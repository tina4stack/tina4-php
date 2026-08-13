<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boots a REAL Tina4 App + Server on the REAL socket event loop
 * (App::run -> Server::start) so DualPortContractTest can make REAL HTTP
 * requests and a REAL raw WebSocket-upgrade handshake against both the main
 * port and the base+1000 "AI port" (feature 128).
 *
 * NO MOCKS: a real listening socket on the base port, and — when
 * TINA4_DEBUG=true and TINA4_NO_AI_PORT is not set — a real second listener
 * on base+1000 (Server::start wires this internally, gated on those exact two
 * conditions; nothing here has to reproduce that logic).
 *
 * /health is auto-registered by App::__construct (registerHealthCheck()), so
 * no route file is needed to prove "the AI port serves the same app".
 *
 * Usage:
 *   php dual_port_server.php <port>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$port = (int)($argv[1] ?? 0);

if ($port === 0) {
    fwrite(STDERR, "usage: dual_port_server.php <port>\n");
    exit(2);
}

$app = new \Tina4\App(basePath: sys_get_temp_dir());
$app->run('127.0.0.1', $port);
