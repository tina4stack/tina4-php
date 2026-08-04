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

    /** Historic literals, kept as the fallback so a host publishing them is unaffected. */
    private const FALLBACK_HOST = 'localhost';
    private const FALLBACK_PORT = 53050;
    private const FALLBACK_DB_PATH = '/firebird/data/tina4.fdb';

    /**
     * Resolve the live-Firebird host, port and database path.
     *
     * Reads TINA4_TEST_FIREBIRD_URL — the SAME variable the rest of this suite
     * and the Python / Ruby / Node suites already use — so one export points
     * every framework at the same server.
     *
     * These three values were hardcoded to localhost:53050 and
     * /firebird/data/tina4.fdb. Nothing publishes 53050 on the lab host
     * (Firebird is on 3050), so the reachability gate below could NEVER open:
     * the three live tests skipped green on every machine, which is
     * indistinguishable from "environment not set up" and is exactly how a
     * permanently dead test stays invisible. The literals stay as the fallback,
     * so a host that really does publish 53050 with that database path behaves
     * precisely as before.
     *
     * Mirrors tina4-python's _live_firebird_target(). Deliberately NOT cached:
     * testLiveFirebirdTargetResolution* pin it as a pure function of the
     * environment, which is what stops the coordinates silently drifting back.
     *
     * @return array{0: string, 1: int, 2: string} [host, port, database path]
     */
    private static function liveFirebirdTarget(): array
    {
        $raw = getenv('TINA4_TEST_FIREBIRD_URL');
        $url = trim($raw === false ? '' : $raw);
        if ($url === '') {
            return [self::FALLBACK_HOST, self::FALLBACK_PORT, self::FALLBACK_DB_PATH];
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return [self::FALLBACK_HOST, self::FALLBACK_PORT, self::FALLBACK_DB_PATH];
        }

        $path = (string)($parsed['path'] ?? '');
        // `@host:port//abs/path` is the absolute-path spelling; collapse the
        // doubled leading slash so this is a plain absolute path again. The
        // tests below re-add it for the double-slash case and strip it for the
        // single-slash one.
        if (str_starts_with($path, '//')) {
            $path = substr($path, 1);
        }

        return [
            (string)($parsed['host'] ?? self::FALLBACK_HOST),
            (int)($parsed['port'] ?? 3050),
            $path !== '' ? $path : self::FALLBACK_DB_PATH,
        ];
    }

    private static function firebirdHost(): string
    {
        return self::liveFirebirdTarget()[0];
    }

    private static function firebirdPort(): int
    {
        return self::liveFirebirdTarget()[1];
    }

    private static function liveDbPath(): string
    {
        return self::liveFirebirdTarget()[2];
    }

    private static function firebirdReachable(): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen(self::firebirdHost(), self::firebirdPort(), $errno, $errstr, 1.0);
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
                    self::firebirdHost(),
                    self::firebirdPort()
                )
            );
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped(
                'ext-interbase not installed — host PHP cannot speak Firebird wire protocol'
            );
        }
    }

    // ── The gate's own coordinates are RESOLVED, not hardcoded ──────────
    //
    // A pure function over a real environment variable — no doubles. Without
    // these the port could silently drift back to a value nothing publishes and
    // the live tests would resume skipping green, which is the failure this fix
    // exists to end. Mirrors tina4-python's TestLiveFirebirdTargetResolution.

    /**
     * Set (or clear) TINA4_TEST_FIREBIRD_URL, resolve, then restore.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private static function resolveWithUrl(?string $value): array
    {
        $key = 'TINA4_TEST_FIREBIRD_URL';
        $previousRaw = getenv($key);
        $previous = $previousRaw === false ? null : $previousRaw;

        try {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }

            return self::liveFirebirdTarget();
        } finally {
            if ($previous === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$previous}");
                $_ENV[$key] = $previous;
            }
        }
    }

    public function testLiveFirebirdTargetResolutionUnsetFallsBackToHistoricLiterals(): void
    {
        $this->assertSame(
            ['localhost', 53050, '/firebird/data/tina4.fdb'],
            self::resolveWithUrl(null),
            'with nothing exported the historic literals must still apply, so a host that really '
            . 'does publish 53050 behaves exactly as it did before'
        );
    }

    public function testLiveFirebirdTargetResolutionBlankIsTreatedAsUnset(): void
    {
        $this->assertSame(
            ['localhost', 53050, '/firebird/data/tina4.fdb'],
            self::resolveWithUrl('   ')
        );
    }

    public function testLiveFirebirdTargetResolutionParsesAbsoluteDoubleSlashForm(): void
    {
        [$host, $port, $path] = self::resolveWithUrl(
            'firebird://SYSDBA:masterkey@db.example:3050//var/lib/firebird/data/x.fdb'
        );

        $this->assertSame('db.example', $host);
        $this->assertSame(3050, $port);
        // One leading slash, not two — the callers re-add the second for the
        // double-slash spelling and strip it for the single-slash one.
        $this->assertSame('/var/lib/firebird/data/x.fdb', $path);
    }

    public function testLiveFirebirdTargetResolutionParsesSingleSlashForm(): void
    {
        $this->assertSame(
            ['h', 3050, '/rel.fdb'],
            self::resolveWithUrl('firebird://SYSDBA:masterkey@h:3050/rel.fdb')
        );
    }

    public function testLiveFirebirdTargetResolutionOmittedPortDefaultsTo3050Not53050(): void
    {
        // THE REGRESSION GUARD: 53050 must never come back as a parsed default.
        [, $port] = self::resolveWithUrl('firebird://SYSDBA:masterkey@h//data/x.fdb');

        $this->assertSame(
            3050,
            $port,
            '53050 is not a Firebird port anybody publishes; a parsed URL with no port must '
            . 'default to the real one, or the live tests go back to skipping green forever'
        );
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
                    'Host ext-interbase cannot connect to the Firebird server (wire-protocol '
                    . 'mismatch) — native Firebird UNVERIFIED here: ' . $msg
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
            self::firebirdHost(),
            self::firebirdPort(),
            self::liveDbPath() // begins with "/"
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
            self::firebirdHost(),
            self::firebirdPort(),
            self::liveDbPath() // already starts with "/" — gives "//..." when joined
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
            self::firebirdHost(),
            self::firebirdPort()
        );
        putenv('TINA4_DATABASE_FIREBIRD_PATH=' . self::liveDbPath());
        $_ENV['TINA4_DATABASE_FIREBIRD_PATH'] = self::liveDbPath();

        $adapter = $this->tryConnect($wrongUrl);
        $row = $adapter->fetchOne('SELECT 1 AS x FROM rdb$database');
        $this->assertNotNull($row);
        $value = $row['X'] ?? $row['x'] ?? null;
        $this->assertSame(1, (int)$value);
        $adapter->close();
    }
}
