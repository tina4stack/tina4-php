<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Front controller for tina4-php#276 — the FULL App boot path under `php -S`.
 *
 * `display_errors` is taken from REPRO_DISPLAY_ERRORS so the test can prove the
 * boot-failure 500 carries no detail under BOTH settings (the bug was a 200 OK
 * with the fatal message + a server-path stack trace when display_errors was
 * on). The app base path is REPRO_BASE — a temp dir the test may seed with a
 * migrations/ folder so the unreachable-database startup path runs.
 *
 * Usage: php -S 127.0.0.1:<port> boot_failure_276_app.php
 */

ini_set('display_errors', getenv('REPRO_DISPLAY_ERRORS') ?: '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../vendor/autoload.php';

$base = getenv('REPRO_BASE');
if ($base === false || $base === '') {
    $base = __DIR__;
}

(new \Tina4\App($base))->handle();
