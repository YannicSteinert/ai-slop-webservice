<?php

declare(strict_types=1);

namespace bapersbw\Testing;

use bapersbw\Application;
use bapersbw\Auth\Guards\SessionGuard;
use bapersbw\Auth\Identity;
use bapersbw\Auth\Session\ArraySession;
use bapersbw\Http\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Boots the application with an in-memory session and lets tests drive it like
 * an HTTP client. Because each webservice is a self-contained API, a test can
 * spin up a client with only the modules it cares about.
 *
 * Example:
 *   $client = TestClient::create();
 *   $client->get('/users/5/show')->assertOk()->assertJsonContains(['id' => 5]);
 *
 *   // protected routes without going through a full login:
 *   $client->actingAs(new Identity('alice', roles: ['admin']))
 *          ->get('/login/whoami')->assertOk();
 */
final class TestClient
{
    private readonly ArraySession $session;

    public function __construct(
        private readonly Application $app,
        ArraySession $session,
    ) {
        $this->session = $session;
    }

    /**
     * @param array<string, mixed>|null         $config  Defaults to config/config.php.
     * @param array<string, class-string>|null   $modules Defaults to config/modules.php.
     */
    public static function create(?array $config = null, ?array $modules = null, ?LoggerInterface $logger = null): self
    {
        $root = dirname(__DIR__, 2);
        $config ??= require $root . '/config/config.php';
        $modules ??= require $root . '/config/modules.php';

        $session = new ArraySession();

        // Quiet by default so the suite writes no log files; pass a logger to
        // assert on log output.
        return new self(new Application($config, $modules, $session, $logger ?? new NullLogger()), $session);
    }

    /** Seed an authenticated session identity, bypassing the login flow. */
    public function actingAs(Identity $identity): self
    {
        $this->session->start();
        $this->app->container->get(SessionGuard::class)->login($identity);

        return $this;
    }

    /** Send a bearer token on the next request (inward/OAuth2 path). */
    public function withToken(string $token): self
    {
        $this->defaultHeaders['authorization'] = 'Bearer ' . $token;

        return $this;
    }

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $headers = [],
    ): TestResponse {
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
