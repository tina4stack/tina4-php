<?php

/**
 * A raw-socket fixture server for the Api streaming contract (ADR-0060).
 *
 * php -S buffers responses aggressively - each request runs to completion
 * before bytes hit the wire - which defeats real chunk-by-chunk streaming
 * tests. This standalone script owns its own listener via
 * stream_socket_server so it can flush per byte, chunk on demand, close a
 * connection mid-body, or stall forever. It is spawned via
 * TestServer::startScript(). No mocks: every route sends real bytes over
 * a real socket.
 */

$port = (int)($argv[1] ?? 0);
if ($port === 0) {
    fwrite(STDERR, "usage: api_stream_contract_server.php <port>\n");
    exit(1);
}

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed on port {$port}: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, true);

// Reap dead forked children so they never linger as zombies. pcntl_signal
// might not be available on macOS PHP builds without ext-pcntl, in which
// case we fall back to serial single-connection handling.
$canFork = function_exists('pcntl_fork') && function_exists('pcntl_signal') && function_exists('pcntl_waitpid');
if ($canFork) {
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
    // Reap children as they finish so nothing lingers as a zombie.
    pcntl_signal(SIGCHLD, static function (): void {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // reap
        }
    });
    // Ignore SIGPIPE so a write to a closed peer just fails fwrite() instead
    // of terminating the child - our sendChunked checks the write result and
    // bails out of its loop, which is what makes /never-ending exit promptly
    // after the client breaks the iterator.
    pcntl_signal(SIGPIPE, SIG_IGN);
}

