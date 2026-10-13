<?php

declare(strict_types=1);

namespace bapersbw\Webservice;

use bapersbw\Container\ContainerInterface;
use bapersbw\Http\JsonResponse;
use bapersbw\Http\RedirectResponse;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * Base class every module's webservice derives from.
 *
 * It carries the per-request context (the {@see Request} and the container) and
 * offers small helpers for building responses, so handler methods stay focused
 * on behaviour. Routes themselves are declared with #[Route] attributes on the
 * subclass's methods; the loader and router take care of the rest.
 *
 * The kernel calls {@see withContext()} right before invoking a handler.
 */
abstract class Webservice
{
    protected Request $request;

    protected ContainerInterface $container;

    public function withContext(Request $request, ContainerInterface $container): void
    {
        $this->request = $request;
        $this->container = $container;
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    protected function ok(mixed $data): JsonResponse
    {
        return $this->json($data, 200);
    }

    protected function created(mixed $data): JsonResponse
    {
        return $this->json($data, 201);
    }

    protected function noContent(): Response
    {
        return new Response('', 204);
    }

    protected function redirect(string $location, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($location, $status);
    }
}
