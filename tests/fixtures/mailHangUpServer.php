<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Child server for tests/MailTimeoutTest.php: an SMTP relay that hangs up.
 *
 * It accepts ONE connection and closes it before greeting, so the client's
 * first read meets end-of-stream at once -- the "lost connection" a silent
 * relay must not be confused with.
 *
 * A real PHP process on a real socket; nothing is mocked. It prints its port
 * and gives up on its own after 40s so an aborted test cannot leave it behind.
 *
 * Usage: php mailHangUpServer.php
 * Output: the bound port on the first line of stdout.
 */

$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$errorString}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$connection = @stream_socket_accept($server, 40);
if ($connection !== false) {
    fclose($connection);
}
