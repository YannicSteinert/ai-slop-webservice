<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Auth\AuthenticationMiddleware;
use bapersbw\Auth\AuthManager;
use bapersbw\Auth\Contracts\TokenValidatorInterface;
use bapersbw\Auth\Guards\SessionGuard;
use bapersbw\Auth\Guards\TokenGuard;
use bapersbw\Auth\Oauth2\StaticTokenValidator;
use bapersbw\Auth\Session\NativeSession;
use bapersbw\Auth\Session\SessionInterface;
use bapersbw\Auth\Session\SessionMiddleware;
use bapersbw\Container\Container;
use bapersbw\Container\ContainerInterface;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Logging\LineLogger;
use bapersbw\Module\ModuleRegistry;
use bapersbw\Policy\AuthorizationMiddleware;
use bapersbw\Policy\Gate;
use bapersbw\Policy\Policies\AuthenticatedPolicy;
use bapersbw\Routing\ArgumentResolver;
use bapersbw\Routing\AttributeRouteLoader;
use bapersbw\Routing\Router;
use Psr\Log\LoggerInterface;

/**
 * Composition root. One place wires the container, auth stack, routing and
 * kernel from config + modules, so the front controller, the test client and
 * any CLI entry point all boot the application identically.
 */
final class Application
{
    public readonly Container $container;

    public readonly Kernel $kernel;

    /**
     * @param array<string, mixed>                       $config
     * @param array<string, class-string>                $modules
     * @param SessionInterface|null                      $session Override the session store (tests use ArraySession).
     */
    public function __construct(
        private readonly array $config,
        array $modules,
        ?SessionInterface $session = null,
        ?LoggerInterface $logger = null,
    ) {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        $this->registerLogger($container, $logger);
        $this->registerSession($container, $session);
        $this->registerAuth($container);
        $this->registerPolicies($container);

        $registry = new ModuleRegistry($modules);
        $router = new Router($registry, new AttributeRouteLoader());

        $this->container = $container;
        $this->kernel = new Kernel(
            router: $router,
            container: $container,
            arguments: new ArgumentResolver($container),
            logger: $container->get(LoggerInterface::class),
            middleware: $config['middleware'] ?? [],
            config: $config,
        );
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }

    /** Convenience accessor for the authorization gate (e.g. registering policies). */
    public function gate(): Gate
    {
        return $this->container->get(Gate::class);
    }

    private function registerLogger(Container $container, ?LoggerInterface $logger): void
    {
        if ($logger !== null) {
            $container->instance(LoggerInterface::class, $logger);

            return;
        }

        $logging = (array) ($this->config['logging'] ?? []);
        $container->instance(
            LoggerInterface::class,
            new LineLogger(
                path: (string) ($logging['path'] ?? 'php://stderr'),
                minLevel: (string) ($logging['level'] ?? 'debug'),
            ),
        );
    }

    private function registerSession(Container $container, ?SessionInterface $session): void
    {
        $sessionConfig = (array) ($this->config['session'] ?? []);
        $container->instance(
            SessionInterface::class,
            $session ?? new NativeSession($sessionConfig),
        );

        $container->singleton(
            SessionMiddleware::class,
            static fn (ContainerInterface $c) => new SessionMiddleware($c->get(SessionInterface::class)),
        );
    }

    private function registerAuth(Container $container): void
    {
        $authConfig = (array) ($this->config['auth'] ?? []);
        $oauthConfig = (array) ($this->config['oauth2'] ?? []);

        $container->singleton(
            AuthManager::class,
            static fn () => new AuthManager($authConfig),
        );

        $container->singleton(
            SessionGuard::class,
            static fn (ContainerInterface $c) => new SessionGuard($c->get(SessionInterface::class)),
        );

        $container->singleton(
            TokenValidatorInterface::class,
            static fn () => new StaticTokenValidator((array) ($oauthConfig['tokens'] ?? [])),
        );

        $container->singleton(
            TokenGuard::class,
            static fn (ContainerInterface $c) => new TokenGuard($c->get(TokenValidatorInterface::class)),
        );

        // Guards are tried inward-first (token) then outward (session).
        $container->singleton(
            AuthenticationMiddleware::class,
            static fn (ContainerInterface $c) => new AuthenticationMiddleware([
                $c->get(TokenGuard::class),
                $c->get(SessionGuard::class),
            ], $c->get(LoggerInterface::class)),
        );
    }

    private function registerPolicies(Container $container): void
    {
        $container->singleton(Gate::class, static fn (ContainerInterface $c) => new Gate($c));
        $container->singleton(
            AuthorizationMiddleware::class,
            static fn (ContainerInterface $c) => new AuthorizationMiddleware($c->get(Gate::class), $c->get(LoggerInterface::class)),
        );

        $gate = $container->get(Gate::class);
        $gate->register('authenticated', new AuthenticatedPolicy());
    }
}
