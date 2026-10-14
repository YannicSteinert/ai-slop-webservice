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
        $db->execute(<<<'SQL'
            CREATE TABLE users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                username NVARCHAR(100) NOT NULL UNIQUE,
                display_name NVARCHAR(200) NULL,
                email NVARCHAR(256) NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);
    }

    public function down(Connection $db): void
    {
        $db->execute("IF OBJECT_ID('users', 'U') IS NOT NULL DROP TABLE users");
    }
}
