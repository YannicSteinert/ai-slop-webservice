<?php

declare(strict_types=1);

use bapersbw\Application;
use bapersbw\Auth\Session;
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

const OAUTH_TOKENS_DDL = 'CREATE TABLE oauth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)';

const OAUTH_CLIENTS_DDL = 'CREATE TABLE oauth_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL, secret_hash TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)';

function oauth_connection(): Connection
{
    $db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->execute(OAUTH_TOKENS_DDL);
    $db->execute(OAUTH_CLIENTS_DDL);

    return $db;
}

function oauth_app(): array
{
    $config = require dirname(__DIR__) . '/config/config.php';
    $config['database'] = ['driver' => 'sqlite', 'database' => ':memory:'];
    $modules = require dirname(__DIR__) . '/config/modules.php';

    $app = new Application($config, $modules, new Session(native: false), new NullLogger());
    $db = $app->container->get(Connection::class);
    $db->execute(OAUTH_TOKENS_DDL);
    $db->execute(OAUTH_CLIENTS_DDL);

    // Register a client to exchange credentials with.
    $client = (new TokenStore($db))->createClient('Test Client');

    return [$app, $client];
}

test('createClient registers a client whose credentials then issue a token', function (): void {
    $db = oauth_connection();
    $store = new TokenStore($db, 900);

    $client = $store->createClient('Onboarding');
    assert_true($client['client_id'] !== '' && $client['client_secret'] !== '');

    $token = $store->issue($client['client_id'], $client['client_secret']);
    assert_true($token !== null, 'valid client should get a token');
    assert_same('Bearer', $token['token_type']);

    $identity = $store->validate($token['access_token']);
    assert_true($identity !== null && $identity->id === $client['client_id']);
    assert_same('client', $identity->type);
});

test('token store rejects bad client credentials', function (): void {
    $store = new TokenStore(oauth_connection(), 900);
    $client = $store->createClient('Svc');

    assert_same(null, $store->issue($client['client_id'], 'wrong-secret'));
    assert_same(null, $store->issue('no-such-client', $client['client_secret']));
});

test('expired tokens do not validate and can be purged', function (): void {
    $db = oauth_connection();
    $store = new TokenStore($db, -1); // already expired on issue
    $client = $store->createClient('Svc');

    $token = $store->issue($client['client_id'], $client['client_secret']);
    assert_same(null, $store->validate($token['access_token']), 'expired token must not authenticate');

    assert_same(1, (int) $db->selectOne('SELECT COUNT(*) AS c FROM oauth_tokens')['c'], 'row was stored');
    assert_same(1, $store->purgeExpired(), 'purge removes the expired row');
    assert_same(0, (int) $db->selectOne('SELECT COUNT(*) AS c FROM oauth_tokens')['c'], 'row was purged');
});

test('POST /oauth/token issues a token usable as a bearer', function (): void {
    [$app, $client] = oauth_app();

    $response = $app->handle(new Request('POST', '/oauth/token', [], [
        'grant_type' => 'client_credentials',
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
    ]));
    assert_same(200, $response->getStatus(), $response->getBody());

    $token = json_decode($response->getBody(), true)['access_token'] ?? '';
    assert_true($token !== '', 'response should carry an access_token');

    $whoami = $app->handle(new Request('GET', '/login/whoami', headers: ['authorization' => 'Bearer ' . $token]));
    assert_same(200, $whoami->getStatus(), $whoami->getBody());
    assert_true(str_contains($whoami->getBody(), $client['client_id']), 'bearer token identifies the client');
});

test('POST /oauth/token rejects a bad client with 401 invalid_client', function (): void {
    [$app] = oauth_app();

    $response = $app->handle(new Request('POST', '/oauth/token', [], [
        'grant_type' => 'client_credentials',
        'client_id' => 'nope',
        'client_secret' => 'nope',
    ]));

    assert_same(401, $response->getStatus());
    assert_true(str_contains($response->getBody(), 'invalid_client'));
});

test('POST /oauth/token rejects an unsupported grant type with 400', function (): void {
    [$app] = oauth_app();

    $response = $app->handle(new Request('POST', '/oauth/token', [], ['grant_type' => 'password']));

    assert_same(400, $response->getStatus());
    assert_true(str_contains($response->getBody(), 'unsupported_grant_type'));
});
