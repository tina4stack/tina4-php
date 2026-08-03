<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

// MessageLog / RequestInspector are declared alongside DevAdmin and PSR-4
// cannot autoload them individually, so the file is force-included (same as
// tests/DevAdminTest.php).
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\PostgresAdapter;
use Tina4\DatabaseUrl;
use Tina4\DevAdmin;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Every way a database password can reach a log, an exception, a dump or a
 * connection parameter it must not.
 *
 * The sentinel password contains a SPACE on purpose. A space is what turns two
 * of these defects from cosmetic into severe: it ends a value in a libpq
 * keyword/value DSN (so an unescaped password INJECTS further connection
 * parameters) and it ends `\S*` in the old redaction regex (so a password's
 * TAIL survived into the logged message). A sentinel without a space cannot
 * fail either test.
 *
 * NO MOCKS. The connection assertions run against the real PostgreSQL named by
 * TINA4_TEST_PG_* (default: the lab server), including a real temporary role
 * created and dropped here. The parsing and redaction assertions are pure
 * functions over strings and need no service.
 *
 * Case names are mirrored in:
 *   tina4-python/tests/test_database_credential_leak.py
 *   tina4-ruby/spec/database_credential_leak_spec.rb
 *   tina4-nodejs/test/databaseCredentialLeak.test.ts
 */
class DatabaseCredentialLeakTest extends TestCase
{
    /** Space + single quote + backslash: every character libpq quoting has to survive. */
    private const SENTINEL = "s3ntinel-Pa55 word'\\x";

    /** The plain sentinel used where a real login must NOT succeed. */
    private const SENTINEL_PLAIN = 's3ntinel-Pa55 word';

    private const PROBE_ROLE = 'tina4_credential_leak_probe';

    private ?string $savedPgPassword = null;

    protected function setUp(): void
    {
        $existing = getenv('PGPASSWORD');
        $this->savedPgPassword = $existing === false ? null : $existing;
    }

    protected function tearDown(): void
    {
        if ($this->savedPgPassword === null) {
            putenv('PGPASSWORD');
        } else {
            putenv('PGPASSWORD=' . $this->savedPgPassword);
        }
        Router::clear();
        putenv('TINA4_DATABASE_URL');
        // DevAdmin::register() installs error/exception handlers; ErrorTracker
        // is what owns and restores them (same teardown as tests/DevAdminTest.php).
        \Tina4\ErrorTracker::reset();
    }

    // ── live PostgreSQL plumbing ────────────────────────────────────

    private static function pgHost(): string
    {
        return (string) (getenv('TINA4_TEST_PG_HOST') ?: '192.168.88.99');
    }

