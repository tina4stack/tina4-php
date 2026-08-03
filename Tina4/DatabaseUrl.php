<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Parses DATABASE_URL connection strings into structured connection parameters.
 *
 * Supported formats:
 *   sqlite:///path/to/database.db
 *   sqlite::memory:
 *   postgres://user:pass@host:port/dbname
 *   postgresql://user:pass@host:port/dbname
 *   mysql://user:pass@host:port/dbname
 *   mssql://user:pass@host:port/dbname
 *   firebird://user:pass@host:port/path/to/database.fdb
 */
class DatabaseUrl implements \JsonSerializable
{
    /**
     * What a secret is replaced by, everywhere. One spelling so a test can pin
     * it and a reader can recognise a redacted value at a glance.
     */
    public const REDACTED = '***';

    /**
     * @var array<string, string> URL scheme to CANONICAL engine name.
     *
     * Alias resolution happens ONCE, here, at parse time. Everything downstream
     * sees the canonical name, so `postgresql://`, `pgsql://` and `postgres://`
     * are the same connection as far as the rest of the framework is concerned.
     */
    private const ENGINE_ALIASES = [
        'sqlite' => 'sqlite',
        'sqlite3' => 'sqlite',
        'postgres' => 'postgres',
        'postgresql' => 'postgres',
        'pgsql' => 'postgres',
        'mysql' => 'mysql',
        'mssql' => 'mssql',
        'sqlserver' => 'mssql',
        'firebird' => 'firebird',
    ];

    /** @var array<string, string> Canonical engine to Tina4 adapter class. */
    private const ENGINE_DRIVER_CLASS = [
        'sqlite' => 'DataSQLite3',
        'postgres' => 'DataPostgresql',
        'mysql' => 'DataMySQL',
        'mssql' => 'DataMSSQL',
        'firebird' => 'DataFirebird',
    ];

    /**
     * The canonical engine name: sqlite, postgres, mysql, mssql or firebird.
     *
     * Breaking (feature 5): replaces BOTH `$driver` and `$scheme`. `$driver` held
     * an internal class name (`DataPostgresql`, `DataSQLite3`) on a public
     * readonly property, leaking implementation into the value; `$scheme` held
     * the RAW scheme, so `pgsql://` and `postgres://` compared unequal for the
     * same connection. The adapter class is now looked up FROM the engine by
     * getDriverClass(); a public property never holds a class name.
     */
    public readonly string $engine;

    /** Null for sqlite - a file has no host. */
    public readonly ?string $host;

    /** Null for sqlite. Otherwise always set: the engine default applies at parse. */
    public readonly ?int $port;

    public readonly string $database;

    /** Null when absent, never an empty string - absent and blank are different. */
    public readonly ?string $username;
    public readonly ?string $password;

