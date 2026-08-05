<?php

/**
 * Firebird URL parsing + TINA4_DATABASE_FIREBIRD_PATH override tests.
 *
 * The Firebird URL is the awkward one in the stack — every other engine
 * (PostgreSQL, MySQL, MSSQL) has a server-side database name where you
 * can write `pg://host:port/dbname` and the path component is just a
 * name. Firebird wants either an absolute file path on the server, a
 * Windows drive-letter path, or an alias. The classic URI form needs a
 * double slash to keep the absolute path through ``parse_url``, which
 * is unintuitive.
 *
 * This suite verifies the framework accepts five equivalent forms and
 * also honours ``TINA4_DATABASE_FIREBIRD_PATH`` as an explicit override
 * (useful for Windows backslash paths and ops setups that keep config
 * split across layers).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;
use Tina4\DotEnv;

class FirebirdUrlTest extends TestCase
{
    // ── Unit tests on FirebirdAdapter::normalizeDbIdentifier ─────────────

    public function testClassicDoubleSlashAbsolutePath(): void
    {
        // firebird://host:port//abs/path/db.fdb → parse_url path = //abs/path/db.fdb
        $this->assertSame(
            '/firebird/data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('//firebird/data/app.fdb')
        );
    }

    public function testSingleSlashAbsolutePath(): void
    {
        // firebird://host:port/abs/path/db.fdb → parse_url path = /abs/path/db.fdb
        $this->assertSame(
            '/firebird/data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('/firebird/data/app.fdb')
        );
    }

    public function testWindowsDriveLetterWithLeadingSlash(): void
    {
        // firebird://host:port/C:/Data/db.fdb → parse_url path = /C:/Data/db.fdb
        $this->assertSame(
            'C:/Data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('/C:/Data/app.fdb')
        );
    }

    public function testWindowsDriveLetterUrlEncoded(): void
    {
        // firebird://host:port/C%3A/Data/db.fdb → parse_url path = /C%3A/Data/db.fdb
        $this->assertSame(
            'C:/Data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('/C%3A/Data/app.fdb')
        );
    }

    public function testAliasSingleToken(): void
    {
        // firebird://host:port/employee → parse_url path = /employee
        $this->assertSame(
            'employee',
            FirebirdAdapter::normalizeDbIdentifier('/employee')
        );
    }

    public function testRelativePathWithSlashGetsPromotedToAbsolute(): void
    {
        // If user writes a path-like value without a leading slash, we
        // treat it as an absolute path (Firebird doesn't have a notion
        // of relative paths anyway). Prepend a slash so the driver sees
        // an absolute path and errors clearly if it doesn't exist.
        $this->assertSame(
            '/data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('data/app.fdb')
        );
    }

    public function testUrlEncodedUnicodeInPath(): void
    {
        // Path with URL-encoded non-ASCII char — decoded correctly.
        $this->assertSame(
            '/data/déjà.fdb',
            FirebirdAdapter::normalizeDbIdentifier('/data/d%C3%A9j%C3%A0.fdb')
        );
    }

    public function testLowercaseDriveLetter(): void
    {
        // Lowercase drive letters must work the same as uppercase.
        $this->assertSame(
            'c:/data/app.fdb',
            FirebirdAdapter::normalizeDbIdentifier('/c:/data/app.fdb')
        );
    }

    // ── Live tests against a Firebird container ─────────────────────────

    // Fallbacks only. TINA4_TEST_FIREBIRD_URL is the canonical way to say where
    // Firebird lives (ADR-0038), and these constants describe ONE particular
    // container layout — a different port and a different data directory from
    // the one the lab actually runs. Hard-coded, they turned three live tests
    // into permanent skips on a host with a perfectly good Firebird on 3050:
    // the service was there, the address was wrong, and the skip reason said
    // "not reachable", which reads like a missing service rather than a
    // misconfigured test.
    private const FIREBIRD_HOST = 'localhost';
    private const FIREBIRD_PORT = 53050;
    private const LIVE_DB_PATH = '/firebird/data/tina4.fdb';

    /**
     * Where the live Firebird actually is: TINA4_TEST_FIREBIRD_URL when set,
     * else the container constants above.
     *
     * @return array{host: string, port: int, path: string, user: string, pass: string}
     */
    private static function liveTarget(): array
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        $parts = ($url === false || $url === '') ? [] : (parse_url($url) ?: []);

        // Normalise to ONE leading slash. The env URL uses the double-slash
        // absolute form, so parse_url hands back "//var/lib/..."; the tests
        // below add the second slash themselves where they want it.
        $path = isset($parts['path']) && $parts['path'] !== ''
            ? '/' . ltrim($parts['path'], '/')
            : self::LIVE_DB_PATH;

        return [
            'host' => $parts['host'] ?? self::FIREBIRD_HOST,
            'port' => (int) ($parts['port'] ?? self::FIREBIRD_PORT),
            'path' => $path,
            'user' => isset($parts['user']) ? urldecode($parts['user']) : 'SYSDBA',
            'pass' => isset($parts['pass']) ? urldecode($parts['pass']) : 'masterkey',
        ];
    }

    /** Build a live connection URL, with `$extraSlash` for the double-slash form. */
    private static function liveUrl(string $extraSlash = '', ?string $overridePath = null): string
    {
        $t = self::liveTarget();
        return sprintf(
            'firebird://%s:%s@%s:%d%s%s',
            $t['user'],
            $t['pass'],
            $t['host'],
            $t['port'],
            $extraSlash,
            $overridePath ?? $t['path']
        );
    }

    private static function firebirdReachable(): bool
    {
        $t = self::liveTarget();
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($t['host'], $t['port'], $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    private function skipIfNoFirebird(): void
    {
        if (!self::firebirdReachable()) {
            $t = self::liveTarget();
            $this->markTestSkipped(
                sprintf(
                    'Firebird not reachable at %s:%d (set TINA4_TEST_FIREBIRD_URL to point at a live server)',
                    $t['host'],
                    $t['port']
                )
            );
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped(
                'ext-interbase not available — host PHP cannot speak Firebird wire protocol'
            );
        }
    }

    protected function tearDown(): void
    {
        // Clean up env override between live tests.
        DotEnv::resetEnv();
        putenv('TINA4_DATABASE_FIREBIRD_PATH');
        unset($_ENV['TINA4_DATABASE_FIREBIRD_PATH'], $_SERVER['TINA4_DATABASE_FIREBIRD_PATH']);
    }

    /**
     * Connect, but skip the test on driver-level wire-protocol errors —
     * the host PHP's bundled ext-interbase is often too old to speak to
     * a modern Firebird 4/5 container ("Invalid clumplet buffer
     * structure"). The Docker image tina4-php-test:8.4 ships a matching
     * driver and runs these tests for real.
     */
    private function tryConnect(string $url): ?FirebirdAdapter
    {
        try {
            return new FirebirdAdapter($url);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (
                str_contains($msg, 'clumplet')
                || str_contains($msg, 'wire')
                || str_contains($msg, 'protocol')
                || str_contains($msg, 'unsupported')
            ) {
                $this->markTestSkipped(
                    'Host ext-interbase cannot speak to Firebird container: ' . $msg
                );
            }
            throw $e;
        }
    }

    public function testLiveSingleSlashFormConnects(): void
    {
        $this->skipIfNoFirebird();

        // Single-slash form — the path already begins with "/".
        $adapter = $this->tryConnect(self::liveUrl());
        $row = $adapter->fetchOne('SELECT 1 AS x FROM rdb$database');
        $this->assertNotNull($row);
        $value = $row['X'] ?? $row['x'] ?? null;
        $this->assertSame(1, (int)$value);
        $adapter->close();
    }

    public function testLiveDoubleSlashFormConnects(): void
    {
        $this->skipIfNoFirebird();

        // Classic double-slash form — parse_url leaves "//path" in the
        // path component. Normalisation strips one slash.
        // The extra slash joins with the path's own leading "/" to give "//...".
        $adapter = $this->tryConnect(self::liveUrl('/'));
        $row = $adapter->fetchOne('SELECT 1 AS x FROM rdb$database');
        $this->assertNotNull($row);
        $value = $row['X'] ?? $row['x'] ?? null;
        $this->assertSame(1, (int)$value);
        $adapter->close();
    }

    public function testLiveEnvOverrideWinsOverWrongUrl(): void
    {
        $this->skipIfNoFirebird();

        // Provide a deliberately wrong URL path; the env override points
        // at the real DB. The framework must connect to the real one.
        $wrongUrl = self::liveUrl('', '/this/path/does/not/exist.fdb');
        $realPath = self::liveTarget()['path'];
        putenv('TINA4_DATABASE_FIREBIRD_PATH=' . $realPath);
        $_ENV['TINA4_DATABASE_FIREBIRD_PATH'] = $realPath;

        $adapter = $this->tryConnect($wrongUrl);
        $row = $adapter->fetchOne('SELECT 1 AS x FROM rdb$database');
        $this->assertNotNull($row);
        $value = $row['X'] ?? $row['x'] ?? null;
        $this->assertSame(1, (int)$value);
        $adapter->close();
    }
}
