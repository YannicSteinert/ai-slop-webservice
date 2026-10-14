<?php

declare(strict_types=1);

namespace bapersbw\Testing;

use bapersbw\Application;
use bapersbw\Auth\Identity;
use bapersbw\Auth\Session;
use bapersbw\Auth\SessionGuard;
use bapersbw\Http\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Boots the application with an in-memory session and drives it like an HTTP
 * client. Each webservice is a self-contained API, so a test can boot with just
 * the modules it needs.
 *
 *   $client = TestClient::create();
 *   $client->get('/users/5/show')->assertOk()->assertJsonContains(['id' => 5]);
 *   $client->actingAs(new Identity('alice', roles: ['admin']))->get('/protected');
 *   $client->withToken('dev-token')->get('/users');
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $defaultHeaders = [];

    public function __construct(
        private readonly Application $app,
        private readonly Session $session,
    ) {
    }

    /**
     * @param array<string, mixed>|null       $config  Defaults to config/config.php.
     * @param array<string, class-string>|null $modules Defaults to config/modules.php.
     */
    public static function create(?array $config = null, ?array $modules = null, ?LoggerInterface $logger = null): self
    {
        $root = dirname(__DIR__, 2);
        $config ??= require $root . '/config/config.php';
        $modules ??= require $root . '/config/modules.php';

        $session = new Session(native: false);

        return new self(new Application($config, $modules, $session, $logger ?? new NullLogger()), $session);
    }

    /** Seed an authenticated session identity, bypassing the login flow. */
    public function actingAs(Identity $identity): self
    {
        $this->session->start();
        $this->app->container->get(SessionGuard::class)->login($identity);

        return $this;
    }

    /** Send a bearer token on subsequent requests (inward/OAuth2 path). */
    public function withToken(string $token): self
    {
        $this->defaultHeaders['authorization'] = 'Bearer ' . $token;

        return $this;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $body = [], array $query = [], array $headers = []): TestResponse
    {
        $request = new Request(
            method: strtoupper($method),
            path: $path,
            query: $query,
            body: $body,
            headers: array_merge($this->defaultHeaders, array_change_key_case($headers)),
        );

        return new TestResponse($this->app->handle($request));
    }

    /** @param array<string, string> $query */
    public function get(string $path, array $query = []): TestResponse
    {
        return $this->request('GET', $path, [], $query);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body = []): TestResponse
    {
        return $this->request('POST', $path, $body);
    }

    /** @param array<string, mixed> $body */
    public function put(string $path, array $body = []): TestResponse
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete(string $path): TestResponse
    {
        return $this->request('DELETE', $path);
    }
}
