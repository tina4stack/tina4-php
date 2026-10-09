<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Child server for tests/MailTimeoutTest.php: an SMTP relay that stalls DURING
 * the STARTTLS handshake.
 *
 * It greets, answers EHLO advertising STARTTLS, answers STARTTLS with "220 go
 * ahead", and then says nothing for ever. The client has done every plain-text
 * step and is now waiting for a TLS ServerHello that never comes -- a wait the
 * silent-greeting black hole never reaches, because there the session never
 * gets as far as TLS.
 *
 * A real PHP process on a real socket; nothing is mocked. It prints its port,
 * serves ONE connection, and gives up on its own after 40s so an aborted test
 * cannot leave it behind.
 *
 * Usage: php mailStarttlsStallServer.php
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

$deadline = time() + 40;
$connection = @stream_socket_accept($server, 40);
if ($connection === false) {
    exit(0);
}
stream_set_timeout($connection, 40);
fwrite($connection, "220 stall.example ESMTP\r\n");
while (time() < $deadline && ($line = fgets($connection)) !== false) {
    $command = strtoupper(trim($line));
    if (str_starts_with($command, 'EHLO') || str_starts_with($command, 'HELO')) {
        fwrite($connection, "250-stall.example\r\n250 STARTTLS\r\n");
    } elseif ($command === 'STARTTLS') {
        fwrite($connection, "220 go ahead\r\n");
        // Silence from here: the client's handshake waits for a ServerHello.
        sleep(max(0, $deadline - time()));
        break;
    }
}
