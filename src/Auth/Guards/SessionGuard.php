<?php

declare(strict_types=1);

namespace bapersbw\Auth\Guards;

use bapersbw\Auth\Contracts\GuardInterface;
use bapersbw\Auth\Identity;
use bapersbw\Auth\Session\SessionInterface;
use bapersbw\Http\Request;

/**
 * The outward guard: an identity established by SAML/LDAP login and persisted
 * in the session. This is what the {@see \bapersbw\Modules\Login\LoginWebservice}
 * writes to on success and reads from for "who am I".
 */
final class SessionGuard implements GuardInterface
{
    private const KEY = '_auth_identity';

    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    /** Persist a freshly authenticated identity, rotating the session id. */
    public function login(Identity $identity): void
    {
        $this->session->regenerate();
        $this->session->set(self::KEY, $identity->toArray());
    }

    public function logout(): void
    {
        $this->session->remove(self::KEY);
        $this->session->invalidate();
    }

    public function user(): ?Identity
    {
        $data = $this->session->get(self::KEY);

        return is_array($data) ? Identity::fromArray($data) : null;
    }

    public function check(): bool
    {
        return $this->session->has(self::KEY);
    }

    public function resolve(Request $request): ?Identity
    {
        return $this->user();
    }
}
