<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * ADR-0044: `connect` is the canonical 3.14 lifecycle name; `open` is the
 * pre-3.14 spelling every PHP adapter already implements (config-injected via
 * the constructor, so it takes no arguments — unlike Python/Ruby/Node's
 * connect(connectionString, ...)). Rather than rename `open` everywhere
 * (every adapter's real connect logic, called from its own constructor), this
 * adds `connect()` as a thin forwarding alias so the DECLARED interface can
 * require the canonical name without moving working code. `open` stays as a
 * temporary deprecated alias per the ADR; removing it is a follow-up.
 */
trait ConnectAliasTrait
{
    public function connect(): void
    {
        $this->open();
    }
}
