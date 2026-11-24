<?php

/**
 * Route/controller discovery recursively require_once's every .php under src/.
 * The static asset directories (src/public, src/assets) are web-reachable and
 * may hold user-uploaded files, so a planted .php there must NEVER be included
 * (it would execute on every request). \Tina4\tina4IsExcludedRouteFile is the
 * predicate the discovery loop uses to skip those directories, while they stay
 * valid template-include locations.
 *
 * Pure-logic unit test, no dependencies and no doubles.
 */

use PHPUnit\Framework\TestCase;

use function Tina4\tina4IsExcludedRouteFile;

class RouteDiscoveryExclusionTest extends TestCase
{
    private const EXCLUDED = [
        "/app/src/public",
        "/app/src/assets",
    ];

    public function testFilesUnderExcludedDirsAreSkipped(): void
    {
        $this->assertTrue(tina4IsExcludedRouteFile("/app/src/public/uploads/shell.php", self::EXCLUDED));
        $this->assertTrue(tina4IsExcludedRouteFile("/app/src/public/uploads/uploader/evil.php", self::EXCLUDED));
        $this->assertTrue(tina4IsExcludedRouteFile("/app/src/assets/x.php", self::EXCLUDED));
    }

    public function testCodeDirectoryFilesAreNotSkipped(): void
    {
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/routes/admin.php", self::EXCLUDED));
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/orm/Users.php", self::EXCLUDED));
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/app/Content.php", self::EXCLUDED));
    }

    public function testSiblingDirectoryIsNotMatchedByPrefix(): void
    {
        // "src/public" must not accidentally match "src/public-api".
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/public-api/route.php", ["/app/src/public"]));
    }

    public function testEmptyExcludedEntryIsIgnored(): void
    {
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/public/x.php", [""]));
        $this->assertFalse(tina4IsExcludedRouteFile("/app/src/public/x.php", []));
    }
}
