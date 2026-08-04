<?php

// Global namespace, like PgTestEnv, AppTestSupport, FreePort, TestServer and
// PhpChild: this repo maps PSR-4 Tina4\ -> Tina4/, so a Tina4\Tests\... symbol
// here would never autoload. Required from tests/bootstrap.php.

/**
 * Assert that a class reports a missing PHP extension - by genuinely removing
 * the extension, not by waiting for a host that lacks it.
 *
 * THE PROBLEM THIS SOLVES
 *
 * Four tests in this suite were written as "if the extension IS installed,
 * skip": DatabaseDriversTest (ext-pgsql, ext-mysqli, ext-interbase) and
 * QueueBackendTest (ext-mongodb). The better the machine, the less they tested;
 * on a fully provisioned box all four skipped green, so the error message a
 * developer with a bare PHP actually hits was asserted by nobody. Inverting the
 * gate does not help - it just moves the green skip to the other kind of host.
 *
 * So the absence is CREATED: PhpChild runs a real php subprocess whose
 * PHP_INI_SCAN_DIR points at a copy of this host's conf.d minus the one .ini
 * that loads the extension. No function is patched and nothing is stubbed - the
 * shared object is simply never dlopen'd, so extension_loaded() genuinely
 * answers false and the class under test takes exactly the branch it takes on a
 * machine that never had the extension.
 *
 * Every case asserts the INSTRUMENT before it asserts the behaviour, because a
 * child that quietly kept the extension would make every other assertion
 * vacuous.
 */
trait MissingExtensionCase
{
    /**
     * Run $construction in a child that genuinely lacks $extension, and assert
     * it raised a RuntimeException whose message names $needle.
     *
     * @param string $extension    Extension to remove, e.g. 'pgsql'.
     * @param string $construction PHP statement the child runs, e.g. "new Foo();".
     * @param string $needle       Text the error must contain, e.g. 'ext-pgsql'.
     */
    private function assertConstructionReportsMissingExtension(string $extension, string $construction, string $needle): void
    {
        // Measured by a CHILD moments before the one under test, so both counts
        // come from the same conf.d as it exists now (see PhpChild).
        $baseline = PhpChild::childExtensionBaseline([$extension]);

        $result = PhpChild::runWithoutExtensions(
            [$extension],
            $this->missingExtensionChild([$extension], $construction),
            $this->missingExtensionChildEnvironment()
        );
        $report = $this->missingExtensionReportOrFail($result, $extension);

        // THE INSTRUMENT, FIRST. Everything below is vacuous without it.
        $this->assertExtensionReallyAbsent($report, $extension, $baseline);

        $this->assertTrue(
            $report['threw'],
            "constructing this with ext-{$extension} genuinely absent must raise, so the developer "
            . 'is told what to install instead of hitting an undefined-function fatal'
        );
        $this->assertSame(
            \RuntimeException::class,
            $report['class'],
            'the missing-extension error must be a RuntimeException. Got: ' . (string)$report['class']
        );
        $this->assertStringContainsString(
            $needle,
            (string)$report['message'],
            "the error must name {$needle} so the developer knows what to install. Got: "
            . (string)$report['message']
        );
    }

    /**
     * NEGATIVE CONTROL: with the extension PRESENT, the same construction in the
     * same kind of child must NOT report it missing.
     *
     * "The child threw naming ext-X" is also true of a child that cannot
     * construct anything at all - a broken autoload, a fatal before the check, a
     * child that never really ran. Without this control the positive case would
     * still pass if the subprocess were broken in a way that made every
     * construction fail.
     */
    private function assertConstructionDoesNotReportMissingExtension(string $extension, string $construction, string $needle): void
    {
        // Remove NOTHING: the same machinery, the same child, the extension kept.
        $result = PhpChild::runWithoutExtensions(
            [],
            $this->missingExtensionChild([$extension], $construction),
            $this->missingExtensionChildEnvironment()
        );
        $report = $this->missingExtensionReportOrFail($result, $extension);

        $this->assertTrue(
            $report['instrument']["extension_loaded:{$extension}"],
            "the control child was supposed to KEEP ext-{$extension} and did not, so it proves nothing"
        );
        $this->assertStringNotContainsString(
            $needle,
            (string)$report['message'],
            "with ext-{$extension} present this must never report it missing. It said: "
            . (string)$report['message']
        );
    }

    /**
     * Prove the subprocess really did lose the extension.
     *
     * The count is checked EXACTLY and is derived from a BASELINE CHILD, not
     * from this process - see PhpChild::childExtensionBaseline for why the
     * parent's frozen count is the wrong yardstick.
     *
     * @param array<string, mixed>                          $report
     * @param array{count: int, present: array<string,bool>} $baseline
     */
    private function assertExtensionReallyAbsent(array $report, string $extension, array $baseline): void
    {
        $instrument = $report['instrument'];
        $expected = PhpChild::expectedCountWithout($baseline, [$extension]);

        $this->assertTrue(
            $baseline['present'][$extension],
            "this host does not have ext-{$extension} at all, so removing it proves nothing. "
            . 'Install it - a negative test that needs no filtering is not testing the filter.'
        );
        $this->assertFalse(
            $instrument["extension_loaded:{$extension}"],
            "the subprocess could still load ext-{$extension}, so it measured the PRESENT path "
            . 'while claiming to measure the absent one. Every assertion in this case would be vacuous.'
        );
        $this->assertSame(
            $expected,
            $instrument['loaded_extension_count'],
            'the subprocess holds ' . $instrument['loaded_extension_count'] . ' extensions where exactly '
            . $expected . " were expected (a baseline child measured {$baseline['count']}, minus "
            . "ext-{$extension}). Either the child inherited the full conf.d - in which case every "
            . 'assertion here is vacuous - or the filtered scan dir dropped something it should not have.'
        );
    }

    /**
     * The child program: instrument first, then construct and report exactly
     * what came back.
     *
     * @param string[] $extensions Extensions whose presence the child reports.
     */
    private function missingExtensionChild(array $extensions, string $construction): string
    {
        $instrument = PhpChild::instrumentSource($extensions);
        $report = PhpChild::reportSource('$payload');

        return <<<CHILD
        require getenv('TINA4_AUTOLOAD');
        {$instrument}
        \$payload = ['instrument' => \$instrument, 'threw' => false, 'class' => '', 'message' => ''];
        try {
            {$construction}
        } catch (\\Throwable \$failure) {
            \$payload['threw'] = true;
            \$payload['class'] = get_class(\$failure);
            \$payload['message'] = \$failure->getMessage();
        }
        {$report}
        CHILD;
    }

    /** The child's COMPLETE environment: just enough to autoload the framework. */
    private function missingExtensionChildEnvironment(): array
    {
        return ['TINA4_AUTOLOAD' => dirname(__DIR__) . '/vendor/autoload.php'];
    }

    /**
     * @param array{report: array<string,mixed>|null, stdout: string, stderr: string, exit: int} $result
     * @return array<string, mixed>
     */
    private function missingExtensionReportOrFail(array $result, string $label): array
    {
        if (!is_array($result['report'])) {
            self::fail(
                "the {$label} subprocess reported nothing.\nexit: {$result['exit']}\n"
                . "stdout:\n{$result['stdout']}\nstderr:\n{$result['stderr']}"
            );
        }

        return $result['report'];
    }
}
