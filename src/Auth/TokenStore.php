<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Database\Connection;

/**
 * OAuth2 server core (the resource server + token/authorize machinery).
 *
 * Supports three grants:
 *   - client_credentials  — a service authenticates itself (token = client).
 *   - authorization_code  — a client acts for a logged-in user (token = user),
 *                           with PKCE and refresh tokens.
 *   - refresh_token       — rotate a refresh token for a new access token.
 *
 * All tokens/codes are stored only as SHA-256 hashes; `expires_at` is a unix
 * timestamp. Clients live in `oauth_clients`; the schema is owned by the OAuth
 * module. The HTTP endpoints (OAuthWebservice) are thin wrappers over this.
 */
final class TokenStore
{
    private const ACCESS = 'oauth_tokens';
    private const CLIENTS = 'oauth_clients';
    private const CODES = 'oauth_auth_codes';
    private const REFRESH = 'oauth_refresh_tokens';

    public function __construct(
        private readonly Connection $db,
        private readonly int $ttl = 900,          // access token lifetime
        private readonly int $codeTtl = 300,       // auth code lifetime
        private readonly int $refreshTtl = 1209600, // refresh token lifetime (14d)
    ) {
    }

    // ---- Clients -----------------------------------------------------------

    /**
     * Register a client. Returns the credentials (secret shown once).
     *
     * @param list<string> $redirectUris allowed redirect URIs (for auth-code)
     * @return array{client_id: string, client_secret: string}
     */
    public function createClient(string $name, array $redirectUris = []): array
    {
        $clientId = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));

        $this->db->execute(
            'INSERT INTO ' . self::CLIENTS . ' (client_id, name, secret_hash, redirect_uris) VALUES (?, ?, ?, ?)',
            [$clientId, $name, password_hash($secret, PASSWORD_DEFAULT), json_encode(array_values($redirectUris))],
        );

        return ['client_id' => $clientId, 'client_secret' => $secret];
    }

    /**
     * Register a PUBLIC client — one with no secret, for use by browser apps
     * (SPAs) that cannot keep a secret. It is restricted to the PKCE-protected
     * authorization_code + refresh_token grants (never client_credentials).
     * Marked by an empty secret_hash, which can never match a real hash.
     *
     * @param list<string> $redirectUris
     * @return array{client_id: string}
     */
    public function createPublicClient(string $name, array $redirectUris = []): array
    {
        $clientId = bin2hex(random_bytes(16));

        $this->db->execute(
            'INSERT INTO ' . self::CLIENTS . ' (client_id, name, secret_hash, redirect_uris) VALUES (?, ?, ?, ?)',
            [$clientId, $name, '', json_encode(array_values($redirectUris))],
        );

        return ['client_id' => $clientId];
    }

    public function authenticateClient(string $clientId, string $secret): bool
    {
        $row = $this->db->selectOne(
            'SELECT secret_hash FROM ' . self::CLIENTS . ' WHERE client_id = ?',
            [$clientId],
        );

        // An empty stored hash marks a public client — it has no secret to verify.
        return $row !== null
            && (string) $row['secret_hash'] !== ''
            && password_verify($secret, (string) $row['secret_hash']);
    }

    /** A public client has no secret (PKCE-only). */
    public function isPublicClient(string $clientId): bool
    {
        $row = $this->db->selectOne(
            'SELECT secret_hash FROM ' . self::CLIENTS . ' WHERE client_id = ?',
            [$clientId],
        );

        return $row !== null && (string) $row['secret_hash'] === '';
    }

    public function clientExists(string $clientId): bool
    {
        return $this->db->selectOne('SELECT 1 AS x FROM ' . self::CLIENTS . ' WHERE client_id = ?', [$clientId]) !== null;
    }

    public function redirectUriAllowed(string $clientId, string $redirectUri): bool
    {
        $row = $this->db->selectOne('SELECT redirect_uris FROM ' . self::CLIENTS . ' WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            return false;
        }

        $allowed = json_decode((string) ($row['redirect_uris'] ?? '[]'), true);

        return is_array($allowed) && in_array($redirectUri, $allowed, true);
    }

    // ---- Authorization codes (authorize endpoint) --------------------------

    /** Create a single-use authorization code and return the raw value. */
    public function createAuthCode(
        string $clientId,
        string $redirectUri,
        Identity $user,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
    ): string {
        $raw = bin2hex(random_bytes(32));

        $this->db->execute(
            'INSERT INTO ' . self::CODES . ' (code_hash, client_id, redirect_uri, principal, code_challenge, code_challenge_method, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $this->hash($raw),
                $clientId,
                $redirectUri,
                json_encode($user->toArray()),
                $codeChallenge,
                $codeChallenge !== null ? ($codeChallengeMethod ?: 'plain') : null,
                time() + $this->codeTtl,
            ],
        );

        return $raw;
    }

    // ---- Token endpoint grants ---------------------------------------------

    /** client_credentials: a token identifying the client itself (no refresh). */
    public function issueClientToken(string $clientId): array
    {
        $identity = new Identity(id: $clientId, type: Identity::TYPE_CLIENT, provider: 'oauth2');

        return $this->issueTokens($clientId, $identity, withRefresh: false);
    }

    /**
     * authorization_code: validate + consume the code (single use), verifying
     * the client, redirect_uri and PKCE, then issue user tokens.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}|null
     */
    public function redeemAuthCode(string $code, string $clientId, string $redirectUri, ?string $codeVerifier): ?array
    {
        $row = $this->db->selectOne(
            'SELECT client_id, redirect_uri, principal, code_challenge, code_challenge_method, expires_at
             FROM ' . self::CODES . ' WHERE code_hash = ?',
            [$this->hash($code)],
        );
        if ($row === null) {
            return null;
        }

        // Consume immediately — a code is single-use even on a failed attempt.
        $this->db->execute('DELETE FROM ' . self::CODES . ' WHERE code_hash = ?', [$this->hash($code)]);

        if ((int) $row['expires_at'] <= time()
            || !hash_equals((string) $row['client_id'], $clientId)
            || !hash_equals((string) $row['redirect_uri'], $redirectUri)
            || !$this->verifyPkce($row['code_challenge'], $row['code_challenge_method'], $codeVerifier)
        ) {
            return null;
        }

        $data = json_decode((string) $row['principal'], true);

        return is_array($data) ? $this->issueTokens($clientId, Identity::fromArray($data), withRefresh: true) : null;
    }

    /**
     * refresh_token: rotate the refresh token (single use) for a fresh pair.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}|null
     */
    public function refresh(string $refreshToken, string $clientId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT client_id, principal, expires_at FROM ' . self::REFRESH . ' WHERE token_hash = ?',
            [$this->hash($refreshToken)],
        );
        if ($row === null) {
            return null;
        }

        // Rotate: the presented refresh token is now spent.
        $this->db->execute('DELETE FROM ' . self::REFRESH . ' WHERE token_hash = ?', [$this->hash($refreshToken)]);

        if ((int) $row['expires_at'] <= time() || !hash_equals((string) $row['client_id'], $clientId)) {
            return null;
        }

        $data = json_decode((string) $row['principal'], true);

        return is_array($data) ? $this->issueTokens($clientId, Identity::fromArray($data), withRefresh: true) : null;
    }

    // ---- Resource server ---------------------------------------------------

    /** Resolve a raw access token to its identity (user or client), or null. */
    public function validate(string $rawToken): ?Identity
    {
        $row = $this->db->selectOne(
            'SELECT principal FROM ' . self::ACCESS . ' WHERE token_hash = ? AND expires_at > ?',
            [$this->hash($rawToken), time()],
        );
        if ($row === null) {
            return null;
        }

        $data = json_decode((string) $row['principal'], true);

        return is_array($data) ? Identity::fromArray($data) : null;
    }

    // ---- Housekeeping ------------------------------------------------------

    /** Delete expired access tokens, auth codes and refresh tokens. */
    public function purgeExpired(): int
    {
        $now = time();

        return $this->db->execute('DELETE FROM ' . self::ACCESS . ' WHERE expires_at < ?', [$now])
            + $this->db->execute('DELETE FROM ' . self::CODES . ' WHERE expires_at < ?', [$now])
            + $this->db->execute('DELETE FROM ' . self::REFRESH . ' WHERE expires_at < ?', [$now]);
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token?: string}
     */
    private function issueTokens(string $clientId, Identity $identity, bool $withRefresh): array
    {
        $access = bin2hex(random_bytes(32));
        $this->db->execute(
            'INSERT INTO ' . self::ACCESS . ' (token_hash, client_id, principal, expires_at) VALUES (?, ?, ?, ?)',
            [$this->hash($access), $clientId, json_encode($identity->toArray()), time() + $this->ttl],
        );

        $result = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $this->ttl];

        if ($withRefresh) {
            $refresh = bin2hex(random_bytes(32));
            $this->db->execute(
                'INSERT INTO ' . self::REFRESH . ' (token_hash, client_id, principal, expires_at) VALUES (?, ?, ?, ?)',
                [$this->hash($refresh), $clientId, json_encode($identity->toArray()), time() + $this->refreshTtl],
            );
            $result['refresh_token'] = $refresh;
        }

        return $result;
    }

    private function verifyPkce(?string $challenge, ?string $method, ?string $verifier): bool
    {
        if ($challenge === null || $challenge === '') {
            return true; // no PKCE was requested at authorize time
        }
        if ($verifier === null || $verifier === '') {
            return false;
        }

        if (($method ?? 'plain') === 'S256') {
            $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            return hash_equals($challenge, $computed);
        }

        return hash_equals($challenge, $verifier);
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
