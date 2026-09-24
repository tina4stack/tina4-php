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
