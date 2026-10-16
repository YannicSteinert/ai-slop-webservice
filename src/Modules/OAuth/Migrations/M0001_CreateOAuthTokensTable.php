<?php

declare(strict_types=1);

namespace bapersbw\Modules\OAuth\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

/**
 * Creates the oauth_tokens (access token) table (MSSQL) plus a self-purging
 * trigger.
 *
 * Only a SHA-256 hash of each token is stored; `identity` holds the JSON of the
 * principal (a user for authorization-code tokens, the client for
 * client-credentials tokens); `expires_at` is a unix timestamp.
 *
 * The AFTER INSERT trigger deletes expired rows whenever a new token is issued,
 * keeping the table pruned without a scheduler. (The TokenGuard also rejects
 * expired tokens, so cleanup is housekeeping, not a security control.)
 */
final class M0001_CreateOAuthTokensTable implements Migration
{
    public function up(Connection $db): void
    {
        if ($db->driver() === 'sqlite') {
            $db->execute(<<<'SQL'
                CREATE TABLE oauth_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token_hash TEXT NOT NULL UNIQUE,
                    client_id TEXT NOT NULL,
                    principal TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL);

            $db->execute(<<<'SQL'
                CREATE TRIGGER trg_oauth_tokens_purge AFTER INSERT ON oauth_tokens
                BEGIN
                    DELETE FROM oauth_tokens WHERE expires_at < strftime('%s', 'now');
                END
                SQL);

            return;
        }

        // CREATE TRIGGER must be its own batch in MSSQL, so run two statements.
        $db->execute(<<<'SQL'
            CREATE TABLE oauth_tokens (
                id INT IDENTITY(1,1) PRIMARY KEY,
                token_hash CHAR(64) NOT NULL UNIQUE,
                client_id NVARCHAR(100) NOT NULL,
                principal NVARCHAR(MAX) NOT NULL,
                expires_at BIGINT NOT NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);

        $db->execute(<<<'SQL'
            CREATE TRIGGER trg_oauth_tokens_purge ON oauth_tokens AFTER INSERT AS
            BEGIN
                SET NOCOUNT ON;
                DELETE FROM oauth_tokens
                WHERE expires_at < DATEDIFF_BIG(SECOND, '1970-01-01', SYSUTCDATETIME());
            END
            SQL);
    }

    public function down(Connection $db): void
    {
        if ($db->driver() === 'sqlite') {
            $db->execute('DROP TRIGGER IF EXISTS trg_oauth_tokens_purge');
            $db->execute('DROP TABLE IF EXISTS oauth_tokens');

            return;
        }

        $db->execute("IF OBJECT_ID('trg_oauth_tokens_purge', 'TR') IS NOT NULL DROP TRIGGER trg_oauth_tokens_purge");
        $db->execute("IF OBJECT_ID('oauth_tokens', 'U') IS NOT NULL DROP TABLE oauth_tokens");
    }
}
