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
class DatabaseUrl
{
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
            throw new \InvalidArgumentException("DatabaseUrl: Invalid URL format '{$url}'");
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
