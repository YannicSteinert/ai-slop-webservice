<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Auth\Contracts\GuardInterface;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Middleware\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves the current identity by trying each guard in order and, on the first
 * hit, attaching it to the request as the "identity" attribute. It never
 * rejects the request — enforcement is left to policies (e.g. the
 * "authenticated" policy) so routes decide for themselves whether they need a
 * user, a client, or neither.
 *
 * Guards are tried inward-first (bearer token) then outward (session), so an
 * explicit API token wins over an ambient browser session.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    /** @var list<GuardInterface> */
    private array $guards;

    private readonly LoggerInterface $logger;

    /** @param iterable<GuardInterface> $guards */
    public function __construct(iterable $guards, ?LoggerInterface $logger = null)
    {
        $this->guards = [];
        foreach ($guards as $guard) {
            $this->guards[] = $guard;
        }
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, callable $next): Response
    {
        foreach ($this->guards as $guard) {
            $identity = $guard->resolve($request);
            if ($identity !== null) {
                $this->logger->debug('Identity resolved by {guard}', [
                    'request_id' => $request->attribute('request_id'),
                    'guard' => $guard::class,
                    'subject' => $identity->id,
                    'type' => $identity->type,
                ]);
                $request = $request->withAttribute('identity', $identity);
                break;
            }
        }

        return $next($request);
    }
}
