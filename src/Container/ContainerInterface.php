<?php

declare(strict_types=1);

namespace bapersbw\Container;

interface ContainerInterface
{
    /**
     * Resolve an entry by id (usually a class/interface name).
     *
     * @template T
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;
}
