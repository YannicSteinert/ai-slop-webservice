<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Database\Connection;

/**
 * Inward OAuth2 (client-credentials) token mechanism.
 *
 * Tokens are **short-lived** and stored in the `oauth_tokens` table — only a
 * SHA-256 hash of each token is persisted, never the raw value. The raw token
 * is handed to the client once, at issue time.
 *
 * Clients are rows in the `oauth_clients` table (create them with
 * `php bin/console oauth:client:create`); there are no roles or scopes — a valid
 * token simply identifies the calling service.
 *
 * The schema (and a self-purging trigger) is owned by the OAuth module
 * ({@see \bapersbw\Modules\OAuth}); this class is the mechanism the resource
 * server (the {@see TokenGuard}) and the token endpoint share.
 */
final class TokenStore
{
    private const TABLE = 'oauth_tokens';

    private const CLIENTS = 'oauth_clients';

    /** @param int $ttl token lifetime in seconds */
    public function __construct(
        private readonly Connection $db,
        private readonly int $ttl = 900,
    ) {
    }

    /**
     * Register a new OAuth client. Returns the credentials to hand to the
     * client; the secret is shown only here (the DB keeps a hash).
     *
     * @return array{client_id: string, client_secret: string}
     */
    public function createClient(string $name): array
    {
        $clientId = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));

        $this->db->execute(
            'INSERT INTO ' . self::CLIENTS . ' (client_id, name, secret_hash) VALUES (?, ?, ?)',
            [$clientId, $name, password_hash($secret, PASSWORD_DEFAULT)],
        );

        return ['client_id' => $clientId, 'client_secret' => $secret];
    }

    /**
     * Verify client credentials (against the oauth_clients table) and issue a
     * short-lived token.
     *
     * @return array{access_token: string, token_type: string, expires_in: int}|null
     *         null when the client id/secret are invalid.
     */
    public function issue(string $clientId, string $secret): ?array
    {
        $client = $this->db->selectOne(
            'SELECT secret_hash FROM ' . self::CLIENTS . ' WHERE client_id = ?',
            [$clientId],
        );
        if ($client === null || !password_verify($secret, (string) $client['secret_hash'])) {
            return null;
        }

        $raw = bin2hex(random_bytes(32));
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (token_hash, client_id, expires_at) VALUES (?, ?, ?)',
            [$this->hash($raw), $clientId, time() + $this->ttl],
        );

        return [
            'access_token' => $raw,
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
        ];
    }

    /**
     * Resolve a raw bearer token to a client identity, or null if it is unknown
     * or expired. Expiry is enforced here regardless of any cleanup, so a stale
     * token never authenticates.
     */
    public function validate(string $rawToken): ?Identity
    {
        $row = $this->db->selectOne(
            'SELECT client_id FROM ' . self::TABLE . ' WHERE token_hash = ? AND expires_at > ?',
            [$this->hash($rawToken), time()],
        );

        if ($row === null) {
            return null;
        }

        return new Identity(
            id: (string) $row['client_id'],
            type: Identity::TYPE_CLIENT,
            provider: 'oauth2',
        );
    }

    /** Delete expired tokens. Returns the number removed. */
    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at < ?',
            [time()],
        );
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
