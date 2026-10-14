<?php

declare(strict_types=1);

use bapersbw\Routing\Exceptions\RouteNotFoundException;

test('GET /users returns the user list', function (): void {
    client()->get('/users')
        ->assertOk()
        ->assertJsonContains(['users' => [['id' => 1, 'name' => 'Ada Lovelace']]]);
});

test('single path parameter is bound and cast to int', function (): void {
    client()->get('/users/5/show')
        ->assertOk()
        ->assertJsonContains(['id' => 5, 'name' => 'Ada Lovelace']);
});

test('multiple path parameters are bound in order', function (): void {
    client()->get('/users/7/members/42')
        ->assertOk()
        ->assertJsonContains(['group_id' => 7, 'user_id' => 42]);
});

test('POST body is available to the handler', function (): void {
    client()->post('/users', ['name' => 'Grace Hopper'])
        ->assertStatus(201)
        ->assertJsonContains(['name' => 'Grace Hopper']);
});

test('unknown module is a 404', function (): void {
    client()->get('/nope')->assertStatus(404);
});

test('unknown route in a known module is a 404', function (): void {
    client()->get('/users/5/unknown')->assertStatus(404);
});

test('wrong method is a 405', function (): void {
    client()->delete('/users/5/show')->assertStatus(405);
});
