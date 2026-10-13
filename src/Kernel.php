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
        try {
            $match = $this->router->match($request);
            $request = $request->withAttribute('route', $match);

            $pipeline = new Pipeline($this->middleware);

            return $pipeline->handle($request, fn (Request $request): Response => $this->runHandler($match, $request));
        } catch (HttpException $exception) {
            return $this->renderHttpException($exception);
        } catch (Throwable $exception) {
            return $this->renderThrowable($exception);
        }
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

    private function renderHttpException(HttpException $exception): Response
    {
        $message = $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Error';

        $response = new JsonResponse(
            ['error' => $message, 'status' => $exception->getStatusCode()],
            $exception->getStatusCode(),
        );

        foreach ($exception->getHeaders() as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }

    private function renderThrowable(Throwable $exception): Response
    {
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
