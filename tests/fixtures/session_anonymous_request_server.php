<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * SessionAnonymousRequestTest under Tina4\Server (the CLI worker: the router
 * emits every cookie on the Response itself). The project root is the working
 * directory.
 *
 * Usage: php session_anonymous_request_server.php <port>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$port = (int)($argv[1] ?? 0);
if ($port === 0) {
    fwrite(STDERR, "usage: session_anonymous_request_server.php <port>\n");
    exit(2);
}

require __DIR__ . '/session_anonymous_request_routes.php';

$app = new \Tina4\App(basePath: (string)getcwd());
$app->run('127.0.0.1', $port);
