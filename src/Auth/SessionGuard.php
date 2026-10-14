<?php

declare(strict_types=1);

namespace bapersbw\Auth;

/**
 * The outward guard: an identity established by SAML/LDAP login and stored in
 * the session.
 */
final class SessionGuard
{
    private const KEY = '_auth_identity';

    public function __construct(
        private readonly Session $session,
    ) {
    }

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
}
