<?php

declare(strict_types=1);

use bapersbw\Auth\Identity;

test('LDAP dev login succeeds with valid credentials and starts a session', function (): void {
    $client = client();

    $client->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'secret'])
        ->assertOk()
        ->assertJsonContains([
            'authenticated' => true,
            'identity' => ['id' => 'devuser', 'provider' => 'ldap', 'type' => 'user'],
        ]);

    // Session persists across requests on the same client.
    $client->get('/login/whoami')
        ->assertOk()
        ->assertJsonContains(['identity' => ['id' => 'devuser']]);
});

test('LDAP dev login rejects bad credentials with 401', function (): void {
    client()->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'wrong'])
        ->assertStatus(401);
});

test('whoami is 401 without a session', function (): void {
    client()->get('/login/whoami')->assertStatus(401);
});

test('logout clears the session', function (): void {
    $client = client();
    $client->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'secret'])->assertOk();
    $client->get('/login/logout')->assertOk()->assertJsonContains(['logged_out' => true]);
    $client->get('/login/whoami')->assertStatus(401);
});

test('GET /login begins the default (ldap) provider flow', function (): void {
    $response = client()->get('/login');
    $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    assert_true(str_contains($response->body(), '/login/ldap/callback'), 'login form should post to the ldap callback');
});

test('a disabled provider is rejected', function (): void {
    $config = require dirname(__DIR__) . '/config/config.php';
    $config['auth']['enabled'] = ['ldap']; // saml disabled
    client($config)->get('/login/saml')->assertStatus(404);
});

test('actingAs seeds an identity without the login flow', function (): void {
    client()
        ->actingAs(new Identity('alice', displayName: 'Alice', roles: ['admin']))
        ->get('/login/whoami')
        ->assertOk()
        ->assertJsonContains(['identity' => ['id' => 'alice', 'display_name' => 'Alice']]);
});

test('inward OAuth2 bearer token resolves a client identity', function (): void {
    client()
        ->withToken('dev-token-onboarding')
        ->get('/login/whoami')
        ->assertOk()
        ->assertJsonContains(['identity' => ['id' => 'onboarding-app', 'type' => 'client', 'provider' => 'oauth2']]);
});

test('an invalid bearer token does not authenticate', function (): void {
    client()->withToken('not-a-real-token')->get('/login/whoami')->assertStatus(401);
});
