<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Child probe for tests/MailTimeoutTest.php.
 *
 * Runs ONE real Messenger::send() against one real socket and reports how long
 * it took. It runs in its own process ON PURPOSE: the defect under test is a
 * wait nothing could shorten, so an in-process assertion would hold the whole
 * suite for the full default instead of failing it. The parent caps the probe
 * and kills it, which turns a regression into a bounded failure.
 *
 * Nothing here is a double -- a real PHP process, the real Messenger, a real
 * socket whose owner never accept()s it, so the kernel completes the TCP
 * handshake from the listen backlog and the greeting never comes.
 *
 * Usage: php mailTimeoutProbe.php <port> [constructor-timeout] [encryption: none|tls|starttls]
 *        TINA4_MAIL_TIMEOUT in the environment is read by the Messenger itself.
 * Output: ELAPSED=<seconds> then OUTCOME=<the send() message, or an exception>
 */

require __DIR__ . '/../../vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$explicit = isset($argv[2]) && $argv[2] !== '' ? (int) $argv[2] : null;
$encryption = isset($argv[3]) && $argv[3] !== '' ? $argv[3] : 'none';

$startedAt = microtime(true);
$report = static function (string $outcome) use ($startedAt): void {
    printf("ELAPSED=%.3f\n", microtime(true) - $startedAt);
    printf("OUTCOME=%s\n", str_replace("\n", ' ', $outcome));
    exit(0);
};

try {
    $arguments = [
        'host' => '127.0.0.1',
        'port' => $port,
        'username' => 'probe@example.com',
        'password' => 'probe',
        'fromAddress' => 'probe@example.com',
        'encryption' => $encryption,
    ];
    if ($explicit !== null) {
        $arguments['timeout'] = $explicit;
    }
    $result = (new \Tina4\Messenger(...$arguments))->send('to@example.com', 'subject', 'body');
    $report('success=' . var_export($result['success'] ?? null, true) . ' ' . (string) ($result['message'] ?? ''));
} catch (\Throwable $e) {
    $report(get_class($e) . ': ' . $e->getMessage());
}
