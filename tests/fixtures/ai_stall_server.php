<?php

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
