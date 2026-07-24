<?php

/**
 * Lock-in: port reclaiming never signals a PID it should not.
 *
 * Regression this pins down
 * -------------------------
 * Before starting, the CLI reclaims the port from a stale dev server by reading
 * `lsof -ti` and signalling what it finds. That output was never validated, and
 * PHP casts silently: `(int)"1 /usr/local/bin/php -S 0.0.0.0:7145"` is 1. So a
 * single junk line became PID 1 -- init, which in a container is the server
 * itself. The real image logged
 *
 *     Killed existing process on port 7145 (PID: 1 /usr/local/bin/php ...)
 *
 * and survived only by luck of signal timing. Signalling PID 0 is worse still:
 * it hits EVERY process in the caller's own process group.
 *
 * tina4SelectablePids is pure, so this runs over real inputs with no double.
 *
 * Parity: Python selectable_pids, Ruby selectable_pids, Node selectablePids.
 */
class SelectablePidsTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (function_exists('tina4SelectablePids')) {
            return;
        }
        // Load the function from bin/tina4php without executing the CLI.
        $binPath = __DIR__ . '/../bin/tina4php';
        $source = file_get_contents($binPath);
        if ($source === false) {
            // Loudly, not a skip: a skip here would hide every assertion below.
            self::fail("could not read {$binPath}");
        }
        if (!preg_match('/^function tina4SelectablePids\b.*?^}/ms', $source, $m)) {
            self::fail(
                'tina4SelectablePids could not be extracted from bin/tina4php. '
                . 'If it was renamed or reformatted, update this test -- do NOT '
                . 'let it skip, or the PID safety rule ships unverified.'
            );
        }
        eval($m[0]);
    }

    // ── negative cases: what must NEVER be signalled ────────────────────────

    public function testNonNumericTokenIsNeverCastIntoAPid(): void
    {
        $this->assertSame([], tina4SelectablePids('php', 999));
    }

    public function testTheRealContainerLogLineYieldsNoPid(): void
    {
        // (int) on this whole string used to be 1.
        $this->assertSame([], tina4SelectablePids('1 /usr/local/bin/php -S 0.0.0.0:7145', 999));
    }

    public function testPidZeroIsNeverSignalled(): void
    {
        // posix_kill(0, ...) signals the caller's entire process group.
        $this->assertSame([], tina4SelectablePids('0', 999));
    }

    public function testPidOneIsNeverSignalled(): void
    {
        $this->assertSame([], tina4SelectablePids('1', 999));
    }

    public function testOwnPidIsNeverSignalled(): void
    {
        $this->assertSame([], tina4SelectablePids('4242', 4242));
    }

    public function testOwnProcessGroupIsNeverSignalled(): void
    {
        $this->assertSame([], tina4SelectablePids('777', 4242, 777));
    }

    public function testEmptyAndWhitespaceOutputYieldNothing(): void
    {
        $this->assertSame([], tina4SelectablePids('', 999));
        $this->assertSame([], tina4SelectablePids("   \n\t ", 999));
    }

    // ── positive cases: a real stale sibling still gets reclaimed ───────────

    public function testARealForeignPidIsSelected(): void
    {
        $this->assertSame([5150], tina4SelectablePids('5150', 4242));
    }

    public function testEveryRealForeignPidIsSelected(): void
    {
        $this->assertSame([5150, 5151, 5152], tina4SelectablePids("5150\n5151\n5152", 4242));
    }

    public function testUnsafePidsAreDroppedWhileSafeOnesSurvive(): void
    {
        $this->assertSame(
            [5150, 5151],
            tina4SelectablePids('php 0 1 4242 777 5150 5151', 4242, 777)
        );
    }

    public function testADuplicatePidIsReportedOnce(): void
    {
        $this->assertSame([5150], tina4SelectablePids('5150 5150 5150', 4242));
    }
}
