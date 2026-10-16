<?php

declare(strict_types=1);

namespace bapersbw\Modules\Users\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

/**
 * Creates the users table (MSSQL). Owned by the users module and discovered
 * automatically by the migrator.
 */
final class M0001_CreateUsersTable implements Migration
{
    public function up(Connection $db): void
    {
        $db->execute(match ($db->driver()) {
            'sqlite' => <<<'SQL'
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    display_name TEXT NULL,
                    email TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            default => <<<'SQL'
                CREATE TABLE users (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    username NVARCHAR(100) NOT NULL UNIQUE,
                    display_name NVARCHAR(200) NULL,
                    email NVARCHAR(256) NULL,
                    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
                )
                SQL,
        });
    }

    public function down(Connection $db): void
    {
        $db->execute(match ($db->driver()) {
            'sqlite' => 'DROP TABLE IF EXISTS users',
            default => "IF OBJECT_ID('users', 'U') IS NOT NULL DROP TABLE users",
        });
    }
}
