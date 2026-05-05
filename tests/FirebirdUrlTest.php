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

    private const FIREBIRD_HOST = 'localhost';
    private const FIREBIRD_PORT = 53050;
    private const LIVE_DB_PATH = '/firebird/data/tina4.fdb';

    private static function firebirdReachable(): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen(self::FIREBIRD_HOST, self::FIREBIRD_PORT, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    private function skipIfNoFirebird(): void
    {
        if (!self::firebirdReachable()) {
            $this->markTestSkipped(
                sprintf(
                    'Firebird not reachable at %s:%d',
                    self::FIREBIRD_HOST,
                    self::FIREBIRD_PORT
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

        $url = sprintf(
            'firebird://SYSDBA:masterkey@%s:%d%s',
            self::FIREBIRD_HOST,
            self::FIREBIRD_PORT,
            self::LIVE_DB_PATH // begins with "/"
        );
        $adapter = $this->tryConnect($url);
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
        $url = sprintf(
            'firebird://SYSDBA:masterkey@%s:%d/%s',
            self::FIREBIRD_HOST,
            self::FIREBIRD_PORT,
            self::LIVE_DB_PATH // already starts with "/" — gives "//..." when joined
        );
        $adapter = $this->tryConnect($url);
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
        $wrongUrl = sprintf(
            'firebird://SYSDBA:masterkey@%s:%d/this/path/does/not/exist.fdb',
            self::FIREBIRD_HOST,
            self::FIREBIRD_PORT
        );
        putenv('TINA4_DATABASE_FIREBIRD_PATH=' . self::LIVE_DB_PATH);
        $_ENV['TINA4_DATABASE_FIREBIRD_PATH'] = self::LIVE_DB_PATH;

        $adapter = $this->tryConnect($wrongUrl);
        $row = $adapter->fetchOne('SELECT 1 AS x FROM rdb$database');
        $this->assertNotNull($row);
        $value = $row['X'] ?? $row['x'] ?? null;
        $this->assertSame(1, (int)$value);
        $adapter->close();
    }
}
