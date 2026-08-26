<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A REAL SMTP listener that records the client's side of the conversation.
 *
 * NO MOCKS: Messenger opens a real TCP socket to this and speaks real SMTP.
 * The transcript this writes is the evidence — what the client actually put on
 * the wire, not what a double was told to report.
 *
 * It exists because the two things MessengerSmtpNegotiationTest proves are
 * decisions the client makes BEFORE the message is offered: whether to send
 * AUTH LOGIN, and whether to send STARTTLS. Neither shows up in send()'s
 * return value — the old code sent AUTH LOGIN with two empty strings and the
 * only symptom was a 5xx from the far end. You have to look at the wire.
 *
 * The advertised capabilities are chosen per test, because that is the input
 * that should drive the client's behaviour now that the port number no longer
 * does.
 *
 * STARTTLS is answered 220 and then the connection is dropped rather than
 * genuinely upgraded. Generating a certificate here would prove PHP can do a
 * TLS handshake, which was never in doubt; the question is whether the client
 * ISSUES the command, and by the time we answer it that is already recorded.
 *
 * Usage:
 *   php smtp_negotiation_server.php <port> <transcript-file> [CAP,CAP,...]
 *
 * Capabilities default to "AUTH". Pass "STARTTLS,AUTH" to offer both, or
 * "NONE" to advertise nothing beyond the bare minimum.
 */

declare(strict_types=1);

$port       = (int)($argv[1] ?? 0);
$transcript = (string)($argv[2] ?? '');
$capsArg    = (string)($argv[3] ?? 'AUTH');

if ($port <= 0 || $transcript === '') {
    fwrite(STDERR, "usage: smtp_negotiation_server.php <port> <transcript-file> [CAPS]\n");
    exit(2);
}

$caps = $capsArg === 'NONE' ? [] : array_filter(array_map('trim', explode(',', $capsArg)));

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "could not listen on {$port}: {$errstr} ({$errno})\n");
    exit(1);
}

/** Append one line of evidence. Opened per write so a failing test can read it mid-run. */
$record = static function (string $line) use ($transcript): void {
    file_put_contents($transcript, rtrim($line, "\r\n") . "\n", FILE_APPEND | LOCK_EX);
};

$reply = static function ($conn, string $line): void {
    fwrite($conn, $line . "\r\n");
};

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }

    // TestServer's readiness probe connects and closes without reading. That
    // arrives here as an immediate EOF, which is not a client and must not be
    // recorded as one.
    $reply($conn, '220 tina4-test ESMTP');

    $inData = false;

    while (($line = fgets($conn, 4096)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($inData) {
            if ($line === '.') {
                $inData = false;
                $record('DATA-END');
                $reply($conn, '250 2.0.0 Ok: queued');
            }
            // Message body is deliberately not recorded; this test is about
            // the negotiation, and a transcript holding the whole message
            // would make the assertions harder to read, not easier.
            continue;
        }

        $record($line);
        $verb = strtoupper(strtok($line, ' ') ?: '');

        switch ($verb) {
            case 'EHLO':
                // Last line uses a space, every earlier line a hyphen. Messenger
                // parses this to decide what the server supports, so the shape
                // has to be right.
                $lines = array_merge(['tina4-test greets you'], $caps);
                $last  = count($lines) - 1;
                foreach ($lines as $i => $cap) {
                    $reply($conn, '250' . ($i === $last ? ' ' : '-') . $cap);
                }
                break;

            case 'HELO':
                $reply($conn, '250 tina4-test');
                break;

            case 'STARTTLS':
                $reply($conn, '220 2.0.0 Ready to start TLS');
                // See the header: the command was the point, and it is recorded.
                break 2;

            case 'AUTH':
                $reply($conn, '334 VXNlcm5hbWU6');
                $user = rtrim((string)fgets($conn, 4096), "\r\n");
                $record('AUTH-USERNAME ' . $user);
                $reply($conn, '334 UGFzc3dvcmQ6');
                $pass = rtrim((string)fgets($conn, 4096), "\r\n");
                $record('AUTH-PASSWORD-LENGTH ' . strlen($pass));
                $reply($conn, '235 2.7.0 Authentication successful');
                break;

            case 'MAIL':
            case 'RCPT':
            case 'RSET':
            case 'NOOP':
                $reply($conn, '250 2.1.0 Ok');
                break;

            case 'DATA':
                $inData = true;
                $reply($conn, '354 End data with <CR><LF>.<CR><LF>');
                break;

            case 'QUIT':
                $reply($conn, '221 2.0.0 Bye');
                break 2;

            default:
                $reply($conn, '500 5.5.2 Command unrecognized');
                break;
        }
    }

    fclose($conn);
}
