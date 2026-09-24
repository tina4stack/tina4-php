<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * PHPUnit bootstrap for the Tina4 Store demo.
 *
 * Loads the demo's Composer autoloader (which is symlinked to the Tina4 PHP
 * framework under test), so tests run the REAL app code against the REAL
 * framework — no mocks, no stubs.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';
