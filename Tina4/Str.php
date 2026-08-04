<?php

namespace Tina4;

/**
 * UTF-8 string helpers that do not require ext-mbstring.
 *
 * WHY THIS EXISTS
 *
 * composer.json requires only ext-json - that is v3's zero-runtime-dependency
 * promise - and ext-mbstring is NOT enabled in a stock php-src build. Every
 * unguarded mb_* call is therefore a fatal "call to undefined function" on an
 * ordinary PHP, not a rare edge case.
 *
 * The measured one that forced this: Log::coerceMessage() called
 * mb_check_encoding(), and Log::error() is what Session::safeWrite() calls when
 * a session backend fails. So a DEGRADABLE backend failure became a FATAL that
 * hid its own cause - the operator saw the undefined-function error instead of
 * the Redis/Mongo failure underneath.
 *
 * Declaring ext-mbstring in composer.json was the alternative and was rejected:
 * it turns a suggested extension into a hard requirement and breaks the
 * zero-dependency promise. ext-openssl is already handled this way - suggested,
 * checked at the point of use - and this follows that precedent.
 *
 * mbstring is still PREFERRED wherever it is present: it is faster, and it is
 * the only fully Unicode-correct option for case conversion. The fallback is
 * ext-pcre, which cannot be disabled in PHP.
 */
class Str
{
    /**
     * True when the bytes are valid UTF-8.
     *
     * @param string $value Bytes to validate.
     * @return bool True when the value is well-formed UTF-8.
     */
    public static function isUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }
        // The empty pattern with /u makes PCRE validate the subject as UTF-8 and
        // fail on malformed bytes. This is the canonical mbstring-free check.
        return $value === '' || preg_match('//u', $value) === 1;
    }

    /**
     * Length in CHARACTERS, not bytes.
     *
     * @param string $value String to measure.
     * @return int Number of UTF-8 characters.
     */
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }
        $count = preg_match_all('/./us', $value);
        // preg_match_all returns false on malformed input; the byte length is the
        // honest floor there rather than a silent 0.
        return $count === false ? strlen($value) : $count;
    }

    /**
     * Substring counted in CHARACTERS, so a multi-byte glyph is never split.
     *
     * @param string   $value  Source string.
     * @param int      $start  Character offset to start at.
     * @param int|null $length Characters to take, or null for the remainder.
     * @return string The extracted substring.
     */
    public static function substr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length);
        }
        $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return $length === null ? substr($value, $start) : substr($value, $start, $length);
        }
        return implode('', array_slice($parts, $start, $length));
    }

    /**
     * Title-case each word.
     *
     * DEGRADATION, stated rather than hidden: without ext-mbstring the
     * word-initial letter is uppercased with ASCII rules, so an initial
     * non-ASCII letter (e.g. a leading accented character) is left as-is
     * instead of being uppercased. Everything else - word splitting, the
     * lowercasing of the remainder - is Unicode-correct via PCRE. A degraded
     * but working render beats a fatal, and mbstring is used whenever present.
     *
     * @param string $value String to title-case.
     * @return string The title-cased string.
     */
    public static function titleCase(string $value): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($value, MB_CASE_TITLE);
        }
        $lowered = self::lower($value);
        $result = preg_replace_callback(
            '/(?<![\p{L}\p{N}])\p{L}/u',
            static fn(array $m): string => strtoupper($m[0]),
            $lowered
        );
        return $result ?? $lowered;
    }

    /**
     * Lowercase.
     *
     * Without ext-mbstring this is ASCII-only, matching PHP's own strtolower.
     *
     * @param string $value String to lowercase.
     * @return string The lowercased string.
     */
    public static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}
