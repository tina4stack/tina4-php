<?php

/**
 * Tests for port/host resolution in the CLI serve command.
 *
 * Priority order: CLI arg > ENV var > default
 * Default host: 0.0.0.0   Default port: 7145
 */
class PortConfigTest extends \PHPUnit\Framework\TestCase
{
    private static bool $functionLoaded = false;

    public static function setUpBeforeClass(): void
    {
        // Load the resolveHostPort function from bin/tina4php without executing the CLI
        if (!function_exists('resolveHostPort')) {
            // Extract just the function from the CLI script
            $binPath = __DIR__ . '/../bin/tina4php';
            $source = file_get_contents($binPath);

            // Extract the function definition
            if (preg_match('/^function resolveHostPort\b.*?^}/ms', $source, $m)) {
                eval($m[0]);
                self::$functionLoaded = true;
            }
        } else {
            self::$functionLoaded = true;
        }
    }

    protected function setUp(): void
    {
        if (!self::$functionLoaded) {
            $this->markTestSkipped('resolveHostPort function could not be loaded');
        }
        // Clear env vars before each test. TINA4_DEBUG is cleared so the
        // "default host is 0.0.0.0" assertions stay deterministic even on a lab
        // box that exports TINA4_DEBUG (DEVADMIN-DEC-02 made the default
        // dev-aware — 127.0.0.1 in debug, 0.0.0.0 otherwise).
        putenv('PORT');
        putenv('HOST');
        putenv('TINA4_HOST');
        putenv('TINA4_DEBUG');
    }

    protected function tearDown(): void
    {
        putenv('PORT');
        putenv('HOST');
        putenv('TINA4_HOST');
        putenv('TINA4_DEBUG');
    }

    // ── Default values ──

    public function testDefaultPortIs7145(): void
    {
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('7145', $port);
    }

    public function testDefaultHostIs0000(): void
    {
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('0.0.0.0', $host);
    }

    // ── ENV var handling ──

    public function testPortEnvVarIsRespected(): void
    {
        putenv('PORT=9999');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('9999', $port);
        $this->assertSame('0.0.0.0', $host, 'Host should stay default when only PORT is set');
    }

    public function testHostEnvVarIsRespected(): void
    {
        putenv('HOST=192.168.1.100');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('192.168.1.100', $host);
        $this->assertSame('7145', $port, 'Port should stay default when only HOST is set');
    }

    public function testBothEnvVarsRespected(): void
    {
        putenv('HOST=10.0.0.1');
        putenv('PORT=3000');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('10.0.0.1', $host);
        $this->assertSame('3000', $port);
    }

    // ── CLI arg handling ──

    public function testCliPortOnly(): void
    {
        [$host, $port] = resolveHostPort('8080');
        $this->assertSame('8080', $port);
        $this->assertSame('0.0.0.0', $host, 'Host should default when only port given on CLI');
    }

    public function testCliHostAndPort(): void
    {
        [$host, $port] = resolveHostPort('127.0.0.1:4000');
        $this->assertSame('127.0.0.1', $host);
        $this->assertSame('4000', $port);
    }

    // ── Priority: CLI > ENV > default ──

    public function testCliArgOverridesEnvPort(): void
    {
        putenv('PORT=9999');
        [$host, $port] = resolveHostPort('5555');
        $this->assertSame('5555', $port, 'CLI port should override PORT env var');
    }

    public function testCliArgOverridesEnvHostAndPort(): void
    {
        putenv('HOST=10.0.0.1');
        putenv('PORT=9999');
        [$host, $port] = resolveHostPort('192.168.0.1:4444');
        $this->assertSame('192.168.0.1', $host, 'CLI host should override HOST env var');
        $this->assertSame('4444', $port, 'CLI port should override PORT env var');
    }

    public function testCliPortOnlyStillReadsHostFromEnv(): void
    {
        putenv('HOST=10.0.0.1');
        [$host, $port] = resolveHostPort('8080');
        $this->assertSame('10.0.0.1', $host, 'When CLI gives only port, HOST env should still apply');
        $this->assertSame('8080', $port);
    }

    public function testEmptyCliArgFallsToEnv(): void
    {
        putenv('PORT=3333');
        [$host, $port] = resolveHostPort('');
        $this->assertSame('3333', $port);
    }

    // ── DEVADMIN-DEC-02: dev/serve defaults to loopback (feature 127) ──

    public function testDebugDefaultsToLoopback(): void
    {
        // The /__dev dashboard is an unauthenticated file/SQL/RCE surface, so
        // with TINA4_DEBUG on and no explicit host the serve default is loopback.
        putenv('TINA4_DEBUG=true');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('127.0.0.1', $host, 'dev serve must default to loopback');
        $this->assertSame('7145', $port);
    }

    public function testDebugOffDefaultsToAllInterfaces(): void
    {
        putenv('TINA4_DEBUG=false');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('0.0.0.0', $host, 'production default bind is unchanged');
    }

    public function testTina4HostOverridesDebugLoopbackDefault(): void
    {
        // A developer who WANTS network exposure sets TINA4_HOST deliberately;
        // it wins over the debug loopback default.
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_HOST=0.0.0.0');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('0.0.0.0', $host, 'TINA4_HOST override must win over the debug default');
    }

    public function testCliHostOverridesDebugLoopbackDefault(): void
    {
        // The production Docker CMD passes --host 0.0.0.0 (mapped to the
        // positional/flag host), which must win over the debug default.
        putenv('TINA4_DEBUG=true');
        [$host, $port] = resolveHostPort('0.0.0.0:7145');
        $this->assertSame('0.0.0.0', $host, 'an explicit CLI host must win over the debug default');
    }

    public function testTina4HostWinsOverPlainHost(): void
    {
        putenv('TINA4_HOST=1.2.3.4');
        putenv('HOST=5.6.7.8');
        [$host, $port] = resolveHostPort(null);
        $this->assertSame('1.2.3.4', $host, 'TINA4_HOST must win over the legacy plain HOST');
    }
}
