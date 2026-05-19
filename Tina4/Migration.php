<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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

            // PHP class-based migration
            if (str_ends_with($fileName, '.php')) {
                $this->db->startTransaction();
                try {
                    $this->executePHPMigration($migration, 'up');
                    $this->recordMigration($fileName, $batch);
                    $this->db->commit();
                    $applied[] = $fileName;
                    Log::info("Migration applied: {$fileName}");
                } catch (\Throwable $e) {
                    $this->db->rollback();
                    $errors[$fileName] = $e->getMessage();
                    Log::error("Migration failed: {$fileName} — {$e->getMessage()}");
                    break;
                }
                continue;
            }

            $sql = file_get_contents($migration);

            if ($sql === false || trim($sql) === '') {
                $skipped[] = $fileName;
                continue;
            }

            $this->db->startTransaction();

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
     * Rollback the last N batches of migrations.
     *
     * For each migration being rolled back:
     *   1. Look for a matching .down.sql file (e.g. 20240101000000_create_users.down.sql)
     *   2. If found, execute it before removing the tracking record
     *   3. If not found, log a warning but still remove the tracking record
     *
     * @param int $steps Number of batches to roll back (default: 1)
     * @return array{rolledBack: array<string>, errors: array<string, string>}
     */
    public function rollback(int $steps = 1): array
    {
        $rolledBack = [];
        $errors = [];

        for ($step = 0; $step < $steps; $step++) {
            $lastBatch = $this->getLastBatchNumber();
            if ($lastBatch === 0) {
                break;
            }

            $stepResult = $this->rollbackBatch($lastBatch);
            $rolledBack = array_merge($rolledBack, $stepResult['rolledBack']);
            $errors = array_merge($errors, $stepResult['errors']);

            if (!empty($stepResult['errors'])) {
                break;
            }
        }

        return ['rolledBack' => $rolledBack, 'errors' => $errors];
    }

    /**
     * Rollback a single batch.
     *
     * @return array{rolledBack: array<string>, errors: array<string, string>}
     */
    private function rollbackBatch(int $batch): array
    {
        $rolledBack = [];
        $errors = [];

        $migrations = $this->getMigrationsForBatch($batch);

        // Process in reverse order
        $migrations = array_reverse($migrations);

        foreach ($migrations as $migration) {
            $fileName = $migration['migration'];

            $this->db->startTransaction();

            try {
                // PHP class-based migration: call down()
                if (str_ends_with($fileName, '.php')) {
                    $phpFile = $this->migrationsDir . '/' . $fileName;
                    if (file_exists($phpFile)) {
                        $this->executePHPMigration($phpFile, 'down');
                        Log::info("Executed down migration: {$fileName}");
                    } else {
                        Log::warning("No .php file found for {$fileName}, removing tracking record only");
                    }
                } else {
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

        $sqlFiles = glob($this->migrationsDir . '/*.sql');
        if ($sqlFiles === false) {
            $sqlFiles = [];
        }

        // Exclude .down.sql files — those are rollback scripts, not up migrations
        $sqlFiles = array_filter($sqlFiles, function (string $file): bool {
            return !str_ends_with($file, '.down.sql');
        });

        // Include .php migration files matching the digit-prefix naming pattern
        $phpFiles = glob($this->migrationsDir . '/*.php');
        if ($phpFiles === false) {
            $phpFiles = [];
        }

        $phpFiles = array_filter($phpFiles, function (string $file): bool {
            return (bool) preg_match('/^\d+_/', basename($file));
        });

        $files = array_values(array_merge(array_values($sqlFiles), array_values($phpFiles)));
        usort($files, function (string $a, string $b): int {
            return strcmp(basename($a), basename($b));
        });

        return $files;
    }

    /**
     * Get list of all applied migrations (alias for getAppliedMigrations).
     *
     * @return array<int, array{migration: string, batch: int, applied_at: string}>
     */
    public function getApplied(): array
    {
        return $this->getAppliedMigrations();
    }

    /**
     * Get the list of pending migration filenames (alias for getPendingMigrations).
     *
     * @return array<string> Basenames of pending migration files
     */
    public function getPending(): array
    {
        return array_map('basename', $this->getPendingMigrations());
    }

    /**
     * Get all migration files on disk, excluding .down.sql (alias for getMigrationFiles).
     *
     * @return array<string> Basenames of all migration files
     */
    public function getFiles(): array
    {
        return array_map('basename', $this->getMigrationFiles());
    }

    /**
     * Create a new migration file with a YYYYMMDDHHMMSS timestamp prefix.
     *
     * When $kind is 'sql' (default): creates both .sql and .down.sql files.
     * When $kind is 'php': creates a single .php file with up()/down() methods.
     *
     * @param string $description Human-readable description (used in filename)
     * @param string $kind        'sql' (default) or 'php'
     * @return string Path to the created migration file
     */
    public function create(string $description, string $kind = 'sql'): string
    {
        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0755, true);
        }

        $timestamp = date('YmdHis');
        $safeName  = preg_replace('/[^a-z0-9]+/', '_', strtolower($description));
        $safeName  = trim($safeName, '_');
        $createdAt = date('Y-m-d H:i:s') . ' UTC';

        if ($kind === 'php') {
            $fileName  = "{$timestamp}_{$safeName}.php";
            $filePath  = $this->migrationsDir . DIRECTORY_SEPARATOR . $fileName;

            // Derive a CamelCase class name
            $className = implode('', array_map('ucfirst', preg_split('/[^a-z0-9]+/', strtolower($description))));

            $content = "<?php\n\n"
                . "use Tina4\\MigrationBase;\n\n"
                . "// Migration: {$description}\n"
                . "// Created: {$createdAt}\n\n"
                . "class {$className} extends MigrationBase\n"
                . "{\n"
                . "    public function up(\$db): void\n"
                . "    {\n"
                . "        // \$db->exec(\"CREATE TABLE ...\");\n"
                . "    }\n\n"
                . "    public function down(\$db): void\n"
                . "    {\n"
                . "        // \$db->exec(\"DROP TABLE IF EXISTS ...\");\n"
                . "    }\n"
                . "}\n";

            file_put_contents($filePath, $content);
            Log::info("Created migration: {$fileName}");
            return $filePath;
        }

        $upFileName   = "{$timestamp}_{$safeName}.sql";
        $downFileName = "{$timestamp}_{$safeName}.down.sql";
        $upPath       = $this->migrationsDir . DIRECTORY_SEPARATOR . $upFileName;
        $downPath     = $this->migrationsDir . DIRECTORY_SEPARATOR . $downFileName;

        file_put_contents($upPath, "-- Migration: {$description}\n-- Created: {$createdAt}\n\n");
        file_put_contents($downPath, "-- Rollback: {$description}\n-- Created: {$createdAt}\n\n");

        Log::info("Created migration: {$upFileName}");
        return $upPath;
    }

    /**
     * Create a new migration file — static helper for parity with Python/Ruby/Node.
     *
     * @param string $description    Human-readable migration name
     * @param string $migrationsDir  Directory for migration files (default: 'migrations')
     * @param string $kind           File kind: 'sql' or 'php' (default: 'sql')
     * @return string Path to the created file
     */
    public static function createMigration(string $description, string $migrationsDir = 'migrations', string $kind = 'sql'): string
    {
        $m = new static($migrationsDir);
        return $m->create($description, $kind);
    }

    /**
     * Execute a .php migration file by requiring it and calling up() or down().
     *
     * @param string $filepath  Full path to the .php migration file.
     * @param string $direction Either 'up' or 'down'.
     */
    private function executePHPMigration(string $filepath, string $direction): void
    {
        // Snapshot classes before loading so we can detect newly defined ones
        $before = get_declared_classes();

        require_once $filepath;

        // Find a MigrationBase subclass that was newly defined by this file
        $after = get_declared_classes();
        $newClasses = array_diff($after, $before);

        $klass = null;
        foreach ($newClasses as $class) {
            if (is_subclass_of($class, MigrationBase::class)) {
                $klass = $class;
                break;
            }
        }

        // If no new class was defined (e.g. require_once skipped a re-include),
        // fall back to the most recently declared subclass
        if ($klass === null) {
            foreach (array_reverse($after) as $class) {
                if (is_subclass_of($class, MigrationBase::class)) {
                    // Verify this class is actually defined in the expected file
                    try {
                        $ref = new \ReflectionClass($class);
                        if (realpath($ref->getFileName()) === realpath($filepath)) {
                            $klass = $class;
                            break;
                        }
                    } catch (\ReflectionException) {
                        // ignore
                    }
                }
            }
        }

        if ($klass === null) {
            throw new \RuntimeException("No MigrationBase subclass found in {$filepath}");
        }

        $instance = new $klass();
        $instance->{$direction}($this->db);
    }

    /**
     * Create the migrations tracking table — or upgrade a legacy v2 table
     * to the v3 shape if one is already present.
     *
     * v2 shape (tina4-php ^2.x):
     *   migration_id VARCHAR(14) PRIMARY KEY, description VARCHAR(1000),
     *   content BLOB, passed INTEGER
     *
     * v3 shape:
     *   id INTEGER PRIMARY KEY, migration VARCHAR, batch INTEGER, applied_at TIMESTAMP
     *
     * See tina4-php#115.
     */
    private function ensureMigrationsTable(): void
    {
        if (!$this->db->tableExists(self::MIGRATIONS_TABLE)) {
            $this->createV3Table();
            return;
        }

        if ($this->isLegacyV2Schema()) {
            $this->upgradeV2ToV3();
        }
    }

    /**
     * Detect a v2-shaped tina4_migration table by column presence.
     * v2 has `migration_id`; v3 has `migration` (without the `_id` suffix).
     */
    private function isLegacyV2Schema(): bool
    {
        try {
            $cols = $this->db->getColumns(self::MIGRATIONS_TABLE);
        } catch (\Throwable) {
            return false;
        }

        $names = array_map(
            fn($c) => strtolower((string)($c['name'] ?? '')),
            $cols
        );

        return in_array('migration_id', $names, true)
            && !in_array('migration', $names, true);
    }

    /**
     * In-place upgrade of a v2 tina4_migration table to the v3 shape.
     *
     * 1. ALTER TABLE ADD the v3 columns (`migration`, `batch`, `applied_at`)
     *    alongside the existing v2 columns. v2 columns are left in place so
     *    a manual rollback is possible — they are simply ignored from now on.
     * 2. Backfill each v2 row's `migration` value from a file-on-disk match
     *    keyed by the 14-character timestamp prefix in `migration_id`.
     *    Fall back to `migration_id + '.sql'` when no file matches.
     * 3. All legacy entries get `batch = 1`.
     */
    private function upgradeV2ToV3(): void
    {
        Log::warning(
            "Detected legacy v2 tina4_migration schema — performing in-place upgrade to v3"
        );

        // Step 1: add the v3 columns. Each ALTER is wrapped in a try/catch
        // because a partial previous upgrade may have already added some.
        $alters = $this->isFirebird()
            ? [
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD migration VARCHAR(500)",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD batch INTEGER DEFAULT 1",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD applied_at VARCHAR(50) DEFAULT 'legacy'",
            ]
            : [
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN migration VARCHAR(500)",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN batch INTEGER DEFAULT 1",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN applied_at VARCHAR(50) DEFAULT 'legacy'",
            ];

        foreach ($alters as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\Throwable $e) {
                // Column may already exist from a prior partial upgrade.
                Log::debug("v2→v3 upgrade: ALTER skipped — {$e->getMessage()}");
            }
        }

        // Step 2: build a map of 14-char timestamp prefix → file basename
        // from the current migrations directory so legacy rows can be
        // resolved to the correct v3 `migration` value even when renames
        // happened between v2 and v3.
        $fileMap = [];
        foreach ($this->getMigrationFiles() as $file) {
            $base = basename($file);
            if (preg_match('/^(\d{14})/', $base, $m)) {
                $fileMap[$m[1]] = $base;
            }
        }

        // Step 3: backfill every v2 row.
        try {
            $v2Rows = $this->db->query(
                "SELECT migration_id, passed FROM " . self::MIGRATIONS_TABLE
            );
        } catch (\Throwable $e) {
            Log::error("v2→v3 upgrade: failed to read legacy rows — {$e->getMessage()}");
            return;
        }

        $backfilled = 0;
        $failedInV2 = 0;

        foreach ($v2Rows as $row) {
            $prefix = (string)($row['migration_id'] ?? '');
            if ($prefix === '') {
                continue;
            }

            $migration = $fileMap[$prefix] ?? ($prefix . '.sql');

            try {
                $this->db->exec(
                    "UPDATE " . self::MIGRATIONS_TABLE
                    . " SET migration = :m, batch = 1 WHERE migration_id = :p",
                    [':m' => $migration, ':p' => $prefix]
                );
                $backfilled++;
                if ((int)($row['passed'] ?? 0) === 0) {
                    $failedInV2++;
                }
            } catch (\Throwable $e) {
                Log::error("v2→v3 upgrade: failed to backfill {$prefix} — {$e->getMessage()}");
            }
        }

        $note = $failedInV2 > 0
            ? " ({$failedInV2} were marked passed=0 in v2 — review manually)"
            : '';
        Log::info(
            "v2→v3 tina4_migration upgrade complete: {$backfilled} rows backfilled{$note}"
        );
    }

    /**
     * Create the v3 tina4_migration table.
     */
    private function createV3Table(): void
    {
        if ($this->isFirebird()) {
            // Firebird: no AUTOINCREMENT, no TEXT type, use generator for IDs
            try {
                $this->db->exec("CREATE GENERATOR GEN_TINA4_MIGRATION_ID");
                $this->db->exec("COMMIT");
            } catch (\Throwable) {
                // Generator may already exist
            }
            $this->db->exec("
                CREATE TABLE " . self::MIGRATIONS_TABLE . " (
                    id INTEGER NOT NULL PRIMARY KEY,
                    migration VARCHAR(500) NOT NULL UNIQUE,
                    batch INTEGER NOT NULL,
                    applied_at VARCHAR(50) DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
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
     *
     * @param string $fileName Migration filename (e.g. "20240101000000_create_users.sql").
     * @param int    $batch    Batch number this migration belongs to.
     * @param int    $passed   1 if the migration succeeded (default), 0 if it failed.
     */
    public function recordMigration(string $name, int $batch, int $passed = 1): void
    {
        if ($this->isFirebird()) {
            // Firebird: generate ID from sequence
            $rows = $this->db->query(
                "SELECT GEN_ID(GEN_TINA4_MIGRATION_ID, 1) AS NEXT_ID FROM RDB\$DATABASE"
            );
            $nextId = (int)($rows[0]['NEXT_ID'] ?? 1);
            $this->db->exec(
                "INSERT INTO " . self::MIGRATIONS_TABLE . " (id, migration, batch) VALUES (:id, :name, :batch)",
                [':id' => $nextId, ':name' => $name, ':batch' => $batch]
            );
        } else {
            $this->db->exec(
                "INSERT INTO " . self::MIGRATIONS_TABLE . " (migration, batch) VALUES (:name, :batch)",
                [':name' => $name, ':batch' => $batch]
            );
        }
    }

    /**
     * Remove a migration record from the tracking table by name.
     *
     * @param string $name Migration name to remove.
     */
    public function removeMigrationRecord(string $name): void
    {
        $this->db->exec(
            "DELETE FROM " . self::MIGRATIONS_TABLE . " WHERE migration = :name",
            [':name' => $name]
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
