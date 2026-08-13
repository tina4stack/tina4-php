<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * ADR-0044 / DBA-S02: a registered adapter does not satisfy the Tina4
 * database adapter contract. Raised at registration time (see
 * validateAdapter() in DatabaseAdapter.php), naming the adapter and the
 * missing capability, instead of failing later with a bare fatal error on
 * whichever call path happens to touch the gap first.
 */
class AdapterContractException extends \RuntimeException
{
}
