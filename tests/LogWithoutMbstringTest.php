<?php

namespace Tina4;

use PHPUnit\Framework\TestCase;

/**
 * The logger must work on a PHP with no ext-mbstring.
 *
 * WHY THIS EXISTS
 *
 * composer.json requires only ext-json - that is v3's zero-runtime-dependency
 * promise - and ext-mbstring is NOT enabled in a stock php-src build. Log
 * called mb_check_encoding(), mb_strlen() and mb_substr() unguarded, so on an
 * ordinary PHP every log write was a fatal "call to undefined function".
 *
 * The logger is the worst place for that. Log::error() is what
 * Session::safeWrite() calls when a session backend fails, so a DEGRADABLE
 * backend failure became a FATAL that hid its own cause - the operator saw
 * "Call to undefined function Tina4\mb_check_encoding()" instead of the real
 * Redis/Mongo error underneath. Measured stack, 2026-08-04:
 *
 *     Error: Call to undefined function Tina4\mb_check_encoding()
 *       Log.php(441) <- Session.php(549) safeWrite <- Session.php(673)
 *
 * Log's own docblock already promises it "must never be the reason a request
 * dies". These cases are what make that true rather than aspirational.
 */
class LogWithoutMbstringTest extends TestCase
{
    /**
     * NEGATIVE: no mb_* call may sit outside a function_exists() guard.
     *
     * This is the gate that survives the environment. Every machine this suite
     * runs on so far HAS mbstring - macOS has it compiled statically, so even
     * `php -n` cannot remove it - which means a purely behavioural test would
     * exercise the mbstring branch and report green while the fallback rots.
     * Reading the source is the one check that cannot be fooled by the host.
     */
    public function testNoUnguardedMbstringCallInTheLogger(): void
    {
        $source = file_get_contents(__DIR__ . '/../Tina4/Log.php');
        $this->assertNotFalse($source, 'Log.php must be readable');

        preg_match_all('/\bmb_[a-z_]+\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty(
            $matches[0],
            'Expected Log.php to still PREFER mbstring when present. If every mb_* call '
            . 'is gone this assertion is stale - but so is the risk, so relax it deliberately.'
        );

        foreach ($matches[0] as [$call, $offset]) {
            // The guard is the nearest preceding function_exists on the same helper.
            $before = substr($source, max(0, $offset - 220), min(220, $offset));
            $this->assertStringContainsString(
                'function_exists',
                $before,
                "Unguarded {$call} in Log.php at offset {$offset}. On a PHP without "
                . 'ext-mbstring this is a fatal, and inside Log it hides the error it '
                . 'was called to report.'
            );
        }
    }

    /**
     * POSITIVE: the coercion and truncation behaviour itself is correct.
     *
     * Pure functions over their inputs - no service, no double. Non-UTF-8 bytes
     * are DESCRIBED rather than dumped (raw bytes garble a terminal and can emit
     * escape sequences), valid UTF-8 passes through unchanged, and a long line is
     * cut on a CHARACTER boundary so a multi-byte glyph is never split in half.
     */
    public function testTheLoggerCoercesAndTruncatesCorrectly(): void
    {
        $coerce = new \ReflectionMethod(Log::class, 'coerceMessage');
        $truncate = new \ReflectionMethod(Log::class, 'truncateForStdout');

        $this->assertSame(
            '<binary 6 bytes>',
            $coerce->invoke(null, "\xff\xfe bad"),
            'Non-UTF-8 input must be described by byte count, never dumped'
        );

        $this->assertSame(
            "hello \u{00e9}\u{4e16}",
            $coerce->invoke(null, "hello \u{00e9}\u{4e16}"),
            'Valid UTF-8 passes through untouched, multi-byte included'
        );

        $long = str_repeat("\u{4e16}", 5000);
        $cut = $truncate->invoke(null, $long);

        $this->assertStringContainsString('(truncated, 5000 chars)', $cut, 'The count is in CHARACTERS, not bytes');
        $this->assertSame(
            1,
            preg_match('//u', $cut),
            'The truncated line must still be valid UTF-8 - a byte-based cut would '
            . 'split the last 3-byte glyph and produce a replacement character'
        );
    }
}
