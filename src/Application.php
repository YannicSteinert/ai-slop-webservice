<?php

declare(strict_types=1);

namespace bapersbw;

use bapersbw\Auth\AuthManager;
use bapersbw\Auth\Session;
use bapersbw\Auth\SessionGuard;
use bapersbw\Auth\TokenGuard;
use bapersbw\Auth\TokenStore;
use bapersbw\Container\Container;
use bapersbw\Database\Connection;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Logging\LoggerFactory;
use bapersbw\Logging\SecurityLogger;
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

        // Split logging into daily-rotated channels (application / security /
        // error). A logger injected for tests is reused for both channels so no
        // files are written; otherwise each channel gets its own rotating files.
        $logConfig = (array) ($config['logging'] ?? []);
        if ($logger !== null) {
            $securityLogger = new SecurityLogger($logger);
        } else {
            $logger = LoggerFactory::create($logConfig, 'application');
            $securityLogger = new SecurityLogger(LoggerFactory::create($logConfig, 'security'));
        }
        $container->instance(LoggerInterface::class, $logger);
        $container->instance(SecurityLogger::class, $securityLogger);

        // Lazy: the database only connects when a repository first uses it.
        $container->bind(Connection::class, static fn () => new Connection((array) ($config['database'] ?? [])));

        $session ??= new Session(true, (array) ($config['session'] ?? []));
        $sessionGuard = new SessionGuard($session);

        // Inward OAuth2: DB-backed, short-lived tokens. The Connection is lazy,
        // so the token store only hits the database when a bearer token is used.
        $oauth = (array) ($config['oauth2'] ?? []);
        $tokenStore = new TokenStore(
            $container->get(Connection::class),
            (int) ($oauth['ttl'] ?? 900),
            (int) ($oauth['code_ttl'] ?? 300),
            (int) ($oauth['refresh_ttl'] ?? 1209600),
        );
        $tokenGuard = new TokenGuard($tokenStore);

        // Bound so autowired webservices receive them (e.g. the OAuth and Login modules).
        $container->instance(TokenStore::class, $tokenStore);
        $container->instance(AuthManager::class, new AuthManager((array) ($config['auth'] ?? []), $securityLogger));
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
            securityLogger: $securityLogger,
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
