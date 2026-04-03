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
 *   pgsql://user:pass@host:port/dbname
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

        if (str_starts_with($url, 'sqlite:///')) {
            $this->scheme = 'sqlite';
            $this->driver = self::DRIVER_MAP['sqlite'];
            $this->host = '';
            $this->port = 0;
            $this->database = substr($url, 9); // Everything after sqlite://
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
     * Parse a DATABASE_URL from environment variable.
     *
     * @param string $envKey The environment variable name (default: DATABASE_URL)
     * @return self|null Null if the env var is not set
     * @throws \InvalidArgumentException If the URL format is invalid
     */
    public static function fromEnv(string $envKey = 'DATABASE_URL'): ?self
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
