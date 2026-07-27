<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\App;

/**
 * `php index.php` bootstraps routes and exits 0 without listening. That is
 * correct — index.php is a front controller, not a server launcher — but it
 * reads as a crash, so handle() prints a hint pointing at `tina4 serve`.
 *
 * The hint MUST NOT fire when the framework CLI includes index.php: the CLI
 * emits a machine-read manifest (`tina4php commands --json`), and a stray line
 * would corrupt it. These tests pin both directions.
 */
class CliServeHintTest extends TestCase
{
    public function testHintsWhenIndexPhpIsTheEntryScript(): void
    {
        $this->assertTrue(App::shouldHintCliServe('/app/index.php'));
        $this->assertTrue(App::shouldHintCliServe('index.php'));
    }

    public function testSilentWhenTheFrameworkCliIncludedIndexPhp(): void
    {
        // The entry script is the CLI, not index.php. This is the case that
        // would corrupt `tina4php commands --json` if it ever regressed.
        $this->assertFalse(App::shouldHintCliServe('/app/vendor/bin/tina4php'));
        $this->assertFalse(App::shouldHintCliServe('/app/bin/tina4php'));
        $this->assertFalse(App::shouldHintCliServe('bin/tina4php'));
    }

    public function testSilentWhenTheEntryScriptIsUnknown(): void
    {
        // Silence is the safe default — never guess into a machine-read stream.
        $this->assertFalse(App::shouldHintCliServe(''));
    }

    public function testSilentForAnyOtherIncludingScript(): void
    {
        $this->assertFalse(App::shouldHintCliServe('/app/worker.php'));
        $this->assertFalse(App::shouldHintCliServe('/usr/local/bin/phpunit'));
    }
}
