<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;
use Tina4\Database\Database;

/**
 * A percent-encoded password in a DATABASE_URL must reach the driver DECODED.
 *
 * PHP was already correct here - its adapters urldecode the userinfo. Python
 * was the framework that did NOT (its adapters read urlparse().password, which
 * returns the RAW userinfo), and this file exists so PHP cannot ACQUIRE the bug
 * in a later refactor.
 *
 * The failure mode is why it matters: the driver reports a plain "login
 * failed", nothing mentions the URL, the password looks right in the config,
 * and the same credentials work when passed as separate arguments.
 *
 * NO MOCKS: pure parsing, plus a live PostgreSQL round trip when one is set.
 *
 * Identical case names in all four frameworks:
 *   tina4-python/tests/test_database_url_credentials.py
 *   tina4-ruby/spec/database_url_credentials_spec.rb
 *   tina4-nodejs/test/databaseUrlCredentials.test.ts
 */
class DatabaseUrlCredentialsTest extends TestCase
{
    public function testAPercentEncodedPasswordIsDecoded(): void
    {
        $url = new DatabaseUrl('mssql://sa:TinaSQL123%21Secure@h:1433/db');
        $this->assertEquals('TinaSQL123!Secure', $url->password);
    }

    /**
     * Exactly the characters that FORCE encoding in a URL - the only ones that
     * can expose the bug. A password without them works either way.
     */
    public function testEveryReservedCharacterSurvivesARoundTrip(): void
    {
        $url = new DatabaseUrl('postgres://us%3Aer:p%40ss%21w%3Ard%2Fx%23y@h:5432/db');
        $this->assertEquals('us:er', $url->username);
        $this->assertEquals('p@ss!w:rd/x#y', $url->password);
    }

    public function testAnUnencodedPasswordIsUnchanged(): void
    {
        $this->assertEquals('tina4', (new DatabaseUrl('postgres://tina4:tina4@h:5432/db'))->password);
    }

    /** A real '%' encodes to '%25'. Decoding once yields '%'; twice would corrupt it. */
    public function testALiteralPercentInAPasswordSurvives(): void
    {
        $this->assertEquals('100%sure', (new DatabaseUrl('postgres://u:100%25sure@h:5432/db'))->password);
    }

    /**
     * The end-to-end proof: '%61' decodes to 'a', so the encoded form spells the
     * SAME password. It connects only if the credential path decodes.
     */
    public function testAnEncodedPasswordConnectsToALiveDatabase(): void
    {
        $url = trim((string) (getenv('TINA4_TEST_PG_URL') ?: ''));
        if ($url === '') {
            $this->markTestSkipped('live PostgreSQL not configured (TINA4_TEST_PG_URL)');
        }
        $raw = trim((string) (getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4'));
        if (!str_contains($raw, 'a')) {
            $this->markTestSkipped("password has no 'a' to encode as %61");
        }

        $user = trim((string) (getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4'));
        [$scheme, $rest] = explode('://', $url, 2);
        $parts = explode('@', $rest);
        $tail = end($parts);
        $encoded = preg_replace('/a/', '%61', $raw, 1);

        $db = Database::create("{$scheme}://{$user}:{$encoded}@{$tail}");
        $this->assertIsBool($db->tableExists('tina4_write_contract'));
    }
}
