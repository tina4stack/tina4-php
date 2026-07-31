<?php

/**
 * Tina4 v3 test bootstrap.
 * Defines legacy constants before autoloader triggers Initialize.php,
 * then loads the Composer autoloader.
 */

// Legacy log level constants needed by Initialize.php
if (!defined('TINA4_LOG_DEBUG')) {
    define('TINA4_LOG_DEBUG', 'debug');
}
if (!defined('TINA4_LOG_INFO')) {
    define('TINA4_LOG_INFO', 'info');
}
if (!defined('TINA4_LOG_WARNING')) {
    define('TINA4_LOG_WARNING', 'warning');
}
if (!defined('TINA4_LOG_ERROR')) {
    define('TINA4_LOG_ERROR', 'error');
}
if (!defined('TINA4_LOG_CRITICAL')) {
    define('TINA4_LOG_CRITICAL', 'critical');
}

require __DIR__ . '/../vendor/autoload.php';

// Shared test helpers (plain helpers, not mocks). Loaded here so every test
// file can use them without a per-file require — e.g. PgTestEnv resolves the
// live PostgreSQL host/port from TINA4_TEST_POSTGRES_URL, and AppTestSupport
// releases a Tina4\App's global error/exception handlers inside a test boundary.
require __DIR__ . '/PgTestEnv.php';
require __DIR__ . '/AppTestSupport.php';
require __DIR__ . '/FreePort.php';
require __DIR__ . '/TestServer.php';
