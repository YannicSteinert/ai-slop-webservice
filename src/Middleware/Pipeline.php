<?php

declare(strict_types=1);

namespace bapersbw\Middleware;

use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * Composes a list of middleware around a final "core" handler, producing a
 * single callable. Middleware run in the order given; the core runs last.
 */
final class Pipeline
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    /** @param iterable<MiddlewareInterface> $middleware */
    public function __construct(iterable $middleware = [])
    {
        foreach ($middleware as $item) {
            $this->middleware[] = $item;
        }
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * @param callable(Request): Response $core The innermost handler.
     */
    public function handle(Request $request, callable $core): Response
    {
        $next = $core;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn (Request $request): Response => $middleware->process($request, $next);
        }

        return $next($request);
    }
}
