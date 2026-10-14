<?php

declare(strict_types=1);

namespace bapersbw\Database;

/**
 * A single schema change. Migrations live inside their module
 * (Modules/<Name>/Migrations/) so each module owns its schema, the same way it
 * owns its routes — add a module, it brings its own migrations.
 *
 * Classes are applied in ascending order by class name, so prefix them to sort:
 * e.g. M0001_CreateUsersTable, M0002_AddEmailIndex.
 */
interface Migration
{
    public function up(Connection $db): void;

    public function down(Connection $db): void;
}