    /**
     * Parse a DATABASE_URL string.
     *
     * @param string $url The database URL to parse
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public function __construct(string $url)
    {
        // Handle sqlite special cases — :memory: check must come first
        // `sqlite3:` is accepted input and normalises to `sqlite:`. The driver is
        // literally named sqlite3 in every framework (Python's sqlite3 module,
        // Ruby's sqlite3 gem, PHP's ext-sqlite3, Node's node:sqlite), so people
        // type it. The "3" is the file-format version, not a different engine, so
        // the canonical ENGINE name stays `sqlite` and only the input is widened.
        if (str_starts_with($url, 'sqlite3:')) {
            $url = 'sqlite:' . substr($url, 8);
        }

        if ($url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            $this->engine = 'sqlite';
            $this->host = null;
            $this->port = null;
            $this->database = ':memory:';
            $this->username = null;
            $this->password = null;
            return;
        }

        if (str_starts_with($url, 'sqlite:')) {
            // Strip the sqlite scheme on the RAW string (mirrors tina4-python/ruby/nodejs).
            // parse_url() collapses "sqlite:/x" and "sqlite:///x", losing the distinction
            // between a one-slash ABSOLUTE path and the documented three-slash RELATIVE form —
            // that was the "sqlite:<abspath> silently goes relative" footgun.
            //   sqlite:///app.db        → "app.db"       (three slashes = relative to cwd)
            //   sqlite:///data/app.db   → "data/app.db"
            //   sqlite:////abs/app.db   → "/abs/app.db"  (four slashes = absolute)
            //   sqlite:///C:/Users/app  → "C:/Users/app" (Windows absolute)
            //   sqlite:/abs/app.db      → "/abs/app.db"  (one slash = a real absolute path)
            //   sqlite://rel/app.db     → "rel/app.db"   (two-slash legacy = relative)
            //   sqlite:app.db           → "app.db"
            if (str_starts_with($url, 'sqlite:///')) {
                $rest = substr($url, 10);
            } elseif (str_starts_with($url, 'sqlite://')) {
                $rest = substr($url, 9);
            } else {
                $rest = substr($url, 7); // "sqlite:"
            }

            $this->engine = 'sqlite';
            $this->host = null;
            $this->port = null;
            // Absolute vs relative is decided by the adapter at connect time
            // (a leading "/" or a Windows drive letter → absolute).
            $this->database = $rest;
            $this->username = null;
            $this->password = null;
            return;
        }

        // Parse standard URL format
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            // NEVER interpolate the raw URL. Measured 2026-08-02 in all four
            // frameworks: a malformed TINA4_DATABASE_URL put the PASSWORD into
            // this message verbatim, and this message reaches the boot log, the
            // error overlay, a crash report and a CI log. redact() keeps the
            // scheme, user, host and port - everything needed to see WHICH
            // character is wrong - and drops the secret.
            throw new \InvalidArgumentException(
                'DatabaseUrl: Invalid URL format ' . self::redact($url)
                . " - expected 'engine://user:password@host:port/database'."
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::ENGINE_ALIASES[$scheme])) {
            throw new \InvalidArgumentException(
                "DatabaseUrl: Unsupported database scheme '{$scheme}'. Supported: " .
                implode(', ', array_keys(self::ENGINE_ALIASES))
            );
        }

        $this->engine = self::ENGINE_ALIASES[$scheme];
        $this->host = $parts['host'] ?? 'localhost';
        $this->port = $parts['port'] ?? $this->defaultPort($this->engine);
        $this->username = isset($parts['user']) ? urldecode($parts['user']) : null;
        $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : null;

        // Strip EXACTLY ONE leading slash - the URL path separator - never all of
        // them. `ltrim($path, '/')` ate every slash, which turned the documented
        // absolute Firebird form `firebird://host:3050//var/lib/db.fdb` into the
        // RELATIVE `var/lib/db.fdb`. Verified against live Firebird 5.0.4: the
        // driver accepts one or two leading slashes and rejects a relative path
        // outright, so the value we published would not open the database it
        // claimed to name. PHP still connected because the adapter rebuilt the
        // path downstream, which is exactly why nobody reported it.
        $path = $parts['path'] ?? '';
        $this->database = str_starts_with($path, '/') ? substr($path, 1) : $path;
    }

    /**
     * Parse a TINA4_DATABASE_URL from environment variable.
     *
     * @param string $envKey The environment variable name (default: TINA4_DATABASE_URL)
     * @return self|null Null if the env var is not set
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public static function fromEnv(string $envKey = 'TINA4_DATABASE_URL'): ?self
    {
        $url = DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        return new self($url);
    }

    /**
     * Get the fully qualified Tina4 driver class name.
     */
    public function getDriverClass(): string
    {
        return 'Tina4\\' . self::ENGINE_DRIVER_CLASS[$this->engine];
    }

    /**
     * Get a DSN-style connection string for the database.
     */
    public function getDsn(): string
    {
        if ($this->engine === 'sqlite') {
            return $this->database;
        }

        $dsn = (string) $this->host;

        if ($this->port !== null) {
            $dsn .= ':' . $this->port;
        }

        if ($this->database !== '') {
            $dsn .= '/' . $this->database;
        }

        return $dsn;
    }

    /**
     * Convert back to a URL string (password masked).
     */
    public function toSafeString(): string
    {
        if ($this->engine === 'sqlite') {
            return "sqlite:///{$this->database}";
        }

        $url = $this->engine . '://';

        if ($this->username !== null) {
            $url .= $this->username;
            if ($this->password !== null) {
                $url .= ':***';
            }
            $url .= '@';
        }

        $url .= (string) $this->host;

        if ($this->port !== null) {
            $url .= ':' . $this->port;
        }

        if ($this->database !== '') {
            $url .= '/' . $this->database;
        }

        return $url;
    }

