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
    ) {
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
        try {
            $identity = $this->tokenGuard->resolve($request) ?? $this->sessionGuard->user();
            if ($identity !== null) {
                $request = $request->withAttribute('identity', $identity);
            }

            $match = $this->router->match($request);
            $this->enforcePolicies($match, $request);

            return $this->dispatch($match, $request);
        } catch (HttpException $e) {
            return $this->renderHttpException($e, $request);
        } catch (Throwable $e) {
            return $this->renderThrowable($e, $request);
        }
    }

    private function enforcePolicies(RouteMatch $match, Request $request): void
    {
        foreach ($match->policies as $authorize) {
            if (!$this->gate->allows($authorize->policy, $request, $authorize->arguments)) {
                $this->logger->warning('Authorization denied by policy {policy}', [
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
        $this->logger->log($level, 'Request failed with {status}: {message}', [
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
