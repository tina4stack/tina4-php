<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;

/**
 * Migration engine — reads SQL migration files and applies them in order.
 *
 * Migration files can use either naming pattern:
 *   - YYYYMMDDHHMMSS_description.sql  (timestamp-based)
 *   - 000001_description.sql          (sequential numbering)
 *
 * Both patterns sort alphabetically and work identically.
 * Rollback files use the same base name with a .down.sql extension:
 *   - YYYYMMDDHHMMSS_description.down.sql
 *   - 000001_description.down.sql
 *
 * Located in src/migrations/ by default.
 * Tracks applied migrations in the tina4_migration table.
 */
class Migration
{
    /** @var string Name of the migrations tracking table */
    private const MIGRATIONS_TABLE = 'tina4_migration';

    public function __construct(
        private readonly DatabaseAdapter $db,
        private readonly string $migrationsDir = 'src/migrations',
        private readonly string $delimiter = ';',
    ) {
        $this->ensureMigrationsTable();
    }

    /**
     * Run all pending migrations.
     *
     * @return array{applied: array<string>, skipped: array<string>, errors: array<string, string>}
     */
    public function migrate(): array
    {
        $applied = [];
        $skipped = [];
        $errors = [];

        $pending = $this->getPendingMigrations();

        if (empty($pending)) {
            return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
        }

        // Get the next batch number
        $batch = $this->getNextBatchNumber();

        foreach ($pending as $migration) {
            $fileName = basename($migration);
            $sql = file_get_contents($migration);

            if ($sql === false || trim($sql) === '') {
                $skipped[] = $fileName;
                continue;
            }

            $this->db->begin();

            try {
                // Split into individual statements
                $statements = $this->splitStatements($sql);

                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement === '') {
                        continue;
                    }

                    // Firebird lacks IF NOT EXISTS for ALTER TABLE ADD.
                    // Pre-check the system catalogue so duplicate columns are
                    // silently skipped instead of raising an error.
                    $skipReason = $this->shouldSkipForFirebird($statement);
                    if ($skipReason !== null) {
                        Log::info("Migration {$fileName}: {$skipReason}");
                        continue;
                    }

                    $result = $this->db->exec($statement);
                    if (!$result) {
                        $error = $this->db->error() ?? 'Unknown error';
                        throw new \RuntimeException("Migration failed: {$error}");
                    }
                }

                // Record the migration
                $this->recordMigration($fileName, $batch);
                $this->db->commit();
                $applied[] = $fileName;

                Log::info("Migration applied: {$fileName}");
            } catch (\Throwable $e) {
                $this->db->rollback();
                $errors[$fileName] = $e->getMessage();
                Log::error("Migration failed: {$fileName} — {$e->getMessage()}");
                // Stop on first error
                break;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Rollback the last batch of migrations.
     *
     * For each migration being rolled back:
     *   1. Look for a matching .down.sql file (e.g. 20240101000000_create_users.down.sql)
     *   2. If found, execute it before removing the tracking record
     *   3. If not found, log a warning but still remove the tracking record
     *
     * @return array{rolledBack: array<string>, errors: array<string, string>}
     */
    public function rollback(): array
    {
        $rolledBack = [];
        $errors = [];

        $lastBatch = $this->getLastBatchNumber();
        if ($lastBatch === 0) {
            return ['rolledBack' => $rolledBack, 'errors' => $errors];
        }

        $migrations = $this->getMigrationsForBatch($lastBatch);

        // Process in reverse order
        $migrations = array_reverse($migrations);

        foreach ($migrations as $migration) {
            $fileName = $migration['migration'];

            $this->db->begin();

            try {
                // Look for a .down.sql file
                $downFile = $this->getDownFilePath($fileName);

                if ($downFile !== null && file_exists($downFile)) {
                    $downSql = file_get_contents($downFile);

                    if ($downSql !== false && trim($downSql) !== '') {
                        $statements = $this->splitStatements($downSql);

                        foreach ($statements as $statement) {
                            $statement = trim($statement);
                            if ($statement === '') {
                                continue;
                            }

                            $result = $this->db->exec($statement);
                            if (!$result) {
                                $error = $this->db->error() ?? 'Unknown error';
                                throw new \RuntimeException("Rollback SQL failed: {$error}");
                            }
                        }

                        Log::info("Executed down migration: " . basename($downFile));
                    }
                } else {
                    Log::warning("No .down.sql file found for {$fileName}, removing tracking record only");
                }

                // Remove the migration record
                $this->db->exec(
                    "DELETE FROM " . self::MIGRATIONS_TABLE . " WHERE migration = :name",
                    [':name' => $fileName]
                );

                $this->db->commit();
                $rolledBack[] = $fileName;

                Log::info("Migration rolled back: {$fileName}");
            } catch (\Throwable $e) {
                $this->db->rollback();
                $errors[$fileName] = $e->getMessage();
                Log::error("Rollback failed: {$fileName} — {$e->getMessage()}");
                break;
            }
        }

        return ['rolledBack' => $rolledBack, 'errors' => $errors];
    }

    /**
     * Get migration status — lists completed and pending migrations.
     *
     * @return array{completed: array<int, array{migration: string, batch: int, applied_at: string}>, pending: array<string>}
     */
    public function status(): array
    {
        $completed = $this->getAppliedMigrations();

        $pendingFiles = $this->getPendingMigrations();
        $pending = array_map('basename', $pendingFiles);

        return [
            'completed' => $completed,
            'pending' => $pending,
        ];
    }

    /**
     * Get list of all applied migrations.
     *
     * @return array<int, array{migration: string, batch: int, applied_at: string}>
     */
    public function getAppliedMigrations(): array
    {
        return $this->db->query(
            "SELECT migration, batch, applied_at FROM " . self::MIGRATIONS_TABLE . " ORDER BY migration ASC"
        );
    }

    /**
     * Get the list of pending migration files.
     *
     * @return array<string> Full file paths
     */
    public function getPendingMigrations(): array
    {
        $allFiles = $this->getMigrationFiles();
        $appliedNames = array_column($this->getAppliedMigrations(), 'migration');

        $pending = [];
        foreach ($allFiles as $file) {
            if (!in_array(basename($file), $appliedNames, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * Get all migration files sorted by name (alphabetical/timestamp order).
     * Excludes .down.sql rollback files.
     *
     * Both YYYYMMDDHHMMSS_name.sql and 000001_name.sql patterns are supported
     * since they both sort correctly in alphabetical order.
     *
     * @return array<string> Full file paths
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false) {
            return [];
        }

        // Exclude .down.sql files — those are rollback scripts, not up migrations
        $files = array_filter($files, function (string $file): bool {
            return !str_ends_with($file, '.down.sql');
        });

        $files = array_values($files);
        sort($files);
        return $files;
    }

    /**
     * Create the migrations tracking table if it doesn't exist.
     */
    private function ensureMigrationsTable(): void
    {
        if (!$this->db->tableExists(self::MIGRATIONS_TABLE)) {
            $this->db->exec("
                CREATE TABLE " . self::MIGRATIONS_TABLE . " (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
    }

    /**
     * Record a migration as applied.
     */
    private function recordMigration(string $fileName, int $batch): void
    {
        $this->db->exec(
            "INSERT INTO " . self::MIGRATIONS_TABLE . " (migration, batch) VALUES (:name, :batch)",
            [':name' => $fileName, ':batch' => $batch]
        );
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatchNumber(): int
    {
        $rows = $this->db->query(
            "SELECT MAX(batch) as max_batch FROM " . self::MIGRATIONS_TABLE
        );

        $maxBatch = (int)($rows[0]['max_batch'] ?? 0);
        return $maxBatch + 1;
    }

    /**
     * Get the last batch number.
     */
    private function getLastBatchNumber(): int
    {
        $rows = $this->db->query(
            "SELECT MAX(batch) as max_batch FROM " . self::MIGRATIONS_TABLE
        );
        return (int)($rows[0]['max_batch'] ?? 0);
    }

    /**
     * Get migrations for a specific batch.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMigrationsForBatch(int $batch): array
    {
        return $this->db->query(
            "SELECT migration, batch FROM " . self::MIGRATIONS_TABLE . " WHERE batch = :batch ORDER BY migration ASC",
            [':batch' => $batch]
        );
    }

    /**
     * Get the path to the .down.sql file for a given migration filename.
     *
     * For "20240101000000_create_users.sql" returns
     * "{migrationsDir}/20240101000000_create_users.down.sql"
     *
     * @return string|null Full path if determinable, null otherwise
     */
    private function getDownFilePath(string $migrationFileName): ?string
    {
        // Strip .sql extension and add .down.sql
        if (str_ends_with($migrationFileName, '.sql')) {
            $baseName = substr($migrationFileName, 0, -4); // remove .sql
            return $this->migrationsDir . '/' . $baseName . '.down.sql';
        }

        return null;
    }

    /**
     * Split SQL content into individual statements, properly handling:
     *   - $$ delimited stored procedure/function blocks
     *   - // delimited blocks
     *   - Block comments: /* ... * /
     *   - Line comments: -- ...
     *   - Quoted strings (don't split on ; inside quotes)
     *
     * @return array<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $inDollarBlock = false;
        $inSlashBlock = false;

        while ($i < $len) {
            // Check for $$ delimiter (toggle in/out of dollar-quoted block)
            if (!$inSlashBlock && $i + 1 < $len && $sql[$i] === '$' && $sql[$i + 1] === '$') {
                $current .= '$$';
                $i += 2;
                $inDollarBlock = !$inDollarBlock;
                continue;
            }

            // Check for // delimiter (toggle in/out of slash-delimited block)
            if (!$inDollarBlock && $i + 1 < $len && $sql[$i] === '/' && $sql[$i + 1] === '/') {
                $current .= '//';
                $i += 2;
                $inSlashBlock = !$inSlashBlock;
                continue;
            }

            // Inside a delimited block, consume everything until the closing delimiter
            if ($inDollarBlock || $inSlashBlock) {
                $current .= $sql[$i];
                $i++;
                continue;
            }

            // Block comment: /* ... */
            if ($i + 1 < $len && $sql[$i] === '/' && $sql[$i + 1] === '*') {
                $endPos = strpos($sql, '*/', $i + 2);
                if ($endPos !== false) {
                    $current .= substr($sql, $i, ($endPos + 2) - $i);
                    $i = $endPos + 2;
                } else {
                    // Unterminated block comment — consume rest
                    $current .= substr($sql, $i);
                    $i = $len;
                }
                continue;
            }

            // Line comment: -- ...
            if ($i + 1 < $len && $sql[$i] === '-' && $sql[$i + 1] === '-') {
                $endPos = strpos($sql, "\n", $i + 2);
                if ($endPos !== false) {
                    $current .= substr($sql, $i, ($endPos + 1) - $i);
                    $i = $endPos + 1;
                } else {
                    $current .= substr($sql, $i);
                    $i = $len;
                }
                continue;
            }

            // Single-quoted string
            if ($sql[$i] === "'") {
                $current .= "'";
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                        // Escaped quote ''
                        $current .= "''";
                        $i += 2;
                    } elseif ($sql[$i] === "'") {
                        $current .= "'";
                        $i++;
                        break;
                    } else {
                        $current .= $sql[$i];
                        $i++;
                    }
                }
                continue;
            }

            // Double-quoted identifier
            if ($sql[$i] === '"') {
                $current .= '"';
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === '"' && $i + 1 < $len && $sql[$i + 1] === '"') {
                        $current .= '""';
                        $i += 2;
                    } elseif ($sql[$i] === '"') {
                        $current .= '"';
                        $i++;
                        break;
                    } else {
                        $current .= $sql[$i];
                        $i++;
                    }
                }
                continue;
            }

            // Statement delimiter
            if ($sql[$i] === $this->delimiter) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $sql[$i];
            $i++;
        }

        // Don't forget the last statement (may not end with delimiter)
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    // ──────────────────────────────────────────────────────────────
    // Firebird ALTER TABLE ADD idempotency check
    // ──────────────────────────────────────────────────────────────
    // Firebird does not support IF NOT EXISTS for ALTER TABLE ADD.
    // When a migration adds a column that already exists, Firebird
    // throws an error and blocks the entire migration. These helpers
    // detect ALTER TABLE ... ADD statements and query RDB$RELATION_FIELDS
    // to see if the column is already present. If so, the statement is
    // silently skipped rather than executed.

    /** Pattern to match ALTER TABLE <table> ADD <column> ... */
    private const ALTER_ADD_PATTERN =
        '/^\s*ALTER\s+TABLE\s+(?:"([^"]+)"|(\S+))\s+ADD\s+(?:"([^"]+)"|(\S+))/i';

    /**
     * Check if the database adapter is Firebird.
     */
    private function isFirebird(): bool
    {
        return $this->db instanceof \Tina4\Database\FirebirdAdapter;
    }

    /**
     * Check if a column already exists in a Firebird table.
     *
     * Firebird stores unquoted identifiers in upper-case, so both
     * the table and column names are uppercased before comparison.
     */
    private function firebirdColumnExists(string $table, string $column): bool
    {
        $rows = $this->db->query(
            "SELECT 1 FROM RDB\$RELATION_FIELDS "
            . "WHERE RDB\$RELATION_NAME = :table AND TRIM(RDB\$FIELD_NAME) = :column",
            [':table' => strtoupper($table), ':column' => strtoupper($column)]
        );

        return !empty($rows);
    }

    /**
     * If $statement is an ALTER TABLE ... ADD on Firebird and the column
     * already exists, return a skip reason string. Returns null if the
     * statement should execute normally.
     */
    private function shouldSkipForFirebird(string $statement): ?string
    {
        if (!$this->isFirebird()) {
            return null;
        }

        if (!preg_match(self::ALTER_ADD_PATTERN, $statement, $m)) {
            return null;
        }

        $table = $m[1] !== '' ? $m[1] : $m[2];
        $column = $m[3] !== '' ? $m[3] : $m[4];

        if ($this->firebirdColumnExists($table, $column)) {
            return "Column {$column} already exists in {$table}, skipping";
        }

        return null;
    }
}
