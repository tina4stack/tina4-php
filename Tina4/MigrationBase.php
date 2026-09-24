<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
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
 *           $db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
 *       }
 *
 *       public function down($db): void
 *       {
 *           $db->execute("DROP TABLE IF EXISTS users");
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
