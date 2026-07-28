<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\App;

/**
 * Guard the framework version against the two ways it has actually drifted.
 *
 * 1. App::$VERSION must be a real release version. It used to default to
 *    '0.0.0' and be resolved on boot from composer metadata, and there was
 *    therefore no version file to anchor to. That resolution cannot work in
 *    the base Docker image -- composer.json here has no `version` key
 *    (Packagist derives it from tags) and there is no vendored
 *    tina4stack/tina4php entry in installed.json, because in this repo Tina4
 *    IS the project. Both lookups missed and it fell to the sentinel, so
 *    every published image served "version": "0.0.0" on /health until the CI
 *    boot gate caught it. The version is now DECLARED in App.php, which makes
 *    it the thing these tests can hold.
 *
 * 2. CLAUDE.md must agree with it. That file is what AI assistants read as
 *    ground truth, and a stale footer is the drift that once left the sister
 *    frameworks reporting an old "latest version".
 */
final class VersionConsistencyTest extends TestCase
{
    /**
     * The exact regression. This assertion FAILS against the old code, where
     * $VERSION was declared '0.0.0' and only reassigned if a lookup succeeded.
     */
    public function testVersionIsNotTheBrokenResolverSentinel(): void
    {
        $this->assertNotSame(
            '0.0.0',
            App::$VERSION,
            'App::$VERSION is 0.0.0 -- the sentinel a failed composer-metadata lookup '
            . 'fell back to, which shipped on /health in every Docker image. Declare '
            . 'the real version in App.php.'
        );
        $this->assertNotSame('', App::$VERSION, 'App::$VERSION is empty');
    }

    public function testVersionIsAReleaseVersion(): void
    {
        $this->assertSame(
            1,
            preg_match('/^\d+\.\d+\.\d+/', App::$VERSION),
            'App::$VERSION must look like a release version, got: ' . App::$VERSION
        );
    }

    /**
     * The declared version and the AI-facing doc header get bumped together.
     * This is the anchor the previous version of this test said did not exist.
     */
    public function testClaudeMdHeaderMatchesTheDeclaredVersion(): void
    {
        $claude = file_get_contents(__DIR__ . '/../CLAUDE.md');
        $this->assertNotFalse($claude, 'CLAUDE.md is missing');

        $this->assertSame(
            1,
            preg_match('/^Version (\d+\.\d+\.\d+)\b/m', $claude, $h),
            'CLAUDE.md is missing its "Version X" header'
        );

        $this->assertSame(
            App::$VERSION,
            $h[1],
            'CLAUDE.md header disagrees with App::$VERSION -- bump both with the release'
        );
    }

    public function testClaudeMdVersionIsInternallyConsistent(): void
    {
        $claude = file_get_contents(__DIR__ . '/../CLAUDE.md');
        $this->assertNotFalse($claude, 'CLAUDE.md is missing');

        $this->assertSame(
            1,
            preg_match('/^Version (\d+\.\d+\.\d+)\b/m', $claude, $h),
            'CLAUDE.md is missing its "Version X" header'
        );
        $header = $h[1];

        preg_match_all('/^- Version:\s*(\d+\.\d+\.\d+)/m', $claude, $f);
        foreach ($f[1] as $footer) {
            $this->assertSame(
                $header,
                $footer,
                'CLAUDE.md "- Version:" footer disagrees with the header (bump both with the release)'
            );
        }
    }
}
