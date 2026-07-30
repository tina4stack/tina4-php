<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * SQLite database adapter over the pdo_sqlite driver — the SILENT fallback
 * used by Database::create() when ext-sqlite3 (the \SQLite3 class) is absent.
 *
 * Externally indistinguishable from SQLite3Adapter: pdo_sqlite with
 * ATTR_STRINGIFY_FETCHES=false returns native int/float/string and raw BLOB
 * bytes (verified), lastInsertId is cast to int to match \SQLite3, and the
 * fail-loud execute()/fetch() contract is preserved. Constructor signature
 * mirrors SQLite3Adapter so the factory can swap them 1:1.
 */
class PdoSqliteAdapter implements DatabaseAdapter
{
    use AutocommitTrait;

    use SqlNormalizerTrait;
    use PdoAdapterTrait;

    /** Resolved database path (after cwd-relative resolution). */
    private string $database;

    /**
     * @param string $database Path to the SQLite database file, or ":memory:"
     * @param bool|null $autoCommit Auto-commit (default from TINA4_AUTOCOMMIT)
     */
    public function __construct(
        string $database = ':memory:',
        ?bool $autoCommit = null,
    ) {
        $this->autoCommit = $this->resolveAutoCommit($autoCommit);
        $this->database = self::resolveDatabasePath($database);
        $this->open();
    }

    protected function engineLabel(): string
    {
        return 'SQLite (PDO)';
    }

    protected function buildDsn(): array
    {
        return ['sqlite:' . $this->database, '', '', []];
    }

    protected function onConnect(): void
    {
        // Match SQLite3Adapter: wait on write locks, enable WAL + foreign keys.
        try {
            $this->pdo->exec('PRAGMA busy_timeout=5000');
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
        }
    }

    /** \SQLite3::lastInsertRowID() returns an int — match it. */
    protected function normalizeReturnedId(mixed $value): int|string
    {
        return (int) $value;
    }

    protected function captureLastIdAfterWrite(string $sql): void
    {
        if ($this->isInsert($sql)) {
            $this->lastId = (int) $this->pdo->lastInsertId();
        }
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function tableExists(string $table): bool
    {
        // Honour an attached-database prefix ("attached.table"), matching
        // SQLite3Adapter — SQLite's "schema" is an ATTACH alias.
        [$schema, $tbl] = self::splitSchema($table);
        if ($schema !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            $rows = $this->query(
                "SELECT name FROM {$schema}.sqlite_master WHERE type='table' AND name = ?",
                [$tbl]
            );
        } else {
            $rows = $this->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$table]
            );
        }
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        [$schema, $tbl] = self::splitSchema($table);
        if ($schema !== null
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tbl)) {
            $rows = $this->query("PRAGMA {$schema}.table_info('{$tbl}')");
        } else {
            $rows = $this->query("PRAGMA table_info('{$table}')");
        }

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => (int) $row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'primaryKey' => (int) $row['pk'] > 0,
            ];
        }
        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        return array_column($rows, 'name');
    }

    /**
     * Resolve a SQLite path argument against the project root (cwd).
     * Identical to SQLite3Adapter::resolveDatabasePath so both adapters treat
     * a connection string the same way.
     */
    private static function resolveDatabasePath(string $dbPath): string
    {
        if ($dbPath === ':memory:') {
            return $dbPath;
        }

        $isUnixAbs = str_starts_with($dbPath, '/');
        $isWindowsAbs = (
            strlen($dbPath) >= 3
            && ctype_alpha($dbPath[0])
            && $dbPath[1] === ':'
            && ($dbPath[2] === '/' || $dbPath[2] === '\\')
        );

        if ($isUnixAbs || $isWindowsAbs) {
            return $dbPath;
        }

        $resolved = getcwd() . DIRECTORY_SEPARATOR . $dbPath;
        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }
        return $resolved;
    }
}
