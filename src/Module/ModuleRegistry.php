<?php

declare(strict_types=1);

namespace bapersbw\Module;

use bapersbw\Webservice\Webservice;
use InvalidArgumentException;

/**
 * Maps the first URL segment (the "module") to the webservice class that owns
 * it, e.g. "users" => UsersWebservice::class.
 *
 * This is the single place new modules are registered (via config/modules.php),
 * which is what keeps the system expandable: add a class, add one line.
 */
final class ModuleRegistry
{
    /** @var array<string, class-string<Webservice>> */
    private array $modules;

    /** @param array<string, class-string<Webservice>> $modules */
    public function __construct(array $modules = [])
    {
        $this->modules = [];
        foreach ($modules as $name => $class) {
            $this->register($name, $class);
        }
    }

    /** @param class-string<Webservice> $webserviceClass */
    public function register(string $name, string $webserviceClass): void
    {
        if (!is_subclass_of($webserviceClass, Webservice::class)) {
            throw new InvalidArgumentException(
                "Module [$name]: [$webserviceClass] must extend " . Webservice::class . '.',
            );
        }

        $this->modules[strtolower($name)] = $webserviceClass;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[strtolower($name)]);
    }

    /** @return class-string<Webservice>|null */
    public function resolve(string $name): ?string
    {
        return $this->modules[strtolower($name)] ?? null;
    }

    /** @return array<string, class-string<Webservice>> */
    public function all(): array
    {
        return $this->modules;
    }
}
