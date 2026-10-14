<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Container\ContainerInterface;
use bapersbw\Exceptions\HttpException;
use bapersbw\Http\JsonResponse;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Middleware\MiddlewareInterface;
use bapersbw\Middleware\Pipeline;
use bapersbw\Routing\ArgumentResolver;
use bapersbw\Routing\RouteMatch;
use bapersbw\Routing\Router;
use bapersbw\Webservice\Webservice;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * The application entry point. Given a Request it:
 *
 *   1. routes it to a webservice method (module + attribute route);
 *   2. runs the middleware pipeline (policies, auth, ... plug in here);
 *   3. invokes the handler, binding its arguments from the path and container;
 *   4. normalises the return value and any thrown HttpException into a Response.
 */
final class Kernel
{
    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware Global middleware, in order.
     * @param array<string, mixed>                    $config
     */
    public function __construct(
        private readonly Router $router,
        private readonly ContainerInterface $container,
        private readonly ArgumentResolver $arguments,
        private readonly LoggerInterface $logger,
        array $middleware = [],
        private readonly array $config = [],
    ) {
        $this->middleware = array_map(
            fn (string $class): MiddlewareInterface => $this->container->get($class),
            $middleware,
        );
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

        try {
            $match = $this->router->match($request);
            $request = $request->withAttribute('route', $match);

            $pipeline = new Pipeline($this->middleware);
            $response = $pipeline->handle($request, fn (Request $request): Response => $this->runHandler($match, $request));
        } catch (HttpException $exception) {
            $response = $this->renderHttpException($exception, $request);
        } catch (Throwable $exception) {
            $response = $this->renderThrowable($exception, $request);
        }

        $this->logger->info('Request handled', [
            'request_id' => $requestId,
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->getStatus(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
        ]);

        return $response;
    }

    private function runHandler(RouteMatch $match, Request $request): Response
    {
        /** @var Webservice $webservice */
        $webservice = $this->container->get($match->route->webserviceClass);
        $webservice->withContext($request, $this->container);

        $method = new ReflectionMethod($webservice, $match->route->handlerMethod);
        $arguments = $this->arguments->resolve($method, $match->parameters, $request);

        $result = $method->invokeArgs($webservice, $arguments);

        return $this->toResponse($result);
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null) {
            return new Response('', 204);
        }

        // Anything else is treated as a JSON payload.
        return new JsonResponse($result);
    }

    private function renderHttpException(HttpException $exception, Request $request): Response
    {
        $status = $exception->getStatusCode();
        $message = $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Error';

        // Server errors are real problems; 401/403 are security-relevant; other
        // client errors are routine and logged quietly.
        $level = match (true) {
            $status >= 500 => 'error',
            in_array($status, [401, 403], true) => 'warning',
            default => 'info',
        };

        $this->logger->log($level, 'Request failed with {status}: {message}', [
            'request_id' => $request->attribute('request_id'),
            'method' => $request->method,
            'path' => $request->path,
            'status' => $status,
            'message' => $message,
        ]);

        $response = new JsonResponse(
            ['error' => $message, 'status' => $status],
            $status,
        );

        foreach ($exception->getHeaders() as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }

    private function renderThrowable(Throwable $exception, Request $request): Response
    {
        $this->logger->error('Unhandled exception: {message}', [
            'request_id' => $request->attribute('request_id'),
            'method' => $request->method,
            'path' => $request->path,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $debug = (bool) ($this->config['debug'] ?? false);

        $payload = ['error' => 'Internal Server Error', 'status' => 500];
        if ($debug) {
            $payload['message'] = $exception->getMessage();
            $payload['exception'] = $exception::class;
            $payload['trace'] = explode("\n", $exception->getTraceAsString());
        }

        return new JsonResponse($payload, 500);
    }
}
