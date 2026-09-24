<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
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
