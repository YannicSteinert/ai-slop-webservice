<?php

declare(strict_types=1);

use bapersbw\Application;
use bapersbw\Auth\Identity;
use bapersbw\Auth\Session;
use bapersbw\Auth\SessionGuard;
use bapersbw\Auth\TokenStore;
use bapersbw\Database\Connection;
use bapersbw\Http\Request;
use Psr\Log\NullLogger;

// Inward OAuth2 uses the database, so these run only when sqlite is available
// (php -d extension=pdo_sqlite tests/run.php). MSSQL is the production target.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    test('oauth tests (skipped: no pdo_sqlite)', fn () => assert_true(true));

    return;
}

const OAUTH_DDL = [
    'CREATE TABLE oauth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE,
        client_id TEXT NOT NULL, principal TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL, secret_hash TEXT NOT NULL, redirect_uris TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE oauth_auth_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, code_hash TEXT NOT NULL UNIQUE,
        client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, principal TEXT NOT NULL, code_challenge TEXT,
        code_challenge_method TEXT, expires_at INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE,
        client_id TEXT NOT NULL, principal TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
];

function oauth_connection(): Connection
{
    $db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    foreach (OAUTH_DDL as $sql) {
        $db->execute($sql);
    }

    return $db;
}

/** @return array{0: Application, 1: Session, 2: array{client_id:string,client_secret:string}} */
function oauth_app(string $redirectUri = 'https://app.example/callback'): array
{
    $config = require dirname(__DIR__) . '/config/config.php';
    $config['database'] = ['driver' => 'sqlite', 'database' => ':memory:'];
    $modules = require dirname(__DIR__) . '/config/modules.php';

    $session = new Session(native: false);
    $app = new Application($config, $modules, $session, new NullLogger());
    $db = $app->container->get(Connection::class);
    foreach (OAUTH_DDL as $sql) {
        $db->execute($sql);
    }
    $client = (new TokenStore($db))->createClient('Test Client', [$redirectUri]);

    return [$app, $session, $client];
}

function pkce_pair(): array
{
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

function basic_header(array $client): string
{
    return 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret']);
}

/** Drive GET /oauth/authorize (with a logged-in user) and return the issued code. */
function authorize_code(Application $app, Session $session, array $client, string $redirectUri, string $challenge): string
{
    $app->container->get(SessionGuard::class)->login(new Identity('alice', Identity::TYPE_USER, 'Alice'));

    $response = $app->handle(new Request('GET', '/oauth/authorize', query: [
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => $redirectUri,
        'state' => 'xyz',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    assert_same(302, $response->getStatus(), $response->getBody());
    $location = $response->getHeaders()['Location'] ?? '';
    assert_true(str_contains($location, 'state=xyz'), 'state is echoed back');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

    return (string) ($q['code'] ?? '');
}

test('client_credentials issues a token that validates to the client', function (): void {
    $db = oauth_connection();
    $store = new TokenStore($db, 900);
    $client = $store->createClient('Svc', []);

    $token = $store->issueClientToken($client['client_id']);
    $identity = $store->validate($token['access_token']);
    assert_true($identity !== null && $identity->type === 'client' && $identity->id === $client['client_id']);
});

test('authorize without a session redirects to login', function (): void {
    [$app, , $client] = oauth_app();

    $response = $app->handle(new Request('GET', '/oauth/authorize', query: [
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://app.example/callback',
    ]));

    assert_same(302, $response->getStatus());
    assert_true(str_contains($response->getHeaders()['Location'] ?? '', '/login?return_to='), 'should bounce to login');
});

test('authorize rejects an unregistered redirect_uri', function (): void {
    [$app, , $client] = oauth_app();

    $response = $app->handle(new Request('GET', '/oauth/authorize', query: [
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://evil.example/steal',
    ]));

    assert_same(400, $response->getStatus());
});

test('authorization_code + PKCE issues user tokens usable as a bearer', function (): void {
    [$app, $session, $client] = oauth_app();
    $redirectUri = 'https://app.example/callback';
    [$verifier, $challenge] = pkce_pair();

    $code = authorize_code($app, $session, $client, $redirectUri, $challenge);
    assert_true($code !== '', 'authorize should return a code');

    $token = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ], headers: ['authorization' => basic_header($client)]));

    assert_same(200, $token->getStatus(), $token->getBody());
    $data = json_decode($token->getBody(), true);
    assert_true(!empty($data['access_token']) && !empty($data['refresh_token']), 'both tokens returned');

    // The access token represents the USER, not the client.
    $whoami = $app->handle(new Request('GET', '/login/whoami', headers: ['authorization' => 'Bearer ' . $data['access_token']]));
    assert_same(200, $whoami->getStatus(), $whoami->getBody());
    assert_true(str_contains($whoami->getBody(), '"id":"alice"') && str_contains($whoami->getBody(), '"type":"user"'));
});

test('a wrong PKCE verifier is rejected as invalid_grant', function (): void {
    [$app, $session, $client] = oauth_app();
    $redirectUri = 'https://app.example/callback';
    [, $challenge] = pkce_pair();

    $code = authorize_code($app, $session, $client, $redirectUri, $challenge);

    $token = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'code_verifier' => 'the-wrong-verifier',
    ], headers: ['authorization' => basic_header($client)]));

    assert_same(400, $token->getStatus());
    assert_true(str_contains($token->getBody(), 'invalid_grant'));
});

test('refresh_token rotates for a fresh access token', function (): void {
    [$app, $session, $client] = oauth_app();
    $redirectUri = 'https://app.example/callback';
    [$verifier, $challenge] = pkce_pair();

    $code = authorize_code($app, $session, $client, $redirectUri, $challenge);
    $first = json_decode($app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ], headers: ['authorization' => basic_header($client)]))->getBody(), true);

    $refreshed = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'refresh_token',
        'refresh_token' => $first['refresh_token'],
    ], headers: ['authorization' => basic_header($client)]));

    assert_same(200, $refreshed->getStatus(), $refreshed->getBody());
    $data = json_decode($refreshed->getBody(), true);
    assert_true(!empty($data['access_token']) && !empty($data['refresh_token']));

    $whoami = $app->handle(new Request('GET', '/login/whoami', headers: ['authorization' => 'Bearer ' . $data['access_token']]));
    assert_same(200, $whoami->getStatus(), 'refreshed token still identifies the user');

    // The old refresh token is now spent (rotation).
    $reuse = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'refresh_token',
        'refresh_token' => $first['refresh_token'],
    ], headers: ['authorization' => basic_header($client)]));
    assert_same(400, $reuse->getStatus(), 'a rotated refresh token cannot be reused');
});

test('token endpoint rejects a bad client with 401', function (): void {
    [$app] = oauth_app();

    $response = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'client_credentials',
    ], headers: ['authorization' => 'Basic ' . base64_encode('nope:nope')]));

    assert_same(401, $response->getStatus());
    assert_true(str_contains($response->getBody(), 'invalid_client'));
});

test('token endpoint rejects an unsupported grant type with 400', function (): void {
    [$app, , $client] = oauth_app();

    $response = $app->handle(new Request('POST', '/oauth/token', body: [
        'grant_type' => 'password',
    ], headers: ['authorization' => basic_header($client)]));

    assert_same(400, $response->getStatus());
    assert_true(str_contains($response->getBody(), 'unsupported_grant_type'));
});
