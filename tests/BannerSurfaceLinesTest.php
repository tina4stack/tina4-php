<?php

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\App;

/**
 * Lock-in: the startup banner only advertises surfaces that are REACHABLE.
 *
 * Regression this pins down (issue #99)
 * -------------------------------------
 * The banner printed
 *
 *     Swagger:   http://localhost:7145/swagger
 *     Dashboard: http://localhost:7145/__dev
 *
 * unconditionally. In production (or with TINA4_DEBUG off) both of those return
 * 404, so the banner (a) told an operator a dev surface was exposed when it was
 * not, and (b) sent a developer to a dead link.
 *
 * PHP had a second failure mode the other three did not: TWO banner sites (the
 * one in App.php and the LIVE one in bin/tina4php). Patching only App.php left
 * the real banner unchanged -- editing a file is not changing behaviour. Both
 * now route through this single pure helper, so they cannot drift apart.
 *
 * Pure function of (port, two booleans): no dependency, no double -- this is
 * not a mock test.
 *
 * Parity: Python banner_surface_lines, Ruby Tina4.banner_surface_lines, Node
 * bannerSurfaceLines.
 */
class BannerSurfaceLinesTest extends TestCase
{
    private const PORT = 7145;

    public function testBothOffEmitsNothing(): void
    {
        [$swaggerLine, $dashboardLine] = App::bannerSurfaceLines(self::PORT, false, false);

        $this->assertSame('', $swaggerLine);
        $this->assertSame('', $dashboardLine);
    }

    public function testBothOffNeverLeaksAPath(): void
    {
        $combined = implode('', App::bannerSurfaceLines(self::PORT, false, false));

        $this->assertStringNotContainsString('/swagger', $combined);
        $this->assertStringNotContainsString('/__dev', $combined);
    }

    public function testSwaggerOnly(): void
    {
        [$swaggerLine, $dashboardLine] = App::bannerSurfaceLines(self::PORT, true, false);

        $this->assertSame("\n  Swagger:   http://localhost:" . self::PORT . '/swagger', $swaggerLine);
        $this->assertSame('', $dashboardLine);
    }

    public function testDevAdminOnly(): void
    {
        [$swaggerLine, $dashboardLine] = App::bannerSurfaceLines(self::PORT, false, true);

        $this->assertSame('', $swaggerLine);
        $this->assertSame("\n  Dashboard: http://localhost:" . self::PORT . '/__dev', $dashboardLine);
    }

    public function testBothOn(): void
    {
        [$swaggerLine, $dashboardLine] = App::bannerSurfaceLines(self::PORT, true, true);

        $this->assertSame("\n  Swagger:   http://localhost:" . self::PORT . '/swagger', $swaggerLine);
        $this->assertSame("\n  Dashboard: http://localhost:" . self::PORT . '/__dev', $dashboardLine);
    }

    public function testPortIsInterpolated(): void
    {
        [$swaggerLine, $dashboardLine] = App::bannerSurfaceLines(9999, true, true);

        $this->assertStringContainsString('9999', $swaggerLine);
        $this->assertStringContainsString('9999', $dashboardLine);
        $this->assertStringNotContainsString((string)self::PORT, $swaggerLine);
    }

    public function testEachLineStartsOnItsOwnRow(): void
    {
        foreach (App::bannerSurfaceLines(self::PORT, true, true) as $line) {
            $this->assertStringStartsWith("\n", $line);
            $this->assertSame(1, substr_count($line, "\n"));
        }
    }

    /**
     * The live CLI banner and App's own banner must render IDENTICAL rows --
     * the drift that made the first fix a no-op.
     */
    public function testLiveCliBannerUsesTheSharedHelper(): void
    {
        $cli = file_get_contents(__DIR__ . '/../bin/tina4php');

        $this->assertIsString($cli);
        $this->assertStringContainsString('bannerSurfaceLines', $cli);
    }
}
