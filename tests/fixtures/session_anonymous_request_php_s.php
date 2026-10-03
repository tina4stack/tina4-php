<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * SessionAnonymousRequestTest under `php -S` (a real SAPI: PHP sends its own
 * headers, as under Apache/FPM). The project root is the working directory, so
 * static files are served from its src/public.
 *
 * Usage: php -S 127.0.0.1:<port> session_anonymous_request_php_s.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/session_anonymous_request_routes.php';

$app = new \Tina4\App(basePath: (string)getcwd());
$app->handle();
