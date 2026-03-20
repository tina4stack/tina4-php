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
 * Migration files must be named: YYYYMMDDHHMMSS_description.sql
 * Located in src/migrations/ by default.
 *
 * Tracks applied migrations in the _tina4_migrations table.
 */
class Migration
{
    /** @var string Name of the migrations tracking table */
    private const MIGRATIONS_TABLE = '_tina4_migrations';

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
     * Get all migration files sorted by name (timestamp order).
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
     * Split SQL content into individual statements by delimiter.
     *
     * @return array<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = explode($this->delimiter, $sql);
        return array_filter(array_map('trim', $statements), fn(string $s) => $s !== '');
    }
}
