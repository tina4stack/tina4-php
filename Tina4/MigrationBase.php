<?php

declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Base class for programmatic PHP migrations.
 *
 * Subclass this and implement up($db) and down($db).
 *
 * Example migration file (20240101000000_create_users.php):
 *
 *   <?php
 *   use Tina4\MigrationBase;
 *
 *   class CreateUsers extends MigrationBase
 *   {
 *       public function up($db): void
 *       {
 *           $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
 *       }
 *
 *       public function down($db): void
 *       {
 *           $db->exec("DROP TABLE IF EXISTS users");
 *       }
 *   }
 */
abstract class MigrationBase
{
    /**
     * Apply the migration.
     *
     * @param mixed $db The Tina4 database connection.
     */
    abstract public function up($db): void;

    /**
     * Reverse the migration.
     *
     * @param mixed $db The Tina4 database connection.
     */
    abstract public function down($db): void;
}
