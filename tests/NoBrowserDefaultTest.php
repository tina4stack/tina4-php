<?php

use PHPUnit\Framework\TestCase;

/**
 * Guard: the test runner must start with TINA4_NO_BROWSER=true.
 *
 * tests/bootstrap.php sets it before any test runs, so every server a test
 * spawns inherits it and no browser tab opens on the machine running the
 * suite. If that default is removed (or the runner is started with it switched
 * off), this fails.
 */
class NoBrowserDefaultTest extends TestCase
{
    public function testTheRunnerStartsWithTheNoBrowserDefault(): void
    {
        $this->assertSame('true', getenv('TINA4_NO_BROWSER'),
            'tests/bootstrap.php must set TINA4_NO_BROWSER=true for the whole run');
    }

    public function testASpawnedChildInheritsTheNoBrowserDefault(): void
    {
        $child = proc_open(
            [PHP_BINARY, '-n', '-r', 'echo getenv("TINA4_NO_BROWSER");'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($child);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($child);

        $this->assertSame('true', $output);
    }
}
