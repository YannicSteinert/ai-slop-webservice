<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Container\Container;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * Base class every module's webservice derives from. Carries the per-request
 * context and offers small response helpers. Routes are declared with #[Route]
 * attributes on the subclass's methods.
 */
abstract class Webservice
{
    protected Request $request;

    protected Container $container;

    public function withContext(Request $request, Container $container): void
    {
        $this->request = $request;
        $this->container = $container;
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function ok(mixed $data): Response
    {
        return Response::json($data, 200);
    }

    protected function created(mixed $data): Response
    {
        return Response::json($data, 201);
    }

    protected function noContent(): Response
    {
        return new Response('', 204);
    }

    protected function redirect(string $location, int $status = 302): Response
    {
        return Response::redirect($location, $status);
    }
}
