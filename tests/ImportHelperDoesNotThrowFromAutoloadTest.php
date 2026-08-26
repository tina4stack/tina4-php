<?php

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/**
 * PHP autoloaders MUST NOT throw. Throwing from spl_autoload_register
 * breaks class_exists() checks and any snippet that catches "class not
 * found". This test pins the invariant against regression.
 *
 * Regression from 3.13.117: ImportHelper::handle() threw \Error on any
 * unresolved Tina4\* class, which broke class_exists('X', true) for anyone
 * probing an optional external class. Three real tests failed against v3
 * HEAD (GraphTest::testGraphConnectTimeout,
 * SQLTranslatorTest::testPreRenameClassNameNoLongerExists,
 * LazyFeatureLoadingTest::testReferencingAnEagerFilesNameDoesNotFatal). The
 * fix in Tina4/ImportHelper.php replaces both throw sites in handle() with
 * error_log('[Tina4] ...') so the hint is still visible in logs and phpunit
 * output while the autoloader returns silently per PHP's own contract.
 */
final class ImportHelperDoesNotThrowFromAutoloadTest extends TestCase
{
    public function testClassExistsReturnsFalseWithoutFatalForUnknownTina4Class(): void
    {
        // A name that will NEVER be a real class. Composer's PSR-4 returns
        // silently; the ImportHelper computes a suggestion (or the browsable
        // sample) and writes it to error_log; class_exists returns false.
        $exists = class_exists('Tina4\\DefinitelyNotARealClassZzz', true);
        $this->assertFalse($exists, 'class_exists() on an unknown Tina4 class must return false, not fatal');
    }

    public function testClassExistsReturnsTrueForARealTina4Class(): void
    {
        // Ordinary happy path — a real Tina4 class autoloads fine.
        $this->assertTrue(class_exists('Tina4\\Router', true));
    }

    public function testErrorLogEmitsAHintForAnUnknownTina4Class(): void
    {
        // Redirect error_log to a temp file for this test to observe.
        $tmp = tempnam(sys_get_temp_dir(), 'tina4-error-log-');
        $original = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            class_exists('Tina4\\Routr', true); // typo of Router
            $log = file_get_contents($tmp) ?: '';
            $this->assertStringContainsString('[Tina4]', $log);
            $this->assertStringContainsString("'Tina4\\Routr'", $log);
            $this->assertStringContainsString('Router', $log, 'hint should suggest Router');
        } finally {
            ini_set('error_log', $original);
            @unlink($tmp);
        }
    }
}
