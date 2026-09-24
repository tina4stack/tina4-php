<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boots a REAL Tina4 App through App::run(), the path that prints the startup
 * banner, so BannerRealBindTest can compare the banner with the socket the
 * server really bound.
 *
 * PROBE_HOST / PROBE_PORT, when set, are passed to run() as explicit
 * arguments; otherwise run() resolves TINA4_HOST / TINA4_PORT itself.
 * PROBE_BASE_PATH is the App's base path (a throwaway directory).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$app = new \Tina4\App(basePath: (string) getenv('PROBE_BASE_PATH'));
$host = getenv('PROBE_HOST') ?: null;
$port = getenv('PROBE_PORT') ? (int) getenv('PROBE_PORT') : null;
$app->run($host, $port);
