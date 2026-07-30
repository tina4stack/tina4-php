<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency .env file parser.
 * Loads environment variables from a .env file into $_ENV and putenv().
 */
class DotEnv
{
    /** @var array<string, string> Parsed variables */
    private static array $variables = [];

    /** @var bool Whether a file has been loaded */
    private static bool $loaded = false;

    /**
     * Load and parse a .env file.
     *
     * Supports:
     * - KEY=value
     * - KEY="double quoted"
     * - KEY='single quoted'
     * - export KEY=value
     * - # comments
     * - empty lines
     * - interpolation of ${VAR} references within double-quoted values
     *
     * @param string $path Path to the .env file
     * @param bool $overwrite Whether to overwrite existing env vars
     * @return void
     * @throws \RuntimeException If the file cannot be read
     */
    public static function loadEnv(string $path = '.env', bool $overwrite = false): void
    {
        // One warning per unresolved name PER LOAD, not per process.
        self::$warnedRefs = [];

        // Allow operators to redirect the default '.env' lookup via the
        // TINA4_ENV_FILE process-level env var (only when caller didn't
        // pass an explicit, non-default path). Lets containers point at
        // /run/secrets/.env or similar without code changes.
        if ($path === '.env') {
            $envOverride = getenv('TINA4_ENV_FILE');
            if ($envOverride !== false && $envOverride !== '') {
                $path = $envOverride;
            }
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("DotEnv: Cannot read file '{$path}'");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \RuntimeException("DotEnv: Failed to read file '{$path}'");
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Skip section headers like [Project Settings]
            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                continue;
            }

            // Strip "export " prefix
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            // Find the first = sign
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Skip invalid keys
            if ($key === '') {
                continue;
            }

            // Parse the value
            $value = self::parseValue($value);

            // Set only if not already defined or overwrite is true (first-wins
            // when overwrite is false). The internal registry must track the
            // SAME precedence as the real process env — storing every parsed
            // value unconditionally would make getEnv() return the last-loaded
            // value and silently defeat the real-env > .env.local > .env order.
            if ($overwrite || (!isset($_ENV[$key]) && getenv($key) === false)) {
                self::setVariable($key, $value);
                self::$variables[$key] = $value;
            } elseif (!isset(self::$variables[$key])) {
                // Key already present in the real env but not yet mirrored into
                // our registry — record the winning (real) value so getEnv()'s
                // registry-first lookup stays consistent with getenv()/$_ENV.
                $existing = $_ENV[$key] ?? getenv($key);
                if ($existing !== false) {
                    self::$variables[$key] = (string) $existing;
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Parse a value string, handling quotes and inline comments.
     */
    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];

        // Double-quoted value
        if ($firstChar === '"') {
            $endQuote = strpos($value, '"', 1);
            if ($endQuote !== false) {
                $value = substr($value, 1, $endQuote - 1);
                // Process escape sequences in double-quoted strings
                $value = str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    $value
                );
                // Interpolate ${VAR} references
                $value = self::interpolate($value);
                return $value;
            }
        }

        // Single-quoted value (no escaping, no interpolation)
        if ($firstChar === "'") {
            $endQuote = strpos($value, "'", 1);
            if ($endQuote !== false) {
                return substr($value, 1, $endQuote - 1);
            }
        }

        // Unquoted value: strip inline comments
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = rtrim(substr($value, 0, $commentPos));
        }

        // Interpolate ${VAR} references in unquoted values
        $value = self::interpolate($value);

        return $value;
    }

    /**
     * Names already warned about as unresolved, so one typo warns once per load.
     *
     * @var array<string, bool>
     */
    private static array $warnedRefs = [];

    /**
     * Interpolate ${VAR} references in a value string.
     *
     * An UNRESOLVED name is left LITERAL and warned about once. It used to
     * resolve to the empty string, so `URL=${DB_HOST}/db` with a typo'd or
     * unset DB_HOST silently became `/db` - a plausible-looking wrong value
     * that reaches a connection attempt before failing, rather than a visible
     * one. This is the cross-framework behaviour table (feature 1 of the
     * feature audit); the other three leave it literal.
     */
    private static function interpolate(string $value): string
    {
        return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)}/', function (array $matches): string {
            $name = $matches[1];
            $resolved = self::getEnv($name, null);
            if ($resolved !== null) {
                return $resolved;
            }
            if (!isset(self::$warnedRefs[$name])) {
                self::$warnedRefs[$name] = true;
                self::warnEnv("\${{$name}} is not set, left as-is");
            }
            return $matches[0];
        }, $value) ?? $value;
    }

    /**
     * Emit a parse warning. DotEnv loads before the logger exists, so stderr.
     */
    private static function warnEnv(string $message): void
    {
        fwrite(STDERR, "[tina4] {$message}\n");
    }

    /**
     * Set a variable in $_ENV and putenv().
     */
    private static function setVariable(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Get an environment variable value.
     *
     * @param string $key The variable name
     * @param string|null $default Default value if not found
     * @return string|null
     */
    public static function getEnv(string $key, ?string $default = null): ?string
    {
        // Check our internal store first
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        // Then check $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Then check getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Require that an environment variable is set.
     *
     * @param string $key The variable name
     * @return string The value
     * @throws \RuntimeException If the variable is not set
     */
    public static function requireEnv(string $key): string
    {
        $value = self::getEnv($key);

        if ($value === null) {
            throw new \RuntimeException("DotEnv: Required environment variable '{$key}' is not set");
        }

        return $value;
    }

    /**
     * Check if a variable has been loaded.
     */
    public static function hasEnv(string $key): bool
    {
        return self::getEnv($key) !== null;
    }

    /**
     * Reset the internal state (useful for testing).
     */
    public static function resetEnv(): void
    {
        self::$variables = [];
        self::$loaded = false;
    }

    /**
     * Get all loaded variables.
     *
     * @return array<string, string>
     */
    public static function allEnv(): array
    {
        return self::$variables;
    }

    /**
     * Check if a value is truthy for env boolean checks.
     *
     * Accepts: "true", "True", "TRUE", "1", "yes", "Yes", "YES", "on", "On", "ON".
     * Everything else is falsy (including empty string, null, not set).
     *
     * @param string|null $val The value to check
     * @return bool
     */
    public static function isTruthy(?string $val): bool
    {
        return in_array(strtolower(trim($val ?? '')), ['true', '1', 'yes', 'on']);
    }
}
