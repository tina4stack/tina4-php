<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


$port = (int)($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "stall server failed: {$error} ({$errno})\n");
    exit(1);
}

$clients = [];
while (true) {
    $client = @stream_socket_accept($server, 1);
    if ($client !== false) {
        $clients[] = $client;
    }
}
