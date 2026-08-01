<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DotEnv;

/**
 * The shared .env corpus (feature 1 of the feature audit).
 *
 * `tests/fixtures/dotenv_corpus.json` is byte-identical in all four frameworks.
 * One answer key, four suites: a line that parses here and differently in Ruby
 * is a parity bug with a name, not a difference somebody has to notice.
 *
 * PHP was the framework the other three adopted interpolation FROM, and running
 * the corpus is what surfaced the one bug in the half nobody had exercised: an
 * unresolved reference resolved to the empty string.
 *
 * Real files on disk in a temp directory, real process environment. A .env is a
 * file, so the real dependency is trivially available and there is nothing to mock.
 */
class DotEnvCorpusTest extends TestCase
{
    private static array $corpus;
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$corpus = json_decode(
            file_get_contents(__DIR__ . '/fixtures/dotenv_corpus.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-dotenv-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/.env', self::$corpus['env_file']);

        // Loading is FIRST-WINS, so a key left over from another test would mask
        // the file and quietly pass a test that proves nothing.
        foreach (array_merge(array_keys(self::$corpus['expected']), self::$corpus['_never_set']['keys']) as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function load(string $file = '.env'): void
    {
        DotEnv::loadEnv($this->dir . '/' . $file);
    }

    public function testEveryKeyParsesToTheAgreedValue(): void
    {
        $this->load();
        $failures = [];
        foreach (self::$corpus['expected'] as $key => $want) {
            $got = getenv($key);
            if ($got !== $want) {
                $failures[] = sprintf('%s: got %s, want %s', $key, var_export($got, true), var_export($want, true));
            }
        }
        $this->assertSame([], $failures, "corpus mismatches:\n" . implode("\n", $failures));
    }

    public function testLoadEnvReadsAnExportPrefixedLine(): void
    {
        $this->load();
        $this->assertSame('shellstyle', getenv('EXPORTED'));
    }

    /** Absent is the failure mode that hid this in Ruby for so long. */
    public function testLoadEnvDoesNotSilentlySkipAnExportLine(): void
    {
        $this->load();
        $this->assertNotFalse(getenv('EXPORTED'));
    }

    public function testLoadEnvStripsATrailingCommentFromAnUnquotedValue(): void
    {
        $this->load();
        $this->assertSame('value', getenv('WITH_HASH'));
    }

    public function testLoadEnvDoesNotKeepTheCommentInTheValue(): void
    {
        $this->load();
        $this->assertStringNotContainsString('#', (string) getenv('WITH_HASH'));
    }

    public function testLoadEnvKeepsAHashInsideAQuotedValue(): void
    {
        $this->load();
        $this->assertSame('a # b', getenv('QUOTED_HASH'));
    }

    public function testLoadEnvDoesNotTruncateAQuotedValueAtAHash(): void
    {
        $this->load();
        $this->assertStringEndsWith('b', (string) getenv('QUOTED_HASH'));
    }

    public function testLoadEnvExpandsADollarBraceReference(): void
    {
        $this->load();
        $this->assertSame('example.com/api', getenv('INTERP'));
        $this->assertSame('example.com/v2', getenv('DQ_INTERP'));
    }

    /**
     * Single quotes are the documented escape for a literal ${...}, and the
     * migration path for the breaking half of this change.
     */
    public function testLoadEnvDoesNotExpandInsideSingleQuotes(): void
    {
        $this->load();
        $this->assertSame('${HOST}/api', getenv('LITERAL'));
    }

    public function testLoadEnvLeavesAnUnknownReferenceLiteral(): void
    {
        $this->load();
        $this->assertSame('${NOPE}/x', getenv('UNKNOWN'));
    }

    /**
     * The negative half, and the bug this row found in PHP itself: an unresolved
     * reference used to resolve to the empty string, so `URL=${DB_HOST}/db` with
     * a typo'd or unset DB_HOST silently became `/db` - a plausible-looking
     * wrong value that reaches a connection attempt before failing.
     */
    public function testLoadEnvDoesNotResolveAnUnknownReferenceToNothing(): void
    {
        $this->load();
        $this->assertNotSame('/x', getenv('UNKNOWN'));
    }

    public function testLoadEnvSetsAnEmptyStringForABareEquals(): void
    {
        $this->load();
        $this->assertSame('', getenv('EMPTY'));
    }

    /** An empty value IS a value. Absent and blank are different things. */
    public function testLoadEnvDoesNotUnsetAKeyDeclaredEmpty(): void
    {
        $this->load();
        $this->assertNotFalse(getenv('EMPTY'));
    }

    public function testADoubleQuotedValueProcessesEscapes(): void
    {
        $this->load();
        $this->assertSame("line1\nline2\ttabbed", getenv('ESCAPES'));
    }

    /**
     * The malformed lines sit in the MIDDLE of the fixture, so keys declared
     * after them must still load and the bad keys must not exist.
     */
    public function testLoadEnvDoesNotAbortTheWholeFileOnOneBadLine(): void
    {
        $this->load();
        $this->assertSame("line1\nline2\ttabbed", getenv('ESCAPES'));
        foreach (self::$corpus['_never_set']['keys'] as $key) {
            $this->assertFalse(getenv($key), "{$key} should never be set");
        }
    }

    public function testWhitespaceAroundAKeyIsTrimmed(): void
    {
        $this->load();
        $this->assertSame('spaced', getenv('SPACED_KEY'));
    }

    // ── precedence: real environment > .env.local > .env ──────

    public function testEnvLocalOverridesEnv(): void
    {
        $p = self::$corpus['precedence'];
        foreach (array_keys($p['expected_without_real_env']) as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        file_put_contents($this->dir . '/.env', $p['env']);
        file_put_contents($this->dir . '/.env.local', $p['env_local']);

        $this->load('.env.local');
        $this->load('.env');

        foreach ($p['expected_without_real_env'] as $key => $want) {
            $this->assertSame($want, getenv($key), $key);
        }
    }

    /**
     * A stray gitignored .env.local must never clobber a production value. This
     * is the security-correct ordering, not a convenience.
     */
    public function testLoadEnvDoesNotOverwriteAnExistingProcessVariable(): void
    {
        $p = self::$corpus['precedence'];
        $real = $p['real_env_wins'];
        putenv("{$real['key']}={$real['value']}");

        file_put_contents($this->dir . '/.env', $p['env']);
        file_put_contents($this->dir . '/.env.local', $p['env_local']);

        $this->load('.env.local');
        $this->load('.env');

        $this->assertSame($real['value'], getenv($real['key']));
        putenv($real['key']);
    }

    /**
     * One truthiness table, every subsystem, every framework.
     *
     * The parser is only half the contract - the other half is what a parsed
     * value MEANS as a boolean. It was not one table: Ruby's Env::bool also
     * accepted y/t/n/f while its own Log and Mcp checks did not, so one .env
     * gave two answers in one process. PHP was already on the canonical set;
     * this pins it so it stays there.
     */
    public function testCorpusTruthyValuesAreTruthy(): void
    {
        foreach (self::$corpus['truthiness']['truthy'] as $value) {
            $this->assertTrue(DotEnv::isTruthy($value), "isTruthy failed for " . var_export($value, true));
        }
    }

    public function testCorpusFalsyValuesAreFalsy(): void
    {
        foreach (self::$corpus['truthiness']['falsy'] as $value) {
            $this->assertFalse(DotEnv::isTruthy($value), "isTruthy wrongly true for " . var_export($value, true));
        }
    }
}
