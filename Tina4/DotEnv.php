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
     * @param string $path A root DIRECTORY (canonical) or a path to one .env file
     * @param bool $overwrite Whether to overwrite existing env vars
     * @return array<string,string> Keys the file(s) declared, mapped to the value
     *         that WON - not the value the file declared. The two differ exactly
     *         when the real environment beat the file, which is the case an
     *         operator most needs to see: reporting the file's value there would
     *         return "from_local" while the process runs on the real env var.
     * @throws \RuntimeException If a NAMED file cannot be read (the directory
     *         form tolerates an absent file)
     */
    public static function loadEnv(string $path = '.env', bool $overwrite = false): array
    {
        $effective = [];

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

        // A ROOT DIRECTORY is the canonical form in all four frameworks: it loads
        // <dir>/.env.local and then <dir>/.env, both first-wins, which IS the
        // documented precedence real-env > .env.local > .env.
        //
        // That ordering used to be the caller's job here and it was duplicated at
        // the boot site. Get it wrong (overwrite=true on .env.local) and a stray
        // gitignored file silently beats an explicitly-set production variable.
        //
        // The directory form TOLERATES a missing file - a fresh checkout has no
        // .env.local, and reading it unconditionally is the point. The file form
        // below still throws, because naming a file that is not there is a
        // caller error rather than an ordinary state.
        if (is_dir($path)) {
            $dir = rtrim($path, DIRECTORY_SEPARATOR);
            $local = self::loadIfPresent($dir . DIRECTORY_SEPARATOR . '.env.local', $overwrite);
            $main = self::loadIfPresent($dir . DIRECTORY_SEPARATOR . '.env', $overwrite);
            // .env.local wins on a duplicate key, so it is merged last.
            return array_merge($main, $local);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("DotEnv: Cannot read file '{$path}'");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new \RuntimeException("DotEnv: Failed to read file '{$path}'");
        }

        foreach ($lines as $index => $rawLine) {
            $lineNo = $index + 1;
            $line = trim($rawLine);

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

            // Find the first = sign. A line that sets nothing is warned about
            // by line number rather than dropped in silence.
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                self::warnEnv(sprintf('%s:%d: no \'=\' in "%s", line skipped', $path, $lineNo, $line));
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // A key that is not a valid identifier is skipped, by name and line.
            // PHP used to accept anything non-empty, so `1BAD=x` set a variable
            // named `1BAD` that no shell could ever export and no other
            // framework would have created - caught by the shared corpus.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                self::warnEnv(sprintf('%s:%d: invalid key "%s", line skipped', $path, $lineNo, $key));
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

            // The value in effect: the registry already holds the winner (the
            // file's value when it was applied, the real env's when it was not).
            $effective[$key] = self::$variables[$key] ?? $value;
        }

        self::$loaded = true;
        return $effective;
    }

    /**
     * Load one .env file only if it is there, for the directory form.
     *
     * A fresh checkout has no .env.local, and the directory form reads it
     * unconditionally, so absence is ordinary rather than a caller error.
     *
     * @param string $file      Absolute or relative path to a candidate .env file
     * @param bool   $overwrite Whether to overwrite existing env vars
     * @return array<string,string> Empty when the file is absent
     */
    private static function loadIfPresent(string $file, bool $overwrite): array
    {
        if (is_file($file) && is_readable($file)) {
            return self::loadEnv($file, $overwrite);
        }
        return [];
    }

    /**
     * Parse a value string, handling quotes and inline comments.
     */
    /**
     * Index of the quote that CLOSES a quoted value, or false if unterminated.
     *
     * Inside a double-quoted value a backslash escapes the next character, so a
     * \" is part of the value and must not end it. Single-quoted values are
     * verbatim (shell semantics), so nothing escapes there.
     *
     * @param string $value the raw value, starting at its opening quote
     * @param string $quote the opening quote character
     * @return int|false index of the closing quote, or false when unterminated
     */
    private static function findClosingQuote(string $value, string $quote)
    {
        $len = strlen($value);
        for ($i = 1; $i < $len; $i++) {
            if ($quote === '"' && $value[$i] === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($value[$i] === $quote) {
                return $i;
            }
        }
        return false;
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];

        // Double-quoted value.
        //
        // The terminator scan must SKIP a quote preceded by a backslash. A plain
        // strpos() stopped at the first ESCAPED quote, so DQ="say \"hi\"" was
        // truncated to `say \` -- and that made this class's own \" escape entry
        // below unreachable, because the value never contained one by the time
        // the replace ran. Python, Ruby and Node now use the identical scan.
        if ($firstChar === '"') {
            $endQuote = self::findClosingQuote($value, '"');
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
            $endQuote = self::findClosingQuote($value, "'");
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
    /**
     * Validate that required environment variables exist, and return them.
     *
     * Takes VARARGS and returns a map, matching Python, Ruby and Node. It used
     * to take one key and return that value, so checking five variables meant
     * five calls that each failed on the first problem - an operator fixing a
     * deployment got one name per restart instead of the whole list.
     *
     * @param string ...$keys Variable names that must be set
     * @return array<string,string> Every requested key mapped to its value
     * @throws \RuntimeException If any are missing, naming ALL of them
     */
    public static function requireEnv(string ...$keys): array
    {
        $missing = [];
        $found = [];

        foreach ($keys as $key) {
            $value = self::getEnv($key);
            if ($value === null) {
                $missing[] = $key;
                continue;
            }
            $found[$key] = $value;
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'DotEnv: Missing required environment variables: ' . implode(', ', $missing)
            );
        }

        return $found;
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
