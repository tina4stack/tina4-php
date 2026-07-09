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
    /** @var array<string, string> Maps URL scheme to Tina4 driver class names */
    private const DRIVER_MAP = [
        'sqlite' => 'DataSQLite3',
        'postgres' => 'DataPostgresql',
        'postgresql' => 'DataPostgresql',
        'pgsql' => 'DataPostgresql',
        'mysql' => 'DataMySQL',
        'mssql' => 'DataMSSQL',
        'sqlserver' => 'DataMSSQL',
        'firebird' => 'DataFirebird',
    ];

    public readonly string $driver;
    public readonly string $host;
    public readonly int $port;
    public readonly string $database;
    public readonly string $username;
    public readonly string $password;
    public readonly string $scheme;

    /**
     * Parse a DATABASE_URL string.
     *
     * @param string $url The database URL to parse
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public function __construct(string $url)
    {
        // Handle sqlite special cases — :memory: check must come first
        if ($url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            $this->scheme = 'sqlite';
            $this->driver = self::DRIVER_MAP['sqlite'];
            $this->host = '';
            $this->port = 0;
            $this->database = ':memory:';
            $this->username = '';
            $this->password = '';
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

            $this->scheme = 'sqlite';
            $this->driver = self::DRIVER_MAP['sqlite'];
            $this->host = '';
            $this->port = 0;
            // Absolute vs relative is decided by the adapter at connect time
            // (a leading "/" or a Windows drive letter → absolute).
            $this->database = $rest;
            $this->username = '';
            $this->password = '';
            return;
        }

        // Parse standard URL format
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException("DatabaseUrl: Invalid URL format '{$url}'");
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::DRIVER_MAP[$scheme])) {
            throw new \InvalidArgumentException(
                "DatabaseUrl: Unsupported database scheme '{$scheme}'. Supported: " .
                implode(', ', array_keys(self::DRIVER_MAP))
            );
        }

        $this->scheme = $scheme;
        $this->driver = self::DRIVER_MAP[$scheme];
        $this->host = $parts['host'] ?? 'localhost';
        $this->port = $parts['port'] ?? $this->defaultPort($scheme);
        $this->username = isset($parts['user']) ? urldecode($parts['user']) : '';
        $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : '';

        // Database name is the path without leading slash
        $path = $parts['path'] ?? '';
        $this->database = ltrim($path, '/');
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
        return 'Tina4\\' . $this->driver;
    }

    /**
     * Get a DSN-style connection string for the database.
     */
    public function getDsn(): string
    {
        if ($this->scheme === 'sqlite' || false /* sqlite3 alias removed */) {
            return $this->database;
        }

        $dsn = $this->host;

        if ($this->port > 0) {
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
        if ($this->scheme === 'sqlite' || false /* sqlite3 alias removed */) {
            return "sqlite:///{$this->database}";
        }

        $url = $this->scheme . '://';

        if ($this->username !== '') {
            $url .= $this->username;
            if ($this->password !== '') {
                $url .= ':***';
            }
            $url .= '@';
        }

        $url .= $this->host;

        if ($this->port > 0) {
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
    private function defaultPort(string $scheme): int
    {
        return match ($scheme) {
            'postgres', 'postgresql' => 5432,
            'mysql' => 3306,
            'mssql', 'sqlserver' => 1433,
            'firebird' => 3050,
            default => 0,
        };
    }
}
