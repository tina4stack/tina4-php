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

            // Only set if not already defined or overwrite is true
            if ($overwrite || (!isset($_ENV[$key]) && getenv($key) === false)) {
                self::setVariable($key, $value);
            }

            // Always store in our internal registry
            self::$variables[$key] = $value;
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
     * Interpolate ${VAR} references in a value string.
     */
    private static function interpolate(string $value): string
    {
        return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)}/', function (array $matches): string {
            return self::getEnv($matches[1], '');
        }, $value) ?? $value;
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