    /**
     * Redact the credentials out of ANY connection string, in any shape.
     *
     * This is the single string-level redaction primitive for the framework.
     * `toSafeString()` is its struct-level twin (used when the URL parsed);
     * this one is what an ERROR path calls, because an error path by definition
     * holds a string that may not parse. Every message, log line and status
     * payload that has to name a connection goes through one of the two - the
     * measured failure was that the helper existed and NOTHING called it.
     *
     * Three shapes, because Tina4 accepts three:
     *   URL userinfo   postgres://user:secret@host  -> postgres://user:***@host
     *   libpq kv       password='se cret' / password=secret -> password=***
     *   ODBC           PWD=secret; / PWD={se;cret}  -> PWD=***;
     *
     * It FAILS CLOSED. A string with no recognisable structure is replaced
     * wholesale, because a malformed TINA4_DATABASE_URL such as
     * "notaurl-with-<password>" is ENTIRELY secret - that exact value put the
     * password into an exception message in all four frameworks.
     *
     * @param string $connectionString A URL, a libpq/ODBC DSN, or junk
     * @return string The same string with every credential replaced by ***
     */
    public static function redact(string $connectionString): string
    {
        $value = trim($connectionString);

        if ($value === '') {
            return '(empty)';
        }

        $looksLikeUrl = (bool) preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $value);
        $looksLikeDsn = str_contains($value, '=');

        if (!$looksLikeUrl && !$looksLikeDsn) {
            return '(redacted - unrecognised connection string)';
        }

        if ($looksLikeUrl) {
            // Userinfo runs to the LAST '@' before the path, so an unencoded
            // '@' or ':' inside the password cannot end the match early and
            // leave its tail exposed.
            $value = preg_replace_callback(
                '#^([a-zA-Z][a-zA-Z0-9+.\-]*://)([^/?\#]*)@#',
                static function (array $matches): string {
                    $userInfo = $matches[2];
                    $separator = strpos($userInfo, ':');
                    if ($separator === false) {
                        return $matches[1] . $userInfo . '@';
                    }
                    return $matches[1] . substr($userInfo, 0, $separator) . ':' . self::REDACTED . '@';
                },
                $value,
                1
            ) ?? $value;
        }

        // Quoted forms are matched FIRST and as whole units. The old PHP-only
        // redaction was /\bpassword=\S*/ , and \S* stops at the first SPACE, so
        // a password containing a space had its TAIL survive into the logged
        // message ("password=*** word"). A quoted libpq value, a double-quoted
        // value and an ODBC {braced} value all end on their closing delimiter,
        // never on a space.
        // The UNQUOTED alternative (last) keeps eating space-separated tokens
        // until one looks like the next `keyword=` - so an unquoted password
        // with a space is swallowed whole instead of leaving its tail behind.
        return preg_replace(
            '/\b(password|passwd|pwd)\s*=\s*('
            . '\'(?:\\\\.|[^\'\\\\])*\''            // libpq single-quoted, backslash-escaped
            . '|"[^"]*"'                            // double-quoted
            . '|\{[^}]*\}'                          // ODBC braced
            . '|[^;\s]*(?:\s+(?![A-Za-z_][A-Za-z0-9_]*\s*=)[^;\s]+)*'  // bare
            . ')/i',
            '$1=' . self::REDACTED,
            $value
        ) ?? $value;
    }

    /**
     * What print_r()/var_dump() show. The password is a secret in a dump too.
     *
     * Python guards the same object with __repr__ and Ruby with #inspect; PHP
     * had no guard at all, so `print_r($url)` printed "[password] => pass".
     *
     * @return array<string, mixed> The public state with the password masked
     */
    public function __debugInfo(): array
    {
        return [
            'engine' => $this->engine,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password === null ? null : self::REDACTED,
        ];
    }

    /**
     * What json_encode() emits. Tina4 auto-serialises a returned object to JSON,
     * so an un-guarded DatabaseUrl in a route response is a credential on the
     * wire; json_encode() emitted "password":"pass" before this.
     *
     * @return array<string, mixed> The public state with the password masked
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Get the default port for a database scheme.
     */
    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'postgres' => 5432,
            'mysql' => 3306,
            'mssql' => 1433,
            'firebird' => 3050,
            default => null,
        };
    }
}
