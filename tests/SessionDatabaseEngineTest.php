<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DatabaseSessionHandler against REAL non-SQLite engines.
 *
 * WHY THIS FILE EXISTS. The handler called `$this->db->exec(...)`, which is
 * defined ONLY on SQLite3Adapter (and is deprecated even there) — every other
 * adapter implements `execute()`. So on PostgreSQL and MySQL every single
 * session write raised "Call to undefined method ...::exec()", the Session
 * boundary caught it and degraded, and the application ran with NO WORKING
 * SESSIONS while nothing surfaced on stdout. It shipped because the entire
 * existing database-session suite runs on SQLite, the one engine where the
 * broken call happens to resolve.
 *
 * A SQLite-only test can never catch this class of defect. These gates run on
 * the real engines, and every existence assertion is made OUT OF BAND with raw
 * PDO — never through the code under test.
 *
 * NO MOCKS. If an engine is unreachable the test skips LOUDLY naming host and
 * port, unless TINA4_REQUIRE_SERVICES is set — then a missing service is a
 * FAILURE, because a suite that silently skips its only real verification is
 * not verification.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Session\DatabaseSessionHandler;

class SessionDatabaseEngineTest extends TestCase
{
    /**
     * Every engine this gate covers.
     *
     * @return array<string, array{0:string,1:string,2:int,3:string,4:string,5:string}>
     */
    public static function engines(): array
    {
        return [
            'postgresql' => [
                getenv('TINA4_TEST_PG_URL') ?: 'postgres://127.0.0.1:55432/postgres',
                getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1',
                (int)(getenv('TINA4_TEST_PG_PORT') ?: 55432),
                getenv('TINA4_TEST_PG_USER') ?: 'tina4',
                getenv('TINA4_TEST_PG_PASS') ?: 'tina4',
                'pgsql:host=%s;port=%d;dbname=postgres',
            ],
            'mysql' => [
                getenv('TINA4_TEST_MYSQL_URL') ?: 'mysql://127.0.0.1:3306/tina4_test',
                getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1',
                (int)(getenv('TINA4_TEST_MYSQL_PORT') ?: 3306),
                getenv('TINA4_TEST_MYSQL_USER') ?: 'root',
                getenv('TINA4_TEST_MYSQL_PASS') ?: 'tina4',
                'mysql:host=%s;port=%d;dbname=tina4_test',
            ],
        ];
    }

