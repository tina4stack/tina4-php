<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Thrown by Database::execute() / adapter execute() when a SQL statement fails.
 *
 * The execute() contract is FAIL LOUD (parity with the Python master and with
 * fetch()/fetchOne() in this framework): a bad statement, constraint violation,
 * dead/aborted connection or missing driver sets last_error / getError() AND
 * raises this exception instead of silently returning false. Callers that want
 * a bool must wrap execute() in try/catch — see ORM::save(), Migration::migrate()
 * and the dev-admin / MCP database tools for the canonical patterns.
 */
class DatabaseException extends \RuntimeException
{
}
