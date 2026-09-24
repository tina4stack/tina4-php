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

namespace Tina4\Graph;

/**
 * A graph operation failed (a bad statement, an engine error, or a missing
 * driver).
 *
 * The graph analogue of DatabaseException: writes and reads FAIL LOUD (raise on a
 * bad statement) rather than returning a falsy value the caller might miss. The
 * cause is also captured on the adapter's getError().
 */
class GraphError extends \RuntimeException
{
}
