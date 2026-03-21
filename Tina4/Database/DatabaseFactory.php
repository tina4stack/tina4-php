<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

use Tina4\DatabaseUrl;

/**
 * Factory for creating the correct DatabaseAdapter based on a connection string.
 *
 * Auto-detects the database type from a URL scheme and instantiates the
 * appropriate adapter. All database extensions are optional — clear errors
 * are thrown if the required extension is missing.
 *
 * Supported schemes:
 *   sqlite              => SQLite3Adapter
 *   postgres, postgresql => PostgresAdapter
 *   mysql               => MySQLAdapter
 *   mssql, sqlserver     => MSSQLAdapter
 *   firebird            => FirebirdAdapter
 */
class DatabaseFactory
{
    /** @var array<string, class-string<DatabaseAdapter>> Maps scheme to adapter class */
    private const ADAPTER_MAP = [
        'sqlite' => SQLite3Adapter::class,
        'postgres' => PostgresAdapter::class,
        'postgresql' => PostgresAdapter::class,
        'mysql' => MySQLAdapter::class,
        'mssql' => MSSQLAdapter::class,
        'sqlserver' => MSSQLAdapter::class,
        'firebird' => FirebirdAdapter::class,
    ];

    /**
     * Create a DatabaseAdapter from a connection URL string.
     *
     * @param string $url Connection URL (e.g. "sqlite::memory:", "pgsql://user:pass@host/db")
     * @param bool|null $autoCommit Override auto-commit setting
     * @return DatabaseAdapter
     * @throws \InvalidArgumentException If the URL scheme is unsupported
     * @throws \RuntimeException If the required PHP extension is missing
     */
    public static function create(string $url, ?bool $autoCommit = null, string $username = '', string $password = ''): DatabaseAdapter
    {
        // Handle SQLite special cases
        if ($url === ':memory:' || $url === 'sqlite::memory:' || $url === 'sqlite:///:memory:') {
            return new SQLite3Adapter(':memory:', $autoCommit);
        }

        if (str_starts_with($url, 'sqlite:///')) {
            $path = substr($url, 9);
            return new SQLite3Adapter($path, $autoCommit);
        }

        // For a bare file path ending in .db or .sqlite, assume SQLite
        if (!str_contains($url, '://') && preg_match('/\.(db|sqlite|sqlite3)$/i', $url)) {
            return new SQLite3Adapter($url, $autoCommit);
        }

        // Parse standard URL
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new \InvalidArgumentException(
                "DatabaseFactory: Cannot determine database type from '{$url}'. "
                . "Use a URL like 'postgres://user:pass@host/db' or 'sqlite:///path/to/db'."
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::ADAPTER_MAP[$scheme])) {
            throw new \InvalidArgumentException(
                "DatabaseFactory: Unsupported database scheme '{$scheme}'. "
                . 'Supported: ' . implode(', ', array_unique(array_keys(self::ADAPTER_MAP)))
            );
        }

        $adapterClass = self::ADAPTER_MAP[$scheme];

        return match ($adapterClass) {
            SQLite3Adapter::class => new SQLite3Adapter(
                ltrim($parts['path'] ?? ':memory:', '/'),
                $autoCommit,
            ),
            PostgresAdapter::class => new PostgresAdapter($url, $autoCommit, username: $username, password: $password),
            MySQLAdapter::class => new MySQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            MSSQLAdapter::class => new MSSQLAdapter($url, username: $username, password: $password, autoCommit: $autoCommit),
            FirebirdAdapter::class => new FirebirdAdapter(
                $url,
                username: $username !== '' ? $username : (isset($parts['user']) ? urldecode($parts['user']) : 'SYSDBA'),
                password: $password !== '' ? $password : (isset($parts['pass']) ? urldecode($parts['pass']) : 'masterkey'),
                autoCommit: $autoCommit,
            ),
        };
    }

    /**
     * Create a DatabaseAdapter from the DATABASE_URL environment variable.
     *
     * @param string $envKey Environment variable name (default: DATABASE_URL)
     * @param bool|null $autoCommit Override auto-commit setting
     * @return DatabaseAdapter|null Null if the env var is not set
     */
    public static function fromEnv(string $envKey = 'DATABASE_URL', ?bool $autoCommit = null): ?DatabaseAdapter
    {
        $url = \Tina4\DotEnv::getEnv($envKey);

        if ($url === null || $url === '') {
            return null;
        }

        $username = \Tina4\DotEnv::getEnv('DATABASE_USERNAME') ?? '';
        $password = \Tina4\DotEnv::getEnv('DATABASE_PASSWORD') ?? '';

        return self::create($url, $autoCommit, $username, $password);
    }

    /**
     * Get the list of supported database schemes.
     *
     * @return array<string>
     */
    public static function supportedSchemes(): array
    {
        return array_unique(array_keys(self::ADAPTER_MAP));
    }

    /**
     * Check if a scheme is supported.
     */
    public static function isSupported(string $scheme): bool
    {
        return isset(self::ADAPTER_MAP[strtolower($scheme)]);
    }
}
