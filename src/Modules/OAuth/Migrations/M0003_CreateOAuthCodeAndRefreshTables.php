<?php

declare(strict_types=1);

namespace bapersbw\Modules\OAuth\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

/**
 * Tables for the authorization-code flow (MSSQL):
 *
 *   oauth_auth_codes      short-lived, single-use codes from /oauth/authorize,
 *                         carrying the user identity, redirect_uri and PKCE
 *                         challenge.
 *   oauth_refresh_tokens  long-lived, rotated-on-use refresh tokens.
 *
 * Both store only a hash; `identity` is the principal JSON; `expires_at` is a
 * unix timestamp.
 */
final class M0003_CreateOAuthCodeAndRefreshTables implements Migration
{
    public function up(Connection $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE oauth_auth_codes (
                id INT IDENTITY(1,1) PRIMARY KEY,
                code_hash CHAR(64) NOT NULL UNIQUE,
                client_id NVARCHAR(100) NOT NULL,
                redirect_uri NVARCHAR(2048) NOT NULL,
                principal NVARCHAR(MAX) NOT NULL,
                code_challenge NVARCHAR(255) NULL,
                code_challenge_method NVARCHAR(10) NULL,
                expires_at BIGINT NOT NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);

        $db->execute(<<<'SQL'
            CREATE TABLE oauth_refresh_tokens (
                id INT IDENTITY(1,1) PRIMARY KEY,
                token_hash CHAR(64) NOT NULL UNIQUE,
                client_id NVARCHAR(100) NOT NULL,
                principal NVARCHAR(MAX) NOT NULL,
                expires_at BIGINT NOT NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);
    }

    public function down(Connection $db): void
    {
        $db->execute("IF OBJECT_ID('oauth_refresh_tokens', 'U') IS NOT NULL DROP TABLE oauth_refresh_tokens");
        $db->execute("IF OBJECT_ID('oauth_auth_codes', 'U') IS NOT NULL DROP TABLE oauth_auth_codes");
    }
}
