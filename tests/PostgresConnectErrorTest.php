<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

/**
 * A failed PostgreSQL connect must say WHY and WHICH — and never leak the password.
 *
 * Before this, PostgresAdapter::open() threw a bare
 * "PostgresAdapter: Failed to connect to PostgreSQL" and DISCARDED libpq's
 * message. On the lab box that produced 43 identical errors that read like a
 * driver bug; the real cause was a database that did not exist, and libpq had
 * been saying so the whole time.
 *
 * No mocks: the failure is REAL. The connect targets a closed port, so libpq
 * genuinely fails and genuinely produces a message — nothing is simulated.
 */
final class PostgresConnectErrorTest extends TestCase
{
    /** A port nothing listens on, so the connect really fails. */
    private const DEAD_URL = 'postgres://127.0.0.1:1/tina4_does_not_exist';

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('needs ext-pgsql to exercise the adapter');
        }
    }

    /** POSITIVE: the message carries libpq's actual reason, not just "failed". */
    public function testConnectFailureIncludesTheRealCause(): void
    {
        try {
            Database::create(self::DEAD_URL, username: 'tina4', password: 'sup3rs3cret');
            $this->fail('connecting to a closed port must throw');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            $this->assertStringContainsString('Failed to connect to PostgreSQL', $msg);
            // The whole point: something beyond the generic sentence.
            $this->assertGreaterThan(
                strlen('PostgresAdapter: Failed to connect to PostgreSQL'),
                strlen($msg),
                "the error must add the real cause, not just the generic sentence. Got: {$msg}"
            );
        }
    }

    /** POSITIVE: it names the target, so you know WHICH database it wanted. */
    public function testConnectFailureNamesTheTargetDatabase(): void
    {
        try {
            Database::create(self::DEAD_URL, username: 'tina4', password: 'sup3rs3cret');
            $this->fail('connecting to a closed port must throw');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'tina4_does_not_exist',
                $e->getMessage(),
                'the error must name the database it tried to reach — not naming it is what '
                . 'made 43 identical failures unreadable'
            );
        }
    }

    /** NEGATIVE: the password must NEVER appear in the message. */
    public function testConnectFailureNeverLeaksThePassword(): void
    {
        try {
            Database::create(self::DEAD_URL, username: 'tina4', password: 'sup3rs3cret');
            $this->fail('connecting to a closed port must throw');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'sup3rs3cret',
                $e->getMessage(),
                'SECURITY: the DSN in the error must be redacted — an exception message '
                . 'reaches logs and, in debug mode, HTTP 500 bodies'
            );
        }
    }
}
