<?php

namespace Tina4;

use PHPUnit\Framework\TestCase;

/**
 * Tina4 core must work on a PHP with no ext-mbstring.
 *
 * WHY THIS EXISTS
 *
 * composer.json requires only ext-json - that is v3's zero-runtime-dependency
 * promise - and ext-mbstring is NOT enabled in a stock php-src build. Every
 * unguarded mb_* call is therefore a fatal "call to undefined function" on an
 * ordinary PHP, not a rare edge case.
 *
 * The measured one that forced the fix: Log::coerceMessage() called
 * mb_check_encoding(), and Log::error() is what Session::safeWrite() calls when
 * a session backend fails. So a DEGRADABLE backend failure became a FATAL that
 * hid its own cause - the operator saw the undefined-function error instead of
 * the Redis/Mongo failure underneath:
 *
 *     Error: Call to undefined function Tina4\mb_check_encoding()
 *       Log.php(441) <- Session.php(549) safeWrite <- Session.php(673)
 *
 * Every mb_* call in core now lives behind a function_exists() guard inside
 * Tina4\Str, which falls back to ext-pcre - the one text extension PHP cannot
 * disable.
 */
class LogWithoutMbstringTest extends TestCase
{
    /**
     * NEGATIVE, and the load-bearing case: no unguarded mb_* anywhere in core.
     *
     * This is a SOURCE check on purpose. Every machine this suite runs on has
     * mbstring - macOS compiles it in statically, so even `php -n` cannot remove
     * it - which means a purely behavioural test would exercise the mbstring
     * branch, report green, and let the fallback rot unnoticed. Reading the
     * source is the one check the host cannot fool.
     *
     * The scan covers the whole tree rather than one file, so a NEW unguarded
     * call in any future file is caught the day it lands.
     *
     * THE GUARD MUST NAME THE FUNCTION IT GUARDS. This started as "is there a
     * function_exists somewhere in the 220 characters above the call", which is
     * a proximity heuristic, and proximity is not a guard. MEASURED 2026-08-06
     * on the lab (Ubuntu 24.04.4 LTS x86_64, PHP 8.3.6) by injecting this exact
     * shape into Tina4/ContextChunker.php:
     *
     *     if (function_exists('mb_strtolower')) {
     *         $text = mb_strtolower($text, 'UTF-8');
     *     }
     *     $text = mb_substr($text, 0, 64);   // <- fatal without ext-mbstring
     *
     * the whole file scanned GREEN: the second call borrowed the first call's
     * guard simply by sitting near it. That is not a hypothetical shape - it is
     * what "add one more mb_ call to this block" produces. Matching the QUOTED
     * NAME inside the function_exists closes it, and costs one regex.
     *
     * The window is taken from the RAW source, not the blanked copy, because
     * that is where the quoted name survives; codeOnly() replaces each noise
     * BYTE with one space, so byte offsets are identical in both and the same
     * offset indexes both strings.
     *
     * KNOWN LIMIT, stated rather than implied: a call made through a callable
     * STRING (array_map('mb_strtolower', ...)) is invisible to this scan, since
     * codeOnly() blanks string literals - the very thing that stops the gate
     * flagging its own documentation. Core has none today.
     */
    public function testNoUnguardedMbstringCallAnywhereInCore(): void
    {
        $root = realpath(__DIR__ . '/../Tina4');
        $this->assertNotFalse($root, 'Tina4/ must exist');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $offenders = [];
        $guarded = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rawSource = file_get_contents($file->getPathname());
            if ($rawSource === false) {
                continue;
            }
            // Scan CODE only. A first cut regexed the raw source and flagged
            // "mb_check_encoding()" written in a docblock explaining this very
            // rule - the gate reporting its own documentation. PHP's tokenizer
            // is the precise instrument: blank out comments and string literals
            // (newlines preserved so line numbers stay true) and match on what
            // actually executes.
            $code = self::codeOnly($rawSource);
            if (!preg_match_all('/\bmb_[a-z_]+\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$call, $offset]) {
                $function = rtrim(substr($call, 0, -1));
                // A guard is a function_exists() naming THIS function, close
                // above the call or on the same line for the inline ternary.
                $window = substr($rawSource, max(0, $offset - 220), min(220, $offset));
                $guard = '/function_exists\s*\(\s*[\'"]' . preg_quote($function, '/') . '[\'"]/i';
                if (preg_match($guard, $window) === 1) {
                    $guarded++;
                    continue;
                }
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                $offenders[] = str_replace($root, 'Tina4', $file->getPathname()) . ":{$line} {$call}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Unguarded mb_* call(s) in core. On a PHP without ext-mbstring each is a fatal, "
            . "and inside an error path it hides the very failure it was called to report. "
            . "A function_exists() nearby that names a DIFFERENT function does not guard this "
            . "call. Use Tina4\\Str (isUtf8/length/substr/titleCase/lower) instead:\n  "
            . implode("\n  ", $offenders)
        );

        $this->assertGreaterThan(
            0,
            $guarded,
            'Expected core to still PREFER mbstring where present. Zero guarded calls means '
            . 'the helpers stopped using it at all - relax this deliberately if that was intended.'
        );
    }

    /**
     * Replace comments and string literals with equivalent whitespace.
     *
     * Newlines are kept so a reported line number still points at the real
     * line. Everything else becomes spaces, so a mention of mb_something() in
     * prose or inside a quoted string cannot be mistaken for a call.
     *
     * @param string $source PHP source.
     * @return string The same source with comments and strings blanked.
     */
    private static function codeOnly(string $source): string
    {

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            $isNoise = in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true);
            $out .= $isNoise ? preg_replace('/[^\n]/', ' ', $text) : $text;
        }

