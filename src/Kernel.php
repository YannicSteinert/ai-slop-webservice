<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Auth\Session;
use bapersbw\Auth\SessionGuard;
use bapersbw\Auth\TokenGuard;
use bapersbw\Container\Container;
use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Logging\SecurityLogger;
use bapersbw\Policy\Gate;
use bapersbw\Routing\RouteMatch;
use bapersbw\Routing\Router;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Handles a request end to end:
 *
 *   1. start the session;
 *   2. resolve the identity (inward token first, then outward session);
 *   3. route to a module + attribute route;
 *   4. enforce the route's #[Authorize] policies;
 *   5. invoke the handler, binding arguments from the path and container;
 *   6. normalise the result and any HttpException into a Response.
 *
 * Steps 1, 2 and 4 used to be separate middleware; they are folded in here to
 * keep the framework small. New cross-cutting behaviour is added by editing
 * this flow.
 */
final class Kernel
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
        private readonly Session $session,
        private readonly SessionGuard $sessionGuard,
        private readonly TokenGuard $tokenGuard,
        private readonly Gate $gate,
        private readonly array $config = [],
        private readonly ?SecurityLogger $securityLogger = null,
    ) {
    }

    private function security(): \Psr\Log\LoggerInterface
    {
        return $this->securityLogger ?? $this->logger;
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $request = $request->withAttribute('request_id', $requestId);
        $startedAt = microtime(true);

        $this->logger->debug('Request received', [
            'request_id' => $requestId,
            'method' => $request->method,
            'path' => $request->path,
        ]);

        $this->session->start();
        $response = $this->process($request);
        $this->session->save();

        $this->logger->info('Request handled', [
            'request_id' => $requestId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->getStatus(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
        ]);

        return $response;
    }

    private function process(Request $request): Response
    {
        // CORS preflight: answer OPTIONS here, before routing. Routes only
        // declare GET/POST, so an OPTIONS would otherwise 405 with no CORS
        // headers and the browser would block the real request.
        if ($request->method === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            return $this->applyCors($request, new Response('', 204));
        }

        try {
            $identity = $this->tokenGuard->resolve($request) ?? $this->sessionGuard->user();
            if ($identity !== null) {
                $request = $request->withAttribute('identity', $identity);
            }

            $match = $this->router->match($request);
            $this->enforcePolicies($match, $request);

            $response = $this->dispatch($match, $request);
        } catch (HttpException $e) {
            $response = $this->renderHttpException($e, $request);
        } catch (Throwable $e) {
            $response = $this->renderThrowable($e, $request);
        }

        return $this->applyCors($request, $response);
    }

    /**
     * Stamp CORS headers onto a response when the request carries an allowed
     * Origin. Preflight (OPTIONS) responses also get the method/header/max-age
     * headers the browser needs to permit the follow-up request.
     */
    private function applyCors(Request $request, Response $response): Response
    {
        $origin = $request->header('origin');
        if ($origin === null) {
            return $response; // same-origin or non-browser client: nothing to do
        }

        $cors = (array) ($this->config['cors'] ?? []);
        $allowed = (array) ($cors['allowed_origins'] ?? []);
        $credentials = (bool) ($cors['allow_credentials'] ?? false);

        $allowOrigin = match (true) {
            in_array('*', $allowed, true) => $credentials ? $origin : '*',
            in_array($origin, $allowed, true) => $origin,
            default => null,
        };

        if ($allowOrigin === null) {
            // Origin not allow-listed: send no CORS headers so the browser blocks it.
            return $response;
        }

        $response->withHeader('Access-Control-Allow-Origin', $allowOrigin);
        $response->withHeader('Vary', 'Origin');
        if ($credentials) {
            $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($request->method === 'OPTIONS') {
            $response->withHeader(
                'Access-Control-Allow-Methods',
                implode(', ', (array) ($cors['allowed_methods'] ?? ['GET', 'POST', 'OPTIONS'])),
            );

            $allowHeaders = ($cors['allowed_headers'] ?? null) !== null
                ? implode(', ', (array) $cors['allowed_headers'])
                : (string) $request->header('access-control-request-headers', '');
            if ($allowHeaders !== '') {
                $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
            }

            $response->withHeader('Access-Control-Max-Age', (string) ($cors['max_age'] ?? 600));
        }

        return $response;
    }

    private function enforcePolicies(RouteMatch $match, Request $request): void
    {
        foreach ($match->policies as $authorize) {
            if (!$this->gate->allows($authorize->policy, $request, $authorize->arguments)) {
                $this->security()->warning('Authorization denied by policy {policy}', [
                    'request_id' => $request->attribute('request_id'),
                    'policy' => $authorize->policy,
                    'path' => $request->path,
                ]);

                throw new HttpException(403, "Denied by policy [{$authorize->policy}].");
            }
        }
    }

    private function dispatch(RouteMatch $match, Request $request): Response
    {
        /** @var Webservice $webservice */
        $webservice = $this->container->get($match->webserviceClass);
        $webservice->withContext($request, $this->container);

        $method = new ReflectionMethod($webservice, $match->handlerMethod);
        $arguments = $this->resolveArguments($method, $match->parameters, $request);

        $result = $method->invokeArgs($webservice, $arguments);

        return match (true) {
            $result instanceof Response => $result,
            $result === null => new Response('', 204),
            default => Response::json($result),
        };
    }

    /**
     * Bind handler arguments by name from the path (cast to the declared scalar
     * type), the Request by type hint, or the container for other classes.
     *
     * @param array<string, string> $parameters
     * @return list<mixed>
     */
    private function resolveArguments(ReflectionMethod $method, array $parameters, Request $request): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $this->cast($parameters[$name], $type);
            } elseif ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[] = $request;
            } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->container->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                $arguments[] = null;
            }
        }

        return $arguments;
    }

    private function cast(string $value, ?ReflectionNamedType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private function renderHttpException(HttpException $e, Request $request): Response
    {
        $status = $e->getStatusCode();
        $message = $e->getMessage() !== '' ? $e->getMessage() : 'Error';

        $level = match (true) {
            $status >= 500 => 'error',
            in_array($status, [401, 403], true) => 'warning',
            default => 'info',
        };
        // Authentication/authorization failures belong in the security log; other
        // 4xx/5xx stay in the application log (5xx also reaches the error log).
        $logger = in_array($status, [401, 403], true) ? $this->security() : $this->logger;
        $logger->log($level, 'Request failed with {status}: {message}', [
            'request_id' => $request->attribute('request_id'),
            'path' => $request->path,
            'status' => $status,
            'message' => $message,
        ]);

        $response = Response::json(['error' => $message, 'status' => $status], $status);
        foreach ($e->getHeaders() as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }

    private function renderThrowable(Throwable $e, Request $request): Response
    {
        $this->logger->error('Unhandled exception: {message}', [
            'request_id' => $request->attribute('request_id'),
            'path' => $request->path,
            'message' => $e->getMessage(),
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $payload = ['error' => 'Internal Server Error', 'status' => 500];
        if ($this->config['debug'] ?? false) {
            $payload['message'] = $e->getMessage();
            $payload['exception'] = $e::class;
        }

        return Response::json($payload, 500);
    }
}
