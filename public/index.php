<?php

declare(strict_types=1);

use bapersbw\Container\Container;
use bapersbw\Container\ContainerInterface;
use bapersbw\Http\Request;
use bapersbw\Kernel;
use bapersbw\Module\ModuleRegistry;
use bapersbw\Policy\Gate;
use bapersbw\Routing\ArgumentResolver;
use bapersbw\Routing\AttributeRouteLoader;
use bapersbw\Routing\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$modules = require dirname(__DIR__) . '/config/modules.php';

// --- Container wiring -------------------------------------------------------
$container = new Container();
$container->instance(ContainerInterface::class, $container);
$container->instance(Container::class, $container);

$container->singleton(Gate::class, static fn (ContainerInterface $c) => new Gate($c));

// Register policies here as they are implemented, e.g.:
// $container->get(Gate::class)->register('users.view', ViewUsersPolicy::class);

// --- Routing ----------------------------------------------------------------
$registry = new ModuleRegistry($modules);
$router = new Router($registry, new AttributeRouteLoader());
$arguments = new ArgumentResolver($container);

// --- Kernel -----------------------------------------------------------------
$kernel = new Kernel(
    router: $router,
    container: $container,
    arguments: $arguments,
    middleware: $config['middleware'] ?? [],
    config: $config,
);

$kernel->handle(Request::fromGlobals())->send();
