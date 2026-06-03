<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Typed environment-variable helpers — zero-deps.
 *
 * Reading env vars by hand gets old fast: every boolean flag becomes a
 * ``strtolower(getenv("TINA4_DEBUG")) === "true"`` incantation, every
 * numeric tuning knob needs an ``(int)`` cast with a sanity check.
 * Env centralises that. Same API across all four Tina4 frameworks
 * (``tina4_python.env``, ``Tina4::Env`` in Ruby, ``Env`` in Node).
 *
 *     use Tina4\Env;
 *
 *     $debug   = Env::bool('TINA4_DEBUG', default: false);
 *     $workers = Env::int('WORKERS', default: 4);
 *     $rate    = Env::float('RATE_LIMIT', default: 10.0);
 *     $region  = Env::str('AWS_REGION', default: 'us-east-1');
 *
 * Values are accepted case-insensitively after ``trim()``. Truthy:
 * ``1 / true / on / yes / y / t``. Falsy: ``0 / false / off / no / n / f``.
 * Any other value triggers the ``default``. Unparseable ints and floats
 * log a warning via ``Log`` and fall back to ``default`` — never throw.
 *
 * Reads via ``$_ENV[$name] ?? getenv($name)`` so tests that hydrate
 * ``$_ENV`` directly are honoured alongside real OS env vars.
 */
final class Env
{
    /** @var array<int,string> Case-insensitive (post-trim) truthy tokens. */
    private const TRUTHY = ['1', 'true', 'on', 'yes', 'y', 't'];

    /** @var array<int,string> Case-insensitive (post-trim) falsy tokens. Empty string is falsy. */
    private const FALSY = ['0', 'false', 'off', 'no', 'n', 'f', ''];

    /**
     * Raw lookup that honours both $_ENV and getenv() — returns null if unset.
     *
     * Tests typically poke $_ENV directly; production usually comes through
     * getenv(). Checking both keeps the behaviour consistent with DotEnv.
     */
    private static function read(string $name): ?string
    {
        if (array_key_exists($name, $_ENV)) {
            $value = $_ENV[$name];
            return $value === false ? null : (string) $value;
        }

        $value = getenv($name);
        return $value === false ? null : $value;
    }

    /**
     * Read $name and coerce to bool.
     *
     * Truthy tokens (case-insensitive after trim): 1, true, on, yes, y, t.
     * Falsy tokens: 0, false, off, no, n, f, empty string. Anything else
     * returns $default — never throws.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default;
        }
        $token = strtolower(trim($raw));
        if (in_array($token, self::TRUTHY, true)) {
            return true;
        }
        if (in_array($token, self::FALSY, true)) {
            return false;
        }
        return $default;
    }

    /**
     * Read $name and coerce to int. Returns $default on parse failure.
     *
     * Whitespace is stripped before parsing. Float strings like "3.14" are
     * rejected (matches Python's int() boundary) so the caller picks Env::int
     * vs Env::float deliberately.
     */
    public static function int(string $name, int $default = 0): int
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default;
        }
        $trimmed = trim($raw);
        // filter_var FILTER_VALIDATE_INT accepts optional sign and digits only.
        $parsed = filter_var($trimmed, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            self::logWarning(sprintf(
                "Env::int('%s'): could not parse %s as int — using default %d",
                $name,
                var_export($raw, true),
                $default
            ));
            return $default;
        }
        return $parsed;
    }

    /**
     * Read $name and coerce to float. Returns $default on parse failure.
     *
     * Whitespace is stripped before parsing. Accepts decimal and scientific
     * notation (e.g. "1.5e3"). Integers parse as floats (42 -> 42.0).
     */
    public static function float(string $name, float $default = 0.0): float
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default;
        }
        $trimmed = trim($raw);
        $parsed = filter_var($trimmed, FILTER_VALIDATE_FLOAT);
        if ($parsed === false) {
            self::logWarning(sprintf(
                "Env::float('%s'): could not parse %s as float — using default %s",
                $name,
                var_export($raw, true),
                var_export($default, true)
            ));
            return $default;
        }
        return (float) $parsed;
    }

    /**
     * Read $name as a string. Returns $default if unset.
     *
     * Whitespace is preserved — this is a pass-through for the raw env
     * value. Env::str('PATH') is exactly getenv('PATH') ?: '' with a more
     * discoverable name. An empty-string env value (FOO="") is preserved
     * as "" rather than swapped for $default — being set-but-empty differs
     * from being unset.
     */
    public static function str(string $name, string $default = ''): string
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default;
        }
        return $raw;
    }

    /**
     * Emit a warning via Log without raising if Log isn't available.
     *
     * Env is intentionally callable from very early bootstrap (before Log
     * is configured), so a missing or partially-loaded Log class must not
     * become a hard failure.
     */
    private static function logWarning(string $message): void
    {
        try {
            if (class_exists(Log::class)) {
                Log::warning($message);
            }
        } catch (\Throwable) {
            // Log not yet wired — silently skip. Same posture as Python's _log_warning.
        }
    }
}
