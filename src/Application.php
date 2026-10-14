<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Auth\AuthManager;
use bapersbw\Auth\Session;
use bapersbw\Auth\SessionGuard;
use bapersbw\Auth\TokenGuard;
use bapersbw\Container\Container;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Logging\LineLogger;
use bapersbw\Policy\AuthenticatedPolicy;
use bapersbw\Policy\Gate;
use bapersbw\Routing\Router;
use Psr\Log\LoggerInterface;

/**
 * Composition root. Wires the container, auth stack, routing and kernel from
 * config + modules so the front controller and the test client boot identically.
 */
final class Application
{
    public readonly Container $container;

    public readonly Kernel $kernel;

    private readonly Gate $gate;

    /**
     * @param array<string, mixed>        $config
     * @param array<string, class-string> $modules
     */
    public function __construct(
        array $config,
        array $modules,
        ?Session $session = null,
        ?LoggerInterface $logger = null,
    ) {
        $container = new Container();
        $this->container = $container;
        $container->instance(Container::class, $container);

        $logger ??= new LineLogger(
            (string) ($config['logging']['path'] ?? 'php://stderr'),
            (string) ($config['logging']['level'] ?? 'debug'),
        );
        $container->instance(LoggerInterface::class, $logger);

        $session ??= new Session(true, (array) ($config['session'] ?? []));
        $sessionGuard = new SessionGuard($session);
        $tokenGuard = new TokenGuard((array) ($config['oauth2']['tokens'] ?? []));

        // Bound so autowired webservices (e.g. LoginWebservice) receive them.
        $container->instance(AuthManager::class, new AuthManager((array) ($config['auth'] ?? [])));
        $container->instance(SessionGuard::class, $sessionGuard);

        $this->gate = new Gate();
        $this->gate->register('authenticated', new AuthenticatedPolicy());

        $this->kernel = new Kernel(
            router: new Router($modules),
            container: $container,
            logger: $logger,
            session: $session,
            sessionGuard: $sessionGuard,
            tokenGuard: $tokenGuard,
            gate: $this->gate,
            config: $config,
        );
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }

    public function gate(): Gate
    {
        return $this->gate;
    }
}
