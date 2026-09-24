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