        return $out;
    }

    /**
     * POSITIVE: the helpers behave correctly.
     *
     * Pure functions over their inputs - no service, no double. Non-UTF-8 bytes
     * are DESCRIBED rather than dumped (raw bytes garble a terminal and can emit
     * escape sequences), valid UTF-8 passes through unchanged, and a long line is
     * cut on a CHARACTER boundary so a multi-byte glyph is never split in half.
     */
    public function testTheLoggerCoercesAndTruncatesCorrectly(): void
    {
        // Log's internal message coercion now lives in the private
        // messageToString()/normalize() pair (2026-08-13 logger contract
        // rewrite) -- proven end to end through the real public API and a
        // real sink rather than by reflecting a specific private method
        // name, so this survives future internal refactors of the shape.
        $tmp = sys_get_temp_dir() . '/tina4_mbstring_log_' . uniqid();
        mkdir($tmp, 0755, true);
        try {
            Log::reset();
            Log::configure(logDir: $tmp, output: 'file', format: 'json');

            Log::info("\xff\xfe bad");
            Log::info("hello \u{00e9}\u{4e16}");
            Log::info(str_repeat("\u{4e16}", 5000));

            $lines = array_values(array_filter(explode("\n", file_get_contents($tmp . '/tina4.log'))));
            $first = json_decode($lines[0], true);
            $second = json_decode($lines[1], true);
            $third = json_decode($lines[2], true);

            $this->assertMatchesRegularExpression(
                '/^<binary 6 bytes sha256=[0-9a-f]{64}>$/',
                $first['message'],
                'Non-UTF-8 input must be described by byte count + digest, never dumped'
            );
            $this->assertSame(
                "hello \u{00e9}\u{4e16}",
                $second['message'],
                'Valid UTF-8 passes through untouched, multi-byte included'
            );
            $this->assertSame(1, preg_match('//u', $lines[2]), 'the sink line itself must still be valid UTF-8');
        } finally {
            Log::reset();
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /**
     * POSITIVE: Str's own surface, including the two Frond relies on.
     *
     * titleCase is checked on ASCII because that is the part the fallback keeps
     * fully correct; its documented degradation is that a leading NON-ASCII
     * letter is not uppercased without mbstring. Asserting Unicode title-casing
     * here would pass on every machine we have and fail only in the field.
     */
    public function testStrHelpersMatchTheirMbstringMeaning(): void
    {
        $this->assertTrue(Str::isUtf8("caf\u{00e9}"));
        $this->assertFalse(Str::isUtf8("\xff\xfe"));
        $this->assertSame(0, Str::length(''));
        $this->assertSame(3, Str::length("\u{4e16}\u{754c}!"), 'Characters, not bytes');
        $this->assertSame("\u{4e16}\u{754c}", Str::substr("\u{4e16}\u{754c}!", 0, 2), 'Never splits a glyph');
        $this->assertSame('Hello World', Str::titleCase('hELLO wORLD'));
        $this->assertSame('abc', Str::lower('ABC'));
    }
}
