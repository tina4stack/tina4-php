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
 * ADR-0044 / DBA-S02: a registered adapter does not satisfy the Tina4
 * database adapter contract. Raised at registration time (see
 * validateAdapter() in DatabaseAdapter.php), naming the adapter and the
 * missing capability, instead of failing later with a bare fatal error on
 * whichever call path happens to touch the gap first.
 */
class AdapterContractException extends \RuntimeException
{
}
