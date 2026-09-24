<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * The PHP extension (or PDO driver) a database engine needs is not loaded.
 *
 * Raised before any connection is attempted, so it always means "install
 * something", never "the server is down". The message names the extension and
 * the install command. It extends \RuntimeException, so existing catch blocks
 * keep working; code that must tell a missing driver from an unreachable
 * server (the database cache backend's fallback warning) catches this type.
 */
class DatabaseDriverMissing extends \RuntimeException
{
}
