<?php

/**
 * PHPUnit bootstrap for the Tina4 Store demo.
 *
 * Loads the demo's Composer autoloader (which is symlinked to the Tina4 PHP
 * framework under test), so tests run the REAL app code against the REAL
 * framework — no mocks, no stubs.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';
