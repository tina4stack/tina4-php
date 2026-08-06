<?php

/**
 * TEMP SANDBOX -- FIRST, before anything can resolve a temp path.
 *
 * MEASURED: converting the 17 tempnam-orphan call sites (see TempPath.php) left
 * the FULL suite still adding 45 entries to /tmp per run, from a long tail of
 * ~15 other prefixes in files nobody had touched -- tina4_rt_test_ (22),
 * tina4_autoserialize_ (14), and on. Converting call sites does not converge;
 * a new test just adds another prefix.
 *
 * So every temp path this run creates is redirected into ONE per-run directory
 * that is removed wholesale at shutdown. Two measured details decide the shape:
 *
 *   - PHP CACHES the temp directory on the FIRST sys_get_temp_dir() call, so
 *     TMPDIR must be set before any code asks. That is why this block is above
 *     the constants and the autoloader, and why the base is read with getenv()
 *     rather than sys_get_temp_dir() -- calling that here would cache the OLD
 *     value and defeat the whole thing.
 *   - CHILD PROCESSES INHERIT TMPDIR, so the php subprocesses these tests spawn
 *     land in the sandbox too, without knowing it exists.
 *
 * Verified: putenv('TMPDIR=/tmp/probe-php') then tempnam(sys_get_temp_dir(),'x')
 * writes into /tmp/probe-php.
 */
$tina4TestTmpRoot = rtrim(getenv('TMPDIR') ?: '/tmp', '/') . '/tina4-phpunit-' . getmypid();
if (!is_dir($tina4TestTmpRoot)) {
    @mkdir($tina4TestTmpRoot, 0700, true);
}
putenv('TMPDIR=' . $tina4TestTmpRoot);
$_ENV['TMPDIR'] = $tina4TestTmpRoot;
$_SERVER['TMPDIR'] = $tina4TestTmpRoot;

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
// live PostgreSQL host/port from TINA4_TEST_PG_URL, and AppTestSupport
// releases a Tina4\App's global error/exception handlers inside a test boundary.
require __DIR__ . '/PgTestEnv.php';
require __DIR__ . '/AppTestSupport.php';
require __DIR__ . '/FreePort.php';
require __DIR__ . '/TestServer.php';
require __DIR__ . '/PhpChild.php';
require __DIR__ . '/MissingExtensionCase.php';
require __DIR__ . '/TempPath.php';

// Reap every temp path the suite asked TempPath for. A test's own tearDown is
// still the first line of defence; this is the backstop that makes forgetting
// one harmless, and it is what finally empties /tmp on the lab (measured at
// ~2,700 orphaned files and ~840 orphaned directories before it existed).
// THE PID GUARD IS LOAD-BEARING, on both handlers.
//
// tests/DbContractAbcTest.php forks 60 children with pcntl_fork() and ends each
// with exit(0) -- and exit() RUNS THE SHUTDOWN FUNCTIONS. A forked child
// inherits its parent's registered handlers and its copy of $tina4TestTmpRoot,
// so the first child to finish deleted the PARENT'S sandbox, taking with it the
// shared SQLite file the other 59 children and the parent were still using.
// Measured as "SQLite3: Failed to open database: unable to open database file"
// in three DbContractAbc cases, plus a TempPath failure to make a temp file at
// all, none of which reproduce outside a full run.
//
// Only the process that CREATED the sandbox may remove it.
$tina4TestOwnerPid = getmypid();

register_shutdown_function(static function () use ($tina4TestOwnerPid): void {
    if (getmypid() !== $tina4TestOwnerPid) {
        return;                     // a forked child: not ours to reap
    }
    \TempPath::reap();
});

// Then the sandbox itself, wholesale. Registered SECOND on purpose: PHP runs
// shutdown functions in registration order, so TempPath reaps what it tracked
// first and this sweeps everything else the run left behind -- the long tail of
// prefixes no call site was ever converted for. Never throws.
register_shutdown_function(static function () use ($tina4TestTmpRoot, $tina4TestOwnerPid): void {
    if (getmypid() !== $tina4TestOwnerPid) {
        return;                     // a forked child: not ours to reap
    }
    if (!is_dir($tina4TestTmpRoot)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($tina4TestTmpRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tina4TestTmpRoot);
});
