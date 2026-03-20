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