    /** Skip LOUDLY (naming host and port) unless services are required. */
    private function requireReachable(string $engine, string $host, int $port): void
    {
        $probe = @fsockopen($host, $port, $errNo, $errStr, 2);
        if ($probe === false) {
            $message = sprintf(
                '%s is not reachable at %s:%d (%s)',
                $engine,
                $host,
                $port,
                $errStr ?: 'no error reported'
            );
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
            }
            $this->markTestSkipped($message);
        }
        fclose($probe);
    }

    /**
     * @param array{0:string,1:string,2:int,3:string,4:string,5:string} $spec
     * @return array{0:\Tina4\Database\DatabaseAdapter,1:\PDO}
     */
    private function connect(string $engine, array $spec): array
    {
        [$url, $host, $port, $user, $pass, $dsn] = $spec;
        $this->requireReachable($engine, $host, $port);

        try {
            $pdo = new \PDO(sprintf($dsn, $host, $port), $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $adapter = Database::create($url, username: $user, password: $pass)->getAdapter();
        } catch (\Throwable $e) {
            $message = sprintf('%s at %s:%d refused the connection: %s', $engine, $host, $port, $e->getMessage());
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
            }
            $this->markTestSkipped($message);
        }

        $pdo->exec('DROP TABLE IF EXISTS tina4_session');
        return [$adapter, $pdo];
    }

    /**
     * The headline outage gate: on a real non-SQLite engine, write() must
     * actually put a row in the table and read() must return it.
     *
     * Measured before the fix on PostgreSQL 16.14 and MySQL 8.4.11:
     * rows after write() = 0, read() = null, save() = false, silently.
     *
     */
    #[DataProvider('engines')]
    public function testSessionDatabaseBackendWritesOnRealEngine(
        string $url,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $dsn
    ): void {
        $engine = $this->dataName();
        [$adapter, $pdo] = $this->connect($engine, [$url, $host, $port, $user, $pass, $dsn]);

        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 3600]);
        $sessionId = 'engine-' . bin2hex(random_bytes(4));
        $handler->write($sessionId, ['seeded' => true], 3600);

        // OUT OF BAND — raw PDO, a separate connection, not the code under test.
        $statement = $pdo->prepare('SELECT count(*) FROM tina4_session WHERE session_id = ?');
        $statement->execute([$sessionId]);
        $this->assertSame(
            1,
            (int)$statement->fetchColumn(),
            "the row must actually reach {$engine} — a degraded write that stores nothing is the outage"
        );

        $this->assertSame(
            ['seeded' => true],
            $handler->read($sessionId),
            "the session must round-trip through {$engine}"
        );

        $pdo->exec('DROP TABLE IF EXISTS tina4_session');
    }

    /**
     * expires_at must survive a real round-trip.
     *
     * REAL is float4 on PostgreSQL: one ULP at the current epoch is 128 SECONDS,
     * and the drift compounds because the text wire format for float4 does not
     * round-trip into a 64-bit double either. Measured 111.99s worst case over
     * 500 real round-trips on PostgreSQL 16.14 — so any session shorter than
     * ~2 minutes could be judged expired the instant it was written.
     *
     */
    #[DataProvider('engines')]
    public function testSessionExpiresAtSurvivesRealEngineRoundTrip(
        string $url,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $dsn
    ): void {
        $engine = $this->dataName();
        [$adapter, $pdo] = $this->connect($engine, [$url, $host, $port, $user, $pass, $dsn]);

        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 3600]);

        // Repeat to defeat the 128-second float4 bucket — a single sample can
        // land near a representable value and look fine.
        $worstDrift = 0.0;
        for ($i = 0; $i < 20; $i++) {
            $sessionId = 'drift-' . $i . '-' . bin2hex(random_bytes(3));
            $intended = microtime(true) + 3600;
            $handler->write($sessionId, ['i' => $i], 3600);

            $statement = $pdo->prepare('SELECT expires_at FROM tina4_session WHERE session_id = ?');
            $statement->execute([$sessionId]);
            $stored = (float)$statement->fetchColumn();

            $worstDrift = max($worstDrift, abs($stored - $intended));
        }

        $this->assertLessThan(
            1.0,
            $worstDrift,
            "expires_at drifted {$worstDrift}s on {$engine} — that is single-precision loss, not clock skew"
        );

        $pdo->exec('DROP TABLE IF EXISTS tina4_session');
    }

    /**
     * A row carrying NO expiry (expires_at = 0) must be returned and KEPT, and a
     * genuinely expired row must still be reaped. Both on the real engine.
     *
     */
    #[DataProvider('engines')]
    public function testSessionUnstampedRowSurvivesButExpiredRowIsReapedOnRealEngine(
        string $url,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $dsn
    ): void {
        $engine = $this->dataName();
        [$adapter, $pdo] = $this->connect($engine, [$url, $host, $port, $user, $pass, $dsn]);

        $handler = new DatabaseSessionHandler(['db' => $adapter, 'ttl' => 3600]);
        $handler->write('seed-row', ['seeded' => true], 3600);   // creates the table

        // POSITIVE: expires_at = 0 means "never expires".
        $pdo->prepare('INSERT INTO tina4_session (session_id, data, expires_at) VALUES (?, ?, 0)')
            ->execute(['unstamped', '{"seeded":true}']);
        $this->assertSame(
            ['seeded' => true],
            $handler->read('unstamped'),
            'a row with no expiry must be returned — absent expiry means never expires'
        );
        $count = $pdo->prepare('SELECT count(*) FROM tina4_session WHERE session_id = ?');
        $count->execute(['unstamped']);
        $this->assertSame(1, (int)$count->fetchColumn(), 'an unstamped row must NOT be deleted');

        // NEGATIVE CONTROL: a genuinely past expiry is still reaped.
        $pdo->prepare('INSERT INTO tina4_session (session_id, data, expires_at) VALUES (?, ?, 1)')
            ->execute(['expired', '{"seeded":true}']);
        $this->assertNull($handler->read('expired'), 'a genuinely expired row must read as null');
        $count->execute(['expired']);
        $this->assertSame(0, (int)$count->fetchColumn(), 'a genuinely expired row must still be REAPED');

        $pdo->exec('DROP TABLE IF EXISTS tina4_session');
    }
}
