<?php

/**
 * Lock-in: the BUNDLED Swagger UI static assets must honour the swagger gate.
 *
 * Regression this pins down
 * -------------------------
 * The framework ships the Swagger UI as static files under src/public/swagger/
 * (index.html + oauth2-redirect.html). StaticFiles::tryServe resolves static
 * files INDEPENDENTLY of the gated /swagger routes AND applies index resolution
 * ('/swagger' -> 'swagger/index.html'), so before the fix a production server
 * still served the Swagger UI on:
 *
 *     /swagger                       -> 200 (index resolution)
 *     /swagger/                      -> 200 (index resolution)
 *     /swagger/index.html            -> 200 (direct file)
 *     /swagger/oauth2-redirect.html  -> 200 (direct file)
 *
 * even with swagger disabled -- silently bypassing the documented
 * TINA4_SWAGGER_ENABLED / TINA4_DEBUG switch. The tell was /swagger returning
 * 200 (static file) while /swagger/openapi.json (the gated route) 404'd.
 *
 * Real files on disk, the real StaticFiles::tryServe, real env vars. No doubles.
 */

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\StaticFiles;

class SwaggerStaticGateTest extends TestCase
{
    /** Framework's own bundled public directory. */
    private string $frameworkPublic;

    /** Request paths that MUST be gated. */
    private const GATED_PATHS = [
        '/swagger',
        '/swagger/',
        '/swagger/index.html',
        '/swagger/oauth2-redirect.html',
    ];

    /** A bundled asset that is NOT swagger, to prove the gate is surgical. */
    private const CONTROL_PATH = '/favicon.ico';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->frameworkPublic = dirname(__DIR__) . '/src/public';
        foreach (['TINA4_SWAGGER_ENABLED', 'TINA4_DEBUG'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        \Tina4\DotEnv::resetEnv();
    }

    /**
     * Set env for this example. Writes putenv + $_ENV and clears the DotEnv
     * registry so Swagger::isEnabled() reads exactly what we set.
     */
    private function setEnv(?string $swagger, ?string $debug): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach (['TINA4_SWAGGER_ENABLED' => $swagger, 'TINA4_DEBUG' => $debug] as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * GUARD: without the real files on disk the negative test below could pass
     * vacuously (nothing to serve means nothing to leak) and would keep passing
     * even if the gate were deleted.
     */
    public function testSwaggerAssetsReallyShip(): void
    {
        foreach (['swagger/index.html', 'swagger/oauth2-redirect.html'] as $asset) {
            $this->assertFileExists(
                $this->frameworkPublic . '/' . $asset,
                "{$asset} missing -- the negative test would prove nothing"
            );
        }
        $this->assertFileExists($this->frameworkPublic . '/favicon.ico', 'control asset missing');
    }

    /** NEGATIVE: swagger disabled -> no bundled asset path may serve. */
    public function testStaticSwaggerBlockedWhenSwaggerDisabled(): void
    {
        $this->setEnv('false', 'false');
        foreach (self::GATED_PATHS as $path) {
            $this->assertNull(
                StaticFiles::tryServe($path),
                "{$path} served the bundled Swagger UI with swagger disabled"
            );
        }
    }

    /** POSITIVE: swagger enabled -> the assets must still serve. */
    public function testStaticSwaggerServedWhenSwaggerEnabled(): void
    {
        $this->setEnv('true', 'true');
        foreach (self::GATED_PATHS as $path) {
            $this->assertNotNull(
                StaticFiles::tryServe($path),
                "{$path} did not serve with swagger enabled"
            );
        }
    }

    /** TINA4_SWAGGER_ENABLED unset falls back to TINA4_DEBUG (documented). */
    public function testUnsetFlagFallsBackToDebug(): void
    {
        $this->setEnv(null, 'false');
        $this->assertNull(
            StaticFiles::tryServe('/swagger/index.html'),
            'debug off with the flag unset must gate the assets'
        );

        $this->setEnv(null, 'true');
        $this->assertNotNull(
            StaticFiles::tryServe('/swagger/index.html'),
            'debug on with the flag unset must serve the assets'
        );
    }

    /** Explicit TINA4_SWAGGER_ENABLED beats TINA4_DEBUG in both directions. */
    public function testExplicitFlagBeatsDebug(): void
    {
        $this->setEnv('false', 'true');
        $this->assertNull(
            StaticFiles::tryServe('/swagger/index.html'),
            'explicit false must gate even in debug'
        );

        $this->setEnv('true', 'false');
        $this->assertNotNull(
            StaticFiles::tryServe('/swagger/index.html'),
            'explicit true must serve even with debug off'
        );
    }

    /** The gate must be surgical: ordinary bundled assets still serve. */
    public function testNonSwaggerStaticUnaffected(): void
    {
        $this->setEnv('false', 'false');
        $this->assertNotNull(
            StaticFiles::tryServe(self::CONTROL_PATH),
            'the swagger gate must not block non-swagger static assets'
        );
    }
}