while (true) {
    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        if ($canFork) {
            pcntl_signal_dispatch();
        }
        continue;
    }
    stream_set_timeout($client, 60);

    // Fork so a slow handler (e.g. /slow-body's 3s sleep) never blocks the
    // NEXT connection - which is what a real server does, and what
    // ApiStreamContractTest depends on when it hits /slow-body then
    // immediately /never-ending.
    $inChild = false;
    if ($canFork) {
        $pid = pcntl_fork();
        if ($pid > 0) {
            // parent
            @fclose($client);
            continue;
        }
        if ($pid < 0) {
            // fork failed - handle inline as a last resort
            $canFork = false;
        } else {
            // We ARE the child. The child must never loop back to accept on
            // the parent's listening socket; any early return path here must
            // exit(0) instead of `continue`, otherwise we fork-bomb.
            $inChild = true;
        }
    }
    $requestLine = fgets($client);
    if ($requestLine === false) {
        @fclose($client);
        if ($inChild) { exit(0); }
        continue;
    }
    $requestLine = trim($requestLine);
    // Read + discard the remaining request headers up to the blank line.
    while (($line = fgets($client)) !== false) {
        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }
    if (!preg_match('#^(GET|POST|PUT|PATCH|DELETE)\s+(\S+)\s+HTTP#', $requestLine, $match)) {
        @fclose($client);
        if ($inChild) { exit(0); }
        continue;
    }
    $path = $match[2];

    $sendPlain = static function ($sock, string $body, string $contentType, int $status = 200): void {
        $reason = $status === 200 ? 'OK' : 'Response';
        $header = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: {$contentType}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($sock, $header . $body);
    };
    $sendChunked = static function ($sock, array $chunks, string $contentType, int $status = 200, int $delayMicros = 5000): void {
        $reason = $status === 200 ? 'OK' : 'Response';
        $header = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: {$contentType}\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: close\r\n\r\n";
        if (@fwrite($sock, $header) === false) {
            return;
        }
        @fflush($sock);
        foreach ($chunks as $chunk) {
            $line = dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
            $written = @fwrite($sock, $line);
            if ($written === false || $written === 0) {
                return;   // peer went away - stop mid-loop
            }
            @fflush($sock);
            if ($delayMicros > 0) {
                usleep($delayMicros);
            }
        }
        @fwrite($sock, "0\r\n\r\n");
    };

    switch ($path) {
        // ── stream-bytes cases ────────────────────────────────────────
        case '/bytes/chunks':
            // Multiple chunks delivered in order (chunked transfer).
            $sendChunked($client, ['hel', 'lo ', 'wor', 'ld!'], 'application/octet-stream');
            break;
        case '/bytes/eof':
            // Content-Length response that ends cleanly on EOF.
            $sendPlain($client, "hello", 'application/octet-stream');
            break;
        case '/bytes/drop':
            // Advertise more bytes than we send, then close - a real mid-stream drop.
            $header = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/octet-stream\r\n"
                . "Content-Length: 100\r\n"
                . "Connection: close\r\n\r\n";
            fwrite($client, $header);
            fwrite($client, 'partial');
            fflush($client);
            // Fall through to close below.
            break;

        // ── stream-lines cases ────────────────────────────────────────
        case '/lines/lf':
            $sendPlain($client, "alpha\nbeta\ngamma\n", 'text/plain');
            break;
        case '/lines/crlf':
            $sendPlain($client, "alpha\r\nbeta\r\ngamma\r\n", 'text/plain');
            break;
        case '/lines/trailing':
            // Final line has no trailing newline; it must still be yielded on EOF.
            $sendPlain($client, "alpha\nbeta\ngamma", 'text/plain');
            break;
        case '/lines/multibyte':
            // The euro sign U+20AC is E2 82 AC in UTF-8. Split it across two
            // TCP chunks so the sequence spans a chunk boundary; the buffer
            // in streamLines must not decode per-chunk and corrupt it.
            $sendChunked($client, ["hi \xE2", "\x82\xACllo\nsecond\n"], 'text/plain', 200, 20000);
            break;

        // ── stream-sse cases ──────────────────────────────────────────
        case '/sse/single':
            $sendPlain($client, "data: hello\n\n", 'text/event-stream');
            break;
        case '/sse/multiline':
            $sendPlain($client, "data: line1\ndata: line2\n\n", 'text/event-stream');
            break;
        case '/sse/named':
            $sendPlain($client, "event: greeting\ndata: hi\n\n", 'text/event-stream');
            break;
        case '/sse/comment':
            $sendPlain($client, ": this is a comment line\ndata: after\n\n", 'text/event-stream');
            break;
        case '/sse/boundary':
            $sendPlain($client, "data: a\n\ndata: b\n\ndata: c\n\n", 'text/event-stream');
            break;
        case '/sse/done':
            $sendPlain($client, "data: hello\n\ndata: [DONE]\n\ndata: unreachable\n\n", 'text/event-stream');
            break;
        case '/sse/retry':
            $sendPlain($client, "retry: 5000\ndata: with-retry\n\n", 'text/event-stream');
            break;

        // ── timeouts + close cases ───────────────────────────────────
        case '/slow-body':
            // 3s wait BEFORE the response headers - streamBytes with a short
            // total_timeout must give up cleanly rather than block forever.
            usleep(3_000_000);
            $sendPlain($client, "late", 'text/plain');
            break;
        case '/never-ending':
            // Long chunked stream so an "early break out of the loop" test
            // can prove the socket closes on iterator disposal. 200 chunks
            // with 40ms delay is 8s max - long enough that the assertion
            // would fail if the client did NOT release the socket, short
            // enough that the fixture child cannot linger.
            $chunks = [];
            for ($i = 0; $i < 200; $i++) {
                $chunks[] = "data: event-{$i}\n\n";
            }
            $sendChunked($client, $chunks, 'text/event-stream', 200, 40000);
            break;

        default:
            $sendPlain($client, "not found", 'text/plain', 404);
    }
    @fclose($client);
    if ($inChild) {
        // We are the forked child - exit so the parent's accept loop keeps
        // running. Without this, the child would loop back to accept on the
        // parent's listening socket and fork-bomb the machine.
        exit(0);
    }
}
