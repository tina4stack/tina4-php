<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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