    private static function pgPort(): int
    {
        return (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
    }

    private static function pgDatabase(): string
    {
        return (string) (getenv('TINA4_TEST_PG_DATABASE') ?: 'tina4_py');
    }

    private static function pgUser(): string
    {
        return (string) (getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4');
    }

    private static function pgPassword(): string
    {
        return (string) (getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4');
    }

    /**
     * Skip LOUDLY, in wording RequireServicesGate recognises, so a missing
     * PostgreSQL is a hard CI failure under TINA4_REQUIRE_SERVICES instead of
     * a silent green.
     */
    private function requireLivePostgres(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql not installed — postgres tests cannot run');
        }
        $socket = @fsockopen(self::pgHost(), self::pgPort(), $errNo, $errStr, 2.0);
        if ($socket === false) {
            $this->markTestSkipped(sprintf(
                'postgres not reachable at %s:%d',
                self::pgHost(),
                self::pgPort()
            ));
        }
        fclose($socket);
    }

    /** Build the URL the adapter is given, with an arbitrary password. */
    private function pgUrl(string $password, ?string $user = null): string
    {
        return sprintf(
            'postgres://%s:%s@%s:%d/%s',
            rawurlencode($user ?? self::pgUser()),
            rawurlencode($password),
            self::pgHost(),
            self::pgPort(),
            self::pgDatabase()
        );
    }

    private function adminAdapter(): PostgresAdapter
    {
        return new PostgresAdapter($this->pgUrl(self::pgPassword()));
    }

    // ── C1: libpq DSN parameter injection ───────────────────────────

    /**
     * THE severe one. libpq ends an unquoted value at the first SPACE and the
     * LAST occurrence of a keyword wins, so a password containing a space used
     * to append (and override) connection parameters.
     *
     * Measured on this exact server before the fix:
     *   password "tina4"                 -> connected to tina4_py  (as asked)
     *   password "tina4 dbname=postgres" -> connected to postgres  (a DIFFERENT database)
     *
     * NEGATIVE half: the injected dbname/sslmode/host must not take effect.
     * POSITIVE half: the honest password still reaches the database it names —
     * a fix that simply broke PostgreSQL would pass the negative half alone.
     */
    public function testAPasswordWithASpaceCannotInjectAConnectionParameter(): void
    {
        $this->requireLivePostgres();

        // POSITIVE: the real credential still connects, to the named database.
        $good = new PostgresAdapter($this->pgUrl(self::pgPassword()));
        $row = $good->fetchOne('select current_database() as db');
        $this->assertSame(
            self::pgDatabase(),
            $row['db'],
            'the honest password must still reach the database the URL names'
        );
        $good->close();

        // NEGATIVE: each injection is now just part of a (wrong) password.
        foreach ([
            'dbname=postgres',
            'sslmode=disable',
            'host=127.0.0.1',
        ] as $injection) {
            $reached = null;
            try {
                $adapter = new PostgresAdapter($this->pgUrl(self::pgPassword() . ' ' . $injection));
                $reached = $adapter->fetchOne('select current_database() as db')['db'];
                $adapter->close();
            } catch (\Throwable $connectFailure) {
                // Authentication failure is the correct outcome: the whole
                // string, spaces and all, IS the password now.
                $this->assertStringContainsString(
                    'PostgresAdapter',
                    $connectFailure->getMessage()
                );
                continue;
            }

            $this->assertSame(
                self::pgDatabase(),
                $reached,
                "injection '{$injection}' inside the password changed the connection"
            );
        }
    }

    /**
     * The other side of the same fix: quoting must be CORRECT, not merely
     * blocking. A real role whose password contains a space, a single quote
     * and a backslash — every character libpq quoting has to escape — must
     * still authenticate.
     *
     * The role is created and dropped inside the test; nothing is left behind.
     */
    public function testAPasswordContainingASpaceQuoteAndBackslashStillAuthenticates(): void
    {
        $this->requireLivePostgres();

        $admin = $this->adminAdapter();
        $role = self::PROBE_ROLE;
        $quotedLiteral = "'" . str_replace("'", "''", self::SENTINEL) . "'";

        $admin->execute("DROP ROLE IF EXISTS {$role}");
        $admin->execute("CREATE ROLE {$role} LOGIN PASSWORD {$quotedLiteral}");

        try {
            // POSITIVE: the awkward password authenticates.
            $probe = new PostgresAdapter($this->pgUrl(self::SENTINEL, $role));
            $row = $probe->fetchOne('select current_user as who, current_database() as db');
            $this->assertSame($role, $row['who']);
            $this->assertSame(self::pgDatabase(), $row['db']);
            $probe->close();

            // NEGATIVE: one character off and it is refused — proving the
            // login above was the password doing the work, not a fallback.
            $this->expectException(\RuntimeException::class);
            new PostgresAdapter($this->pgUrl(self::SENTINEL . 'X', $role));
        } finally {
            $admin->execute("DROP ROLE IF EXISTS {$role}");
            $admin->close();
        }
    }

    /**
     * Every interpolated value is quoted, not only the password: host, dbname
     * and user reach libpq through the same grammar and inject the same way.
     *
     * Pure — buildDsn() is invoked on a real PostgresAdapter created without
     * its constructor, so no connection is opened and nothing is stubbed.
     */
    public function testEveryDsnValueIsQuotedNotJustThePassword(): void
    {
        $adapter = (new \ReflectionClass(PostgresAdapter::class))->newInstanceWithoutConstructor();
        $buildDsn = new \ReflectionMethod(PostgresAdapter::class, 'buildDsn');

        $dsn = $buildDsn->invoke(
            $adapter,
            'postgres://us er:' . rawurlencode(self::SENTINEL) . '@db.host:5432/my db'
        );

        // POSITIVE: every value is present and single-quoted.
        $this->assertStringContainsString("host='db.host'", $dsn);
        $this->assertStringContainsString("port='5432'", $dsn);
        $this->assertStringContainsString("dbname='my db'", $dsn);
        $this->assertStringContainsString("user='us er'", $dsn);

        // NEGATIVE: no bare value survives, so nothing can end at a space.
        $this->assertStringNotContainsString('host=db.host', $dsn);
        $this->assertStringNotContainsString('dbname=my db', $dsn);
        $this->assertStringNotContainsString('password=' . self::SENTINEL, $dsn);
        // The password IS in this string (it is the real DSN) — but escaped:
        // the literal quote and backslash are backslash-escaped.
        $this->assertStringContainsString("password='s3ntinel-Pa55 word\\'\\\\x'", $dsn);
    }

    // ── C2: a malformed URL must not carry the password ─────────────

    /**
     * Measured before the fix, all four frameworks:
     *   "DatabaseUrl: Invalid URL format 'postgres://user:SuperSecret123@host:notaport/db'"
     * That message reaches the boot log, a crash report, the error overlay and
     * a CI log.
     */
    public function testAMalformedDatabaseUrlNeverPutsThePasswordInTheException(): void
    {
        try {
            new DatabaseUrl('postgres://user:' . self::SENTINEL_PLAIN . '@host:notaport/db');
            $this->fail('a URL with a non-numeric port must raise');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();

            // NEGATIVE: not the password, and not any fragment of it.
            $this->assertStringNotContainsString(self::SENTINEL_PLAIN, $message);
            $this->assertStringNotContainsString('s3ntinel', $message);
            $this->assertStringNotContainsString(' word', $message);

            // POSITIVE: still diagnosable — scheme, user, host and the
            // offending port all survive, plus the reason.
            $this->assertStringContainsString('postgres://', $message);
            $this->assertStringContainsString('user', $message);
            $this->assertStringContainsString('host', $message);
            $this->assertStringContainsString('notaport', $message);
            $this->assertStringContainsString('***', $message);
        }
    }

    /**
     * The harder half: a value with NO structure at all is ENTIRELY secret, so
     * redaction has to fail closed. "notaurl-with-<password>" is the exact
     * value that leaked in Python.
     */
    public function testAnUnstructuredDatabaseUrlIsNotEchoedAtAll(): void
    {
        try {
            new DatabaseUrl('notaurl-with-' . self::SENTINEL_PLAIN);
            $this->fail('a value with no scheme must raise');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();

            // NEGATIVE
            $this->assertStringNotContainsString('s3ntinel', $message);
            $this->assertStringNotContainsString('notaurl-with', $message);

            // POSITIVE: it still says what is wrong and what was expected.
            $this->assertStringContainsString('DatabaseUrl', $message);
            $this->assertStringContainsString('Invalid URL format', $message);
            $this->assertStringContainsString('engine://user:password@host:port/database', $message);
        }
    }

    /** The SECOND copy of that message, on the Database factory, leaked identically. */
    public function testTheDatabaseFactoryAlsoRedactsAnUnparseableUrl(): void
    {
        try {
            Database::create('postgres://user:' . self::SENTINEL_PLAIN . '@host:notaport/db');
            $this->fail('an unparseable URL must raise from the factory too');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('s3ntinel', $message);
            $this->assertStringContainsString('Cannot determine database type', $message);
            $this->assertStringContainsString('host', $message);
            $this->assertStringContainsString('***', $message);
        }
    }

    // ── C3 + C4: the real connect-failure path ──────────────────────

    /**
     * C3 was "the redaction helper has zero call sites on any real path"; C4
     * was "PHP's own connect-failure redaction leaks the password TAIL"
     * (`\S*` stops at the first space, so "s3ntinel-Pa55 word" logged as
     * "password=*** word").
     *
     * This drives the REAL pg_connect failure against the live server.
     */
    public function testTheConnectFailureMessageRedactsThePasswordIncludingItsTail(): void
    {
        $this->requireLivePostgres();

        try {
            new PostgresAdapter($this->pgUrl(self::SENTINEL));
            $this->fail('the sentinel password must not authenticate');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            // NEGATIVE: no password, and specifically no TAIL after the space.
            $this->assertStringNotContainsString('s3ntinel', $message);
            $this->assertStringNotContainsString(' word', $message);
            $this->assertStringNotContainsString('Pa55', $message);

            // POSITIVE: everything a maintainer needs is still there — which
            // host, which port, which database, which user, and WHY.
            $this->assertStringContainsString(self::pgHost(), $message);
            $this->assertStringContainsString((string) self::pgPort(), $message);
            $this->assertStringContainsString(self::pgDatabase(), $message);
            $this->assertStringContainsString(self::pgUser(), $message);
            $this->assertStringContainsString('password=***', $message);
            $this->assertMatchesRegularExpression('/authentication|password/i', $message);
        }
    }

    /**
     * C3's other real path: the dev dashboard returned the whole
     * TINA4_DATABASE_URL — password included — from a PUBLIC GET.
     *
     * The registered route callback is invoked directly with a real Request
     * and Response; nothing is stubbed.
     */
    public function testTheDevStatusEndpointRedactsTheConfiguredDatabaseUrl(): void
    {
        putenv('TINA4_DATABASE_URL=postgres://user:' . self::SENTINEL_PLAIN . '@db.host:5432/appdb');

        Router::clear();
        DevAdmin::register();

        foreach (['/__dev/api/status', '/__dev/api/system'] as $pattern) {
            $callback = null;
            foreach (Router::getRoutes() as $route) {
                if ($route['pattern'] === $pattern && $route['method'] === 'GET') {
                    $callback = $route['callback'];
                    break;
                }
            }
            $this->assertNotNull($callback, "{$pattern} should be registered");

            $result = $callback(Request::create('GET', $pattern), new Response(true));
            $payload = json_decode($result->getBody(), true);

            // NEGATIVE
            $this->assertStringNotContainsString('s3ntinel', $payload['database']);

            // POSITIVE: still identifies the connection.
            $this->assertStringContainsString('postgres://', $payload['database']);
            $this->assertStringContainsString('db.host', $payload['database']);
            $this->assertStringContainsString('appdb', $payload['database']);
            $this->assertStringContainsString('***', $payload['database']);
        }
    }

    // ── C5: the ODBC shape the corpus never covered ─────────────────

    /**
     * The existing negative test ("toSafeString never contains the password")
     * passes in all four frameworks because the shared corpus has no ODBC row —
     * the guard is green and protects nothing. An ODBC connection string keeps
     * its secret in `PWD=`, not in URL userinfo, so it needs its own case.
     *
     * PHP reaches this shape through the odbc:/// scheme (Database.php) and
     * through ODBCAdapter's own connection string.
     */
    public function testRedactRemovesAnOdbcPasswordIncludingOneWithASpace(): void
    {
        foreach ([
            'DSN=MyDSN;UID=reader;PWD=' . self::SENTINEL_PLAIN . ';',
            'DSN=MyDSN;UID=reader;PWD={' . self::SENTINEL_PLAIN . '};',
            'DRIVER={SQL Server};SERVER=db.host;DATABASE=appdb;UID=reader;PWD=' . self::SENTINEL_PLAIN,
        ] as $connectionString) {
            $safe = DatabaseUrl::redact($connectionString);

            // NEGATIVE — including the TAIL after the space, which is the
            // half a naive \S-star regex leaves behind.
            $this->assertStringNotContainsString('s3ntinel', $safe, $connectionString);
            $this->assertStringNotContainsString('Pa55', $safe, $connectionString);
            $this->assertStringNotContainsString(' word', $safe, $connectionString);

            // POSITIVE: every non-secret key survives.
            $this->assertStringContainsString('UID=reader', $safe);
            $this->assertStringContainsString('PWD=***', $safe);
            if (str_contains($connectionString, 'DSN=MyDSN')) {
                $this->assertStringContainsString('DSN=MyDSN', $safe);
            }
            if (str_contains($connectionString, 'DATABASE=appdb')) {
                $this->assertStringContainsString('DATABASE=appdb', $safe);
                $this->assertStringContainsString('SERVER=db.host', $safe);
            }
        }
    }

    /** The libpq keyword/value shape, quoted and unquoted, loses only the password. */
    public function testRedactRemovesALibpqPasswordIncludingOneWithASpace(): void
    {
        foreach ([
            "host=db.host port=5432 dbname=appdb user=reader password='" . self::SENTINEL_PLAIN . "'",
            'host=db.host port=5432 dbname=appdb user=reader password=' . self::SENTINEL_PLAIN,
            'password=' . self::SENTINEL_PLAIN . ' host=db.host port=5432 dbname=appdb user=reader',
        ] as $dsn) {
            $safe = DatabaseUrl::redact($dsn);

            $this->assertStringNotContainsString('s3ntinel', $safe, $dsn);
            $this->assertStringNotContainsString('Pa55', $safe, $dsn);
            $this->assertStringNotContainsString(' word', $safe, $dsn);

            $this->assertStringContainsString('host=db.host', $safe);
            $this->assertStringContainsString('dbname=appdb', $safe);
            $this->assertStringContainsString('user=reader', $safe);
            $this->assertStringContainsString('password=***', $safe);
        }
    }

    // ── C6: a dump must not print the password ──────────────────────

    /**
     * print_r/var_dump showed "[password] => pass" and json_encode emitted
     * "password":"pass". Python guards the same object with __repr__ and Ruby
     * with #inspect; PHP had no guard at all — and Tina4 auto-serialises a
     * returned object to JSON, so an un-guarded DatabaseUrl in a route
     * response is a credential on the wire.
     */
    public function testADumpOfADatabaseUrlNeverShowsThePassword(): void
    {
        $url = new DatabaseUrl('postgres://reader:' . self::SENTINEL_PLAIN . '@db.host:5432/appdb');

        ob_start();
        var_dump($url);
        $dumped = (string) ob_get_clean();

        foreach ([print_r($url, true), $dumped, (string) json_encode($url)] as $rendered) {
            // NEGATIVE
            $this->assertStringNotContainsString('s3ntinel', $rendered);
            $this->assertStringNotContainsString('Pa55', $rendered);

            // POSITIVE: still useful — everything except the secret.
            $this->assertStringContainsString('reader', $rendered);
            $this->assertStringContainsString('db.host', $rendered);
            $this->assertStringContainsString('appdb', $rendered);
            $this->assertStringContainsString('***', $rendered);
        }

        // The property itself is untouched — this is a display guard, not a
        // functional one. A connection still needs the real password.
        $this->assertSame(self::SENTINEL_PLAIN, $url->password);
    }

    /**
     * The CLI banner is a real leak path too: `tina4php console` printed the
     * whole TINA4_DATABASE_URL, so the password landed in a terminal
     * scrollback, a screen share and any piped CI log.
     *
     * Drives the REAL binary in a REAL temporary project (its own .env, the
     * repo's vendor/), with stdin at EOF so the REPL exits immediately.
     */
    public function testTheConsoleBannerNeverPrintsTheDatabasePassword(): void
    {
        $this->requireLivePostgres();

        $project = sys_get_temp_dir() . '/tina4_console_leak_' . uniqid();
        mkdir($project, 0755, true);
        symlink(dirname(__DIR__) . '/vendor', $project . '/vendor');
        $binary = dirname(__DIR__) . '/bin/tina4php';

        try {
            // (a) The connected banner.
            file_put_contents(
                $project . '/.env',
                'TINA4_DATABASE_URL=' . $this->pgUrl(self::pgPassword()) . "\n"
            );
            $connected = (string) shell_exec(
                'cd ' . escapeshellarg($project) . ' && '
                . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' console < /dev/null 2>&1'
            );

            $this->assertStringContainsString('Database: postgres://', $connected);
            $this->assertStringContainsString(':***@', $connected);
            $this->assertStringNotContainsString(
                self::pgUser() . ':' . self::pgPassword() . '@',
                $connected
            );
            // POSITIVE: still says WHICH database.
            $this->assertStringContainsString(self::pgHost(), $connected);
            $this->assertStringContainsString(self::pgDatabase(), $connected);

            // (b) The failure banner — it prints the driver's own message.
            file_put_contents(
                $project . '/.env',
                'TINA4_DATABASE_URL=' . $this->pgUrl(self::SENTINEL_PLAIN) . "\n"
            );
            $failed = (string) shell_exec(
                'cd ' . escapeshellarg($project) . ' && '
                . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' console < /dev/null 2>&1'
            );

            $this->assertStringContainsString('Database: failed', $failed);
            $this->assertStringNotContainsString('s3ntinel', $failed);
            $this->assertStringNotContainsString(' word', $failed);
            $this->assertStringContainsString('password=***', $failed);
            $this->assertStringContainsString(self::pgDatabase(), $failed);
        } finally {
            @unlink($project . '/.env');
            @unlink($project . '/vendor');
            foreach (glob($project . '/*') ?: [] as $leftover) {
                if (is_dir($leftover)) {
                    foreach (glob($leftover . '/*') ?: [] as $inner) {
                        @unlink($inner);
                    }
                    @rmdir($leftover);
                } else {
                    @unlink($leftover);
                }
            }
            @rmdir($project);
        }
    }

    // ── C7: absent and blank are different ──────────────────────────

    /** The parse-level half: '' is an explicitly empty password, null is absent. */
    public function testAnEmptyPasswordInTheUrlIsExplicitlyEmptyNotAbsent(): void
    {
        $this->assertSame('', (new DatabaseUrl('postgres://user:@host/db'))->password);
        $this->assertNull((new DatabaseUrl('postgres://user@host/db'))->password);
    }

    /**
     * The half that matters, driven against the real driver.
     *
     * An empty password used to be OMITTED from the DSN entirely, which handed
     * the decision to libpq — and libpq falls back to PGPASSWORD. Measured
     * 2026-08-02: PGPASSWORD=<real password> with the URL
     * postgres://user:@host/db CONNECTED, i.e. the same .env authenticated
     * with a password nobody put in it.
     *
     * NEGATIVE: an EMPTY password in the URL must be sent as an empty
     * password, so the environment cannot fill it in.
     * POSITIVE: an ABSENT password still allows the fallback — the two must
     * not collapse into one behaviour.
     */
    public function testAnEmptyPasswordInTheUrlDoesNotFallBackToTheEnvironment(): void
    {
        $this->requireLivePostgres();

        putenv('PGPASSWORD=' . self::pgPassword());

        // NEGATIVE: "user:@host" states an empty password. It must be refused.
        try {
            $adapter = new PostgresAdapter($this->pgUrl(''));
            $adapter->close();
            $this->fail('an explicitly empty password must not fall back to PGPASSWORD');
        } catch (\RuntimeException $e) {
            // The driver's own words: it did NOT reach for PGPASSWORD.
            $this->assertStringContainsString('PostgresAdapter', $e->getMessage());
            $this->assertMatchesRegularExpression(
                '/no password supplied|password authentication failed/i',
                $e->getMessage()
            );
        }

        // POSITIVE: no password component at all — absent, so the environment
        // is allowed to supply one and the connection succeeds.
        $absent = new PostgresAdapter(sprintf(
            'postgres://%s@%s:%d/%s',
            rawurlencode(self::pgUser()),
            self::pgHost(),
            self::pgPort(),
            self::pgDatabase()
        ));
        $this->assertSame(
            self::pgDatabase(),
            $absent->fetchOne('select current_database() as db')['db']
        );
        $absent->close();
    }

    /**
     * THE BOUNDARY GUARD: no framework code may serialize or var_export a
     * DatabaseUrl, because those two DELIBERATELY keep the password.
     *
     * MEASURED 2026-08-03 with a sentinel containing a space, a quote and a
     * backslash: print_r, var_dump, json_encode, __toString and toSafeString
     * all redact, but var_export() and serialize() still emit the password in
     * full. That is not an oversight and masking them would be wrong - their
     * whole contract is a FAITHFUL round trip, and a masked serialize()
     * unserializes into an object whose password is the literal "***".
     *
     * The rule is therefore: DISPLAY redacts, FIDELITY does not. That rule is
     * only safe while nothing in the framework persists one of these objects -
     * a DatabaseUrl written into a session file, a cache entry or a queue
     * payload would put the password on disk. This test is what keeps that
     * true, so the boundary is enforced rather than merely documented.
     *
     * (print_r on an EXCEPTION also leaks, because PHP stores constructor
     * arguments in the stack trace. Not reachable through Tina4: both
     * getTrace() consumers - ErrorOverlay.php:67 and DevAdmin.php:3895 - read
     * only file/line/class/type/function and never touch args. Verified.)
     */
    public function testNoFrameworkCodeSerializesOrVarExportsADatabaseUrl(): void
    {
        $root = realpath(__DIR__ . '/../Tina4');
        $this->assertNotFalse($root, 'the framework source directory must exist');

        $offenders = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if ($src === false) {
                continue;
            }
            foreach (explode("\n", $src) as $n => $line) {
                if (preg_match('/\b(serialize|var_export)\s*\(/i', $line)
                    && preg_match('/\$(url|databaseUrl|dbUrl|dsn)\b/i', $line)) {
                    $offenders[] = basename($file->getPathname()) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "serialize()/var_export() on a DatabaseUrl writes the PASSWORD verbatim - those two keep "
            . "the secret on purpose so the round trip stays faithful. Persisting one puts a credential "
            . "on disk. Use toSafeString() to record it, or store the redacted parts:\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    /**
     * Negative case - proves the guard above has TEETH.
     *
     * The scanner is fed a line of the exact shape it must catch. Without this,
     * a regex that silently stopped matching would leave the guard green and
     * guarding nothing - the same vacuous-pass that the ClassCollection guard
     * hit this week.
     */
    public function testTheSerializeGuardDetectsAnOffendingLine(): void
    {
        $offending = '        $payload = serialize($url);';
        $innocent  = '        $payload = json_encode($url->toSafeString());';

        $matches = static fn(string $line): bool =>
            (bool) preg_match('/\b(serialize|var_export)\s*\(/i', $line)
            && (bool) preg_match('/\$(url|databaseUrl|dbUrl|dsn)\b/i', $line);

        $this->assertTrue($matches($offending), 'the scanner must flag serialize($url)');
        $this->assertFalse($matches($innocent), 'the scanner must not flag a redacted render');
    }
}
