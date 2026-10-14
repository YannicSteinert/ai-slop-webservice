<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use JsonSerializable;

/**
 * An authenticated principal, independent of how it was authenticated.
 *
 * `type` distinguishes the two surfaces of the system:
 *   - "user"   — a human, authenticated outward via SAML or LDAP (session);
 *   - "client" — a calling service, authenticated inward via OAuth2 (token).
 *
 * It is a plain value object so it can be stored in the session (via
 * {@see toArray()}) or returned as JSON.
 */
final class Identity implements JsonSerializable
{
    public const TYPE_USER = 'user';
    public const TYPE_CLIENT = 'client';

    /**
     * @param list<string>         $roles
     * @param array<string, mixed> $attributes Raw provider attributes.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type = self::TYPE_USER,
        public readonly ?string $displayName = null,
        public readonly array $roles = [],
        public readonly array $attributes = [],
        public readonly ?string $provider = null,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** First value of a (possibly multi-valued) attribute. */
    public function attribute(string $name, mixed $default = null): mixed
    {
        $value = $this->attributes[$name] ?? $default;

        return is_array($value) ? ($value[0] ?? $default) : $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'display_name' => $this->displayName,
            'roles' => $this->roles,
            'attributes' => $this->attributes,
            'provider' => $this->provider,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            type: (string) ($data['type'] ?? self::TYPE_USER),
            displayName: $data['display_name'] ?? null,
            roles: $data['roles'] ?? [],
            attributes: $data['attributes'] ?? [],
            provider: $data['provider'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
