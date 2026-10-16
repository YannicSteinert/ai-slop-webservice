<?php

declare(strict_types=1);

namespace bapersbw\Modules\OAuth\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

/**
 * Registered OAuth clients (MSSQL). The client secret is stored only as a
 * password_hash; the plaintext is shown once, at creation time. `redirect_uris`
 * is a JSON array of the URIs allowed for the authorization-code flow.
 *
 * Create clients with:
 *   php bin/console oauth:client:create "<name>" [redirect_uri ...]
 */
final class M0002_CreateOAuthClientsTable implements Migration
{
    public function up(Connection $db): void
    {
        $db->execute(match ($db->driver()) {
            'sqlite' => <<<'SQL'
                CREATE TABLE oauth_clients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    secret_hash TEXT NOT NULL,
                    redirect_uris TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            default => <<<'SQL'
                CREATE TABLE oauth_clients (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    client_id NVARCHAR(100) NOT NULL UNIQUE,
                    name NVARCHAR(200) NOT NULL,
                    secret_hash NVARCHAR(255) NOT NULL,
                    redirect_uris NVARCHAR(MAX) NULL,
                    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
                )
                SQL,
        });
    }

    public function down(Connection $db): void
    {
        $db->execute(match ($db->driver()) {
            'sqlite' => 'DROP TABLE IF EXISTS oauth_clients',
            default => "IF OBJECT_ID('oauth_clients', 'U') IS NOT NULL DROP TABLE oauth_clients",
        });
    }
}
