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
 * Located in migrations/ (project root) by default — the one canonical location, matching
 * createMigration(), App auto-migrate, the CLI, and the Python reference. Legacy src/migrations/
 * is still honoured as a fallback.
 * Tracks applied migrations in the tina4_migration table.
 */
class Migration
{
    /** @var string Name of the migrations tracking table */
    private const MIGRATIONS_TABLE = 'tina4_migration';

    /** Cached probe: does the tracking table still carry the legacy v2 `migration_id` column? */
    private ?bool $legacyMigrationIdColumn = null;

    /** Cached probe: does the tracking table still carry the older-v3 `migration` column? */
    private ?bool $legacyMigrationColumn = null;

    /** @var string Resolved migrations directory — canonical migrations/ at the project root */
    private readonly string $migrationsDir;

    public function __construct(
        private readonly DatabaseAdapter $db,
        string $migrationsDir = 'migrations',
        private readonly string $delimiter = ';',
    ) {
        // Canonical location is migrations/ (project root) — matches createMigration(), App
        // auto-migrate, the CLI, and the Python reference. Fall back to the legacy
        // src/migrations/ ONLY when the default was taken, migrations/ is absent, and a legacy
        // src/migrations/ exists, so existing projects keep working (with a deprecation nudge).
        if ($migrationsDir === 'migrations' && !is_dir('migrations') && is_dir('src/migrations')) {
            Log::warning('Migration: using legacy src/migrations/ — move migrations to migrations/ (project root).');
            $migrationsDir = 'src/migrations';
        }
        $this->migrationsDir = $migrationsDir;
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

                    // Idempotency on engines lacking IF NOT EXISTS:
                    //  - Firebird ALTER TABLE ADD (pre-check RDB$RELATION_FIELDS)
                    //  - CREATE TABLE on Firebird AND MSSQL (pre-check tableExists)
                    // so a re-run migration with a raw DDL statement is silently
                    // skipped instead of raising "object already exists".
                    $skipReason = $this->shouldSkipForFirebird($statement)
                        ?? $this->shouldSkipCreateTable($statement);
                    if ($skipReason !== null) {
                        Log::info("Migration {$fileName}: {$skipReason}");
                        continue;
                    }

                    // exec() now RAISES on a SQL error (FAIL LOUD contract);
                    // the thrown error flows to the catch below, which rolls
                    // back and records the failure. A raw-adapter binding may
                    // still return false instead of throwing, so keep the
                    // explicit guard for that path.
                    if ($this->db->execute($statement) === false) {
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
            $fileName = $migration['migration_name'];

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

                                // exec() now RAISES on a SQL error (FAIL LOUD
                                // contract); the rollback try/catch below rolls
                                // back and records the failure. A raw-adapter
                                // binding may still return false, so keep the
                                // explicit guard for that path.
                                if ($this->db->execute($statement) === false) {
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
                $this->db->execute(
                    "DELETE FROM " . self::MIGRATIONS_TABLE . " WHERE migration_name = :name",
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
     * @return array{completed: array<int, array{migration_name: string, description: string, batch: int, executed_at: string}>, pending: array<string>}
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
     * Run a tracking-table query and return the rows with every column key
     * lower-cased.
     *
     * Column names are referenced in lower case throughout this class, so keys
     * are normalised here to read the same across adapters that report them in
     * different cases (Firebird, for example, returns upper-case keys).
     *
     * @param string $sql SQL to run against the tracking table.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return array<int, array<string, mixed>> Rows with lower-cased keys; an
     *                                          empty array when there is no result set.
     */
    private function queryLower(string $sql, array $params = []): array
    {
        $rows = $this->db->query($sql, $params);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(
            static fn($r) => array_change_key_case((array) $r, CASE_LOWER),
            $rows
        );
    }

    /**
     * Get list of all applied migrations. A migration is "applied" iff it has a
     * row with passed = 1.
     *
     * @return array<int, array{migration_name: string, description: string, batch: int, executed_at: string}>
     */
    public function getAppliedMigrations(): array
    {
        return $this->queryLower(
            "SELECT migration_name, description, batch, executed_at FROM " . self::MIGRATIONS_TABLE
            . " WHERE passed = 1 ORDER BY migration_name ASC"
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
        $appliedNames = array_column($this->getAppliedMigrations(), 'migration_name');

        // Also honour legacy v2 rows keyed only by the 14-char timestamp prefix
        // (`migration_id`). A table upgraded in-place from v2 — or one whose
        // `migration` backfill did not resolve to the current filename — still
        // records that the migration ran; matching the prefix stops it being
        // re-applied. Keyed as prefix => true for O(1) lookup.
        $appliedPrefixes = [];
        if ($this->hasLegacyMigrationIdColumn()) {
            foreach ($this->queryLower("SELECT migration_id FROM " . self::MIGRATIONS_TABLE) as $row) {
                $prefix = trim((string) ($row['migration_id'] ?? ''));
                if ($prefix !== '') {
                    $appliedPrefixes[$prefix] = true;
                }
            }
        }

        $pending = [];
        foreach ($allFiles as $file) {
            $base = basename($file);
            if (in_array($base, $appliedNames, true)) {
                continue;
            }
            if (preg_match('/^(\d{14})/', $base, $m) && isset($appliedPrefixes[$m[1]])) {
                continue;
            }
            $pending[] = $file;
        }

        return $pending;
    }

    /**
     * Get all migration files sorted in numeric-aware order.
     * Excludes .down.sql rollback files.
     *
     * Files are sorted by a leading numeric / timestamp prefix so that `9_`
     * applies before `10_` — a plain lexical sort (strcmp) misorders unpadded
     * prefixes ("10" < "9"). Both YYYYMMDDHHMMSS_name.sql and 000001_name.sql
     * patterns sort correctly because they are numeric prefixes. Files WITHOUT
     * a numeric prefix sort after the numbered ones, then lexically; a warning
     * is logged listing any such file because its order is undefined — a silent
     * out-of-order-apply footgun.
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
            return self::migrationSortKey(basename($a)) <=> self::migrationSortKey(basename($b));
        });

        // Warn about filenames without a recognised NNNNNN_/timestamp prefix —
        // their ordering relative to numbered migrations is undefined.
        $unprefixed = [];
        foreach ($files as $file) {
            if (!preg_match('/^\d+[_-]/', basename($file))) {
                $unprefixed[] = basename($file);
            }
        }
        if (!empty($unprefixed)) {
            Log::warning(
                "Migration file(s) without a numeric/timestamp prefix may apply out of order: "
                . implode(', ', $unprefixed)
            );
        }

        return $files;
    }

    /**
     * Numeric-aware sort key for a migration filename so `9_*` sorts before
     * `10_*` (plain strcmp puts "10" before "9"). Files with a leading numeric /
     * timestamp prefix sort first by that number; the rest sort after, lexically.
     *
     * Returns a comparable tuple [group, number, name] where group 0 = numeric
     * prefix, group 1 = no prefix. The number is a zero-padded string so very
     * long timestamp prefixes compare correctly without integer overflow.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function migrationSortKey(string $name): array
    {
        if (preg_match('/^(\d+)/', $name, $m)) {
            return [0, str_pad($m[1], 20, '0', STR_PAD_LEFT), $name];
        }
        return [1, str_repeat('0', 20), $name];
    }

    /**
     * Get list of all applied migrations (alias for getAppliedMigrations).
     *
     * @return array<int, array{migration_name: string, description: string, batch: int, executed_at: string}>
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
                . "        // \$db->execute(\"CREATE TABLE ...\");\n"
                . "    }\n\n"
                . "    public function down(\$db): void\n"
                . "    {\n"
                . "        // \$db->execute(\"DROP TABLE IF EXISTS ...\");\n"
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
     * Canonical v3 shape (unified across all four frameworks):
     *   id, migration_name VARCHAR(500) UNIQUE, description VARCHAR(500),
     *   batch INTEGER, executed_at VARCHAR(50), passed INTEGER
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
            return;
        }

        // A table created by an OLDER v3 (<= 3.13.54) has a `migration` column
        // but not `migration_name`. Bring it up to the canonical shape in place.
        $this->upgradeLegacyV3Schema();
    }

    /**
     * In-place, non-destructive upgrade of a table created by an OLDER v3
     * (<= 3.13.54) to the canonical shape.
     *
     * That table has `migration` (+ `batch`, `applied_at`) but none of the
     * canonical `migration_name`, `description`, `executed_at`, `passed`
     * columns. This adds the missing columns, copies `migration` into
     * `migration_name` and `applied_at` into `executed_at` (falling back to the
     * literal 'legacy'), and marks every existing row `passed = 1` — those rows
     * were only ever written when the migration was applied. The old
     * `migration`/`applied_at` columns are left in place (ignored from now on).
     *
     * This MUST leave already-applied migrations recognised as applied so they
     * are not re-run: without the `migration_name` backfill the applied-read
     * would find nothing and every migration would re-run.
     */
    private function upgradeLegacyV3Schema(): void
    {
        $cols = $this->columnNames();

        // Only act on the specific older-v3 shape: `migration` present,
        // `migration_name` absent. A canonical table (has `migration_name`) is
        // left untouched.
        if (!in_array('migration', $cols, true) || in_array('migration_name', $cols, true)) {
            return;
        }

        Log::warning(
            "Detected older v3 tina4_migration schema — upgrading `migration` to the canonical `migration_name` shape"
        );

        $isFirebird = $this->isFirebird();
        $textType   = $isFirebird ? 'VARCHAR(500)' : 'TEXT';
        $stampType  = $isFirebird ? 'VARCHAR(50)'  : 'TEXT';
        // Firebird has no ADD COLUMN keyword — bare ADD; every other engine here
        // supports ADD COLUMN.
        $addKeyword = $isFirebird ? 'ADD' : 'ADD COLUMN';

        $adds = [
            'migration_name' => "migration_name {$textType}",
            'description'    => "description {$textType}",
            'executed_at'    => "executed_at {$stampType}",
            'passed'         => "passed INTEGER DEFAULT 1",
        ];

        foreach ($adds as $column => $definition) {
            if (in_array($column, $cols, true)) {
                continue;
            }
            try {
                // On Firebird an ALTER TABLE ADD of an existing column raises (no
                // IF NOT EXISTS) — reuse the RDB$RELATION_FIELDS idempotency probe.
                if ($isFirebird && $this->firebirdColumnExists(self::MIGRATIONS_TABLE, $column)) {
                    continue;
                }
                $this->db->execute(
                    "ALTER TABLE " . self::MIGRATIONS_TABLE . " {$addKeyword} {$definition}"
                );
            } catch (\Throwable $e) {
                // Column may already exist from a prior partial upgrade.
                Log::debug("v3 in-place upgrade: ALTER skipped — {$e->getMessage()}");
            }
        }

        // Backfill migration_name from the old `migration` column.
        try {
            $this->db->execute(
                "UPDATE " . self::MIGRATIONS_TABLE
                . " SET migration_name = migration WHERE migration_name IS NULL"
            );
        } catch (\Throwable $e) {
            Log::error("v3 in-place upgrade: migration_name backfill failed — {$e->getMessage()}");
        }

        // executed_at from the old `applied_at` column when present (a
        // TIMESTAMP/DATETIME -> VARCHAR copy can be rejected by strict engines,
        // so fall back to the literal 'legacy'), else 'legacy' outright.
        $backfilledStamp = false;
        if (in_array('applied_at', $this->columnNames(), true)) {
            try {
                $this->db->execute(
                    "UPDATE " . self::MIGRATIONS_TABLE
                    . " SET executed_at = applied_at WHERE executed_at IS NULL"
                );
                $backfilledStamp = true;
            } catch (\Throwable $e) {
                Log::debug("v3 in-place upgrade: executed_at<-applied_at skipped — {$e->getMessage()}");
            }
        }
        if (!$backfilledStamp) {
            try {
                $this->db->execute(
                    "UPDATE " . self::MIGRATIONS_TABLE
                    . " SET executed_at = 'legacy' WHERE executed_at IS NULL"
                );
            } catch (\Throwable $e) {
                Log::debug("v3 in-place upgrade: executed_at<-'legacy' skipped — {$e->getMessage()}");
            }
        }

        // Existing rows were only ever written when applied — mark them passed.
        try {
            $this->db->execute(
                "UPDATE " . self::MIGRATIONS_TABLE . " SET passed = 1 WHERE passed IS NULL"
            );
        } catch (\Throwable $e) {
            Log::debug("v3 in-place upgrade: passed backfill skipped — {$e->getMessage()}");
        }
    }

    /**
     * The lower-cased set of column names on the tracking table, or an empty
     * array if the table can't be read. Column names are compared in lower case
     * throughout this class so they resolve the same across adapters that report
     * them in different cases (Firebird returns upper-case names).
     *
     * @return array<int, string>
     */
    private function columnNames(): array
    {
        try {
            $cols = $this->db->getColumns(self::MIGRATIONS_TABLE);
        } catch (\Throwable) {
            return [];
        }
        return array_map(
            fn($c) => strtolower((string)($c['name'] ?? '')),
            $cols
        );
    }

    /**
     * Detect a v2-shaped tina4_migration table by column presence.
     *
     * v2 has `migration_id`; the canonical v3 shape has `migration_name`. A v2
     * table (including one only partially upgraded by an older PHP build that
     * added a `migration` column) is detected by `migration_id` present AND
     * `migration_name` absent — so a fresh canonical v3 table (which HAS
     * `migration_name`) is never misread as v2.
     */
    private function isLegacyV2Schema(): bool
    {
        $names = $this->columnNames();

        return in_array('migration_id', $names, true)
            && !in_array('migration_name', $names, true);
    }

    /**
     * In-place upgrade of a v2 tina4_migration table to the canonical v3 shape.
     *
     * v2 shape: `migration_id VARCHAR(14) PRIMARY KEY, description VARCHAR(1000),
     * content BLOB, passed INTEGER` — it already carries `description` and
     * `passed`, so only `migration_name`, `batch` and `executed_at` are added.
     *
     * 1. ALTER TABLE ADD the missing canonical columns (`migration_name`,
     *    `batch`, `executed_at`) alongside the existing v2 columns. v2 columns
     *    are left in place so a manual rollback is possible — they are simply
     *    ignored from now on.
     * 2. Backfill each v2 row's `migration_name` from a file-on-disk match keyed
     *    by the 14-character timestamp prefix in `migration_id`. Fall back to
     *    `migration_id + '.sql'` when no file matches.
     * 3. All legacy entries get `batch = 1` (executed_at defaults to 'legacy').
     */
    private function upgradeV2ToV3(): void
    {
        Log::warning(
            "Detected legacy v2 tina4_migration schema — performing in-place upgrade to v3"
        );

        // Step 1: add the missing canonical columns. Each ALTER is wrapped in a
        // try/catch because a partial previous upgrade may have already added some.
        $alters = $this->isFirebird()
            ? [
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD migration_name VARCHAR(500)",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD batch INTEGER DEFAULT 1",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD executed_at VARCHAR(50) DEFAULT 'legacy'",
            ]
            : [
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN migration_name VARCHAR(500)",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN batch INTEGER DEFAULT 1",
                "ALTER TABLE " . self::MIGRATIONS_TABLE . " ADD COLUMN executed_at VARCHAR(50) DEFAULT 'legacy'",
            ];

        foreach ($alters as $sql) {
            try {
                $this->db->execute($sql);
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
            $v2Rows = $this->queryLower(
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
                $this->db->execute(
                    "UPDATE " . self::MIGRATIONS_TABLE
                    . " SET migration_name = :m, batch = 1 WHERE migration_id = :p",
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
            ? " ({$failedInV2} were marked passed=0 in v2 - they will re-apply on the next migrate)"
            : '';
        Log::info(
            "v2→v3 tina4_migration upgrade complete: {$backfilled} rows backfilled{$note}"
        );
    }

    /**
     * Create the v3 tina4_migration table in the canonical shape shared across
     * all four Tina4 frameworks:
     *
     *   id             engine auto-increment PK
     *   migration_name VARCHAR(500) NOT NULL UNIQUE   (the migration filename)
     *   description    VARCHAR(500)                    (human-readable, derived)
     *   batch          INTEGER NOT NULL DEFAULT 1
     *   executed_at    VARCHAR(50) NOT NULL            (ISO-8601 string, written explicitly)
     *   passed         INTEGER NOT NULL DEFAULT 1      (a migration is "applied" iff passed = 1)
     *
     * `executed_at` is a written string timestamp — NOT a DB TIMESTAMP/DATETIME
     * with a CURRENT_TIMESTAMP default — so the value is uniform across engines.
     */
    private function createV3Table(): void
    {
        if ($this->isFirebird()) {
            // Firebird: no AUTOINCREMENT, no TEXT type, use generator for IDs
            try {
                $this->db->execute("CREATE GENERATOR GEN_TINA4_MIGRATION_ID");
                $this->db->execute("COMMIT");
            } catch (\Throwable) {
                // Generator may already exist
            }
            $this->db->execute("
                CREATE TABLE " . self::MIGRATIONS_TABLE . " (
                    id INTEGER NOT NULL PRIMARY KEY,
                    migration_name VARCHAR(500) NOT NULL UNIQUE,
                    description VARCHAR(500),
                    batch INTEGER DEFAULT 1 NOT NULL,
                    executed_at VARCHAR(50) NOT NULL,
                    passed INTEGER DEFAULT 1 NOT NULL
                )
            ");
            return;
        }

        // Engine-aware bookkeeping table for every other adapter. Each engine
        // spells an auto-increment integer PK differently (SQLite AUTOINCREMENT,
        // PostgreSQL SERIAL, MySQL AUTO_INCREMENT, MSSQL IDENTITY). Mirrors
        // ORM::createTable()'s engine-aware DDL.
        $dialect = $this->detectDialect();

        $idColumn = match ($dialect) {
            'postgresql' => 'id SERIAL PRIMARY KEY',
            'mysql'      => 'id INTEGER PRIMARY KEY AUTO_INCREMENT',
            'mssql'      => 'id INTEGER IDENTITY(1,1) PRIMARY KEY',
            default      => 'id INTEGER PRIMARY KEY AUTOINCREMENT', // sqlite
        };

        $this->db->execute("
            CREATE TABLE " . self::MIGRATIONS_TABLE . " (
                {$idColumn},
                migration_name VARCHAR(500) NOT NULL UNIQUE,
                description VARCHAR(500),
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at VARCHAR(50) NOT NULL,
                passed INTEGER NOT NULL DEFAULT 1
            )
        ");
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
        // Delete-before-insert: supersede any existing row for this
        // migration_name first, so a leftover passed = 0 row (a prior failure,
        // or one carried over by the v2 -> v3 upgrade) re-applies cleanly
        // instead of colliding on the UNIQUE migration_name when the fresh
        // passed = 1 row is inserted. DELETE + INSERT is portable across every
        // engine (no UPSERT dialect variance). Invariant: the bookkeeping table
        // holds AT MOST ONE row per migration_name - latest state wins.
        $this->db->execute(
            "DELETE FROM " . self::MIGRATIONS_TABLE . " WHERE migration_name = :migration_name",
            [':migration_name' => $name]
        );

        // Canonical columns written on every shape: migration_name, description,
        // batch, executed_at, passed. `executed_at` is an explicit ISO-8601 UTC
        // string (never a DB CURRENT_TIMESTAMP default); `description` is derived
        // from the filename. Legacy columns retained by an in-place upgrade
        // (`migration_id`, `migration`) are populated too so their NOT NULL /
        // PRIMARY KEY constraints stay satisfied; the columns/values are built
        // per table shape so a fresh canonical table inserts only the 6 columns.
        $columns = [
            'migration_name' => $name,
            'description'    => $this->deriveDescription($name),
            'batch'          => $batch,
            'executed_at'    => gmdate('c'),
            'passed'         => $passed,
        ];

        // A v2-upgraded table keeps `migration_id VARCHAR(14) NOT NULL PRIMARY
        // KEY` — supply the 14-char timestamp prefix that WAS the v2 id. It has
        // no `id` column / generator, so it drives the PK itself.
        if ($this->hasLegacyMigrationIdColumn()) {
            $columns['migration_id'] = $this->legacyMigrationId($name);
        }

        // An older-v3-upgraded table keeps `migration ... NOT NULL UNIQUE` beside
        // the new `migration_name` — mirror the name into it so the NOT NULL
        // constraint is satisfied (it is otherwise ignored on reads).
        if ($this->hasLegacyMigrationColumn()) {
            $columns['migration'] = $name;
        }

        // Firebird has no AUTOINCREMENT — supply id from the generator. Skipped
        // for a v2-upgraded Firebird table (migration_id is its PK; it has no
        // `id` column nor the GEN_TINA4_MIGRATION_ID generator).
        if ($this->isFirebird() && !$this->hasLegacyMigrationIdColumn()) {
            $rows = $this->db->query(
                "SELECT GEN_ID(GEN_TINA4_MIGRATION_ID, 1) AS NEXT_ID FROM RDB\$DATABASE"
            );
            $columns['id'] = (int)($rows[0]['NEXT_ID'] ?? 1);
        }

        $names        = array_keys($columns);
        $placeholders = array_map(static fn($c) => ':' . $c, $names);
        $params       = [];
        foreach ($columns as $col => $value) {
            $params[':' . $col] = $value;
        }

        $this->db->execute(
            "INSERT INTO " . self::MIGRATIONS_TABLE
            . " (" . implode(', ', $names) . ") VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    /**
     * True when the tracking table still carries the older-v3 `migration` column
     * (retained beside `migration_name` by upgradeLegacyV3Schema as a NOT NULL
     * UNIQUE column). A new insert must keep populating it. Probed once — the
     * shape is fixed after ensureMigrationsTable().
     */
    private function hasLegacyMigrationColumn(): bool
    {
        if ($this->legacyMigrationColumn !== null) {
            return $this->legacyMigrationColumn;
        }
        return $this->legacyMigrationColumn = in_array('migration', $this->columnNames(), true);
    }

    /**
     * Derive a human-readable description from a migration filename: drop the
     * extension and the leading numeric/timestamp prefix, then turn underscores
     * into spaces. "20240101000000_create_users.sql" -> "create users".
     * Mirrors the Python master's description derivation.
     */
    private function deriveDescription(string $name): string
    {
        $stem = preg_replace('/\.(sql|php)$/i', '', $name);
        $stem = preg_replace('/^\d+_/', '', $stem, 1);
        return str_replace('_', ' ', $stem);
    }

    /**
     * True when the tracking table still carries the legacy v2 `migration_id`
     * column. An in-place upgrade (upgradeV2ToV3) leaves it in place as a
     * NOT NULL PRIMARY KEY, so a v3 insert must keep populating it. Probed once
     * per Migration instance — the tracking-table shape is fixed after
     * ensureMigrationsTable() and before any record is written.
     */
    private function hasLegacyMigrationIdColumn(): bool
    {
        if ($this->legacyMigrationIdColumn !== null) {
            return $this->legacyMigrationIdColumn;
        }
        return $this->legacyMigrationIdColumn = in_array('migration_id', $this->columnNames(), true);
    }

    /**
     * The v2 `migration_id` for a file: its leading timestamp digits
     * (`20260101000000_create_orders.sql` -> `20260101000000`), capped at the
     * VARCHAR(14) column width. Falls back to the first 14 characters of the
     * name when it carries no numeric prefix.
     */
    private function legacyMigrationId(string $name): string
    {
        if (preg_match('/^(\d{1,14})/', $name, $m)) {
            return $m[1];
        }
        return substr($name, 0, 14);
    }

    /**
     * Remove a migration record from the tracking table by name.
     *
     * @param string $name Migration name to remove.
     */
    public function removeMigrationRecord(string $name): void
    {
        $this->db->execute(
            "DELETE FROM " . self::MIGRATIONS_TABLE . " WHERE migration_name = :name",
            [':name' => $name]
        );
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatchNumber(): int
    {
        $rows = $this->queryLower(
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
        $rows = $this->queryLower(
            "SELECT MAX(batch) as max_batch FROM " . self::MIGRATIONS_TABLE . " WHERE passed = 1"
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
        return $this->queryLower(
            "SELECT migration_name, batch FROM " . self::MIGRATIONS_TABLE
            . " WHERE batch = :batch AND passed = 1 ORDER BY migration_name ASC",
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
     * Normalize smart/curly quotes back to straight ASCII quotes.
     *
     * Editors, word processors, docs and chat apps silently convert a straight
     * " to “ ” and a straight ' to ‘ ’ (plus primes ′ ″). Those code points are
     * NOT valid SQL string/identifier delimiters, so a pasted-in migration fails
     * to run ("syntax error near …"). Map them back to straight ASCII quotes —
     * only the lookalike code points are swapped, real string CONTENTS are
     * unchanged. Mirrors tina4-python's _normalize_quotes().
     */
    private static function normalizeQuotes(string $sql): string
    {
        return strtr($sql, [
            // Double-quote lookalikes → straight " (ASCII 0x22)
            "\u{201C}" => '"', // “ LEFT DOUBLE QUOTATION MARK
            "\u{201D}" => '"', // ” RIGHT DOUBLE QUOTATION MARK
            "\u{201E}" => '"', // „ DOUBLE LOW-9 QUOTATION MARK
            "\u{201F}" => '"', // ‟ DOUBLE HIGH-REVERSED-9 QUOTATION MARK
            "\u{2033}" => '"', // ″ DOUBLE PRIME
            // Single-quote/apostrophe lookalikes → straight ' (ASCII 0x27)
            "\u{2018}" => "'", // ‘ LEFT SINGLE QUOTATION MARK
            "\u{2019}" => "'", // ’ RIGHT SINGLE QUOTATION MARK
            "\u{201A}" => "'", // ‚ SINGLE LOW-9 QUOTATION MARK
            "\u{201B}" => "'", // ‛ SINGLE HIGH-REVERSED-9 QUOTATION MARK
            "\u{2032}" => "'", // ′ PRIME
        ]);
    }

    /**
     * Parse a `SET TERM <new> <current>` statement-terminator directive.
     *
     * `SET TERM` is a script-level directive (recognised by isql and other
     * InterBase/Firebird tooling, not run by the engine) that changes the
     * terminator separating statements. Recognising it lets a statement whose
     * own body contains the default `;` terminator — a trigger, stored
     * procedure or `EXECUTE BLOCK` — be kept intact rather than split on those
     * inner `;`. The terminator may be more than one character (e.g. `!!`).
     *
     * @param string $statement A single, already-trimmed statement.
     * @return string|null The new terminator, or null when $statement is not a
     *                     `SET TERM` directive.
     */
    private static function parseSetTerm(string $statement): ?string
    {
        if (preg_match('/^SET\s+TERM\s+(\S+)$/i', trim($statement), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Split a migration script into its individual statements.
     *
     * Statements are separated on the active terminator (the configured
     * delimiter, `;` by default), while dollar-quoted (`$$`) and slash-quoted
     * (`//`) blocks, quoted strings/identifiers, and line/block comments are
     * kept intact. A `SET TERM` directive switches the active terminator so a
     * statement whose body contains the default terminator survives as one.
     *
     * @param string $sql The raw migration script.
     * @return array<int, string> The trimmed statements, in order, with
     *                            terminators and `SET TERM` directives removed.
     */
    private function splitStatements(string $sql): array
    {
        // Normalize smart/curly quotes to straight ASCII first, so SQL pasted
        // from an editor/doc (which converts " → “ ” and ' → ‘ ’) actually
        // runs. Applied before splitting so both the migrate AND rollback paths
        // get normalized SQL.
        $sql = self::normalizeQuotes($sql);

        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $inDollarBlock = false;
        $inSlashBlock = false;

        // Active statement terminator. Starts as the configured delimiter; a
        // `SET TERM <new> <current>` directive can switch it mid-script so a
        // statement whose own body contains the default terminator (trigger /
        // procedure / EXECUTE BLOCK) stays intact. Multi-character terminators
        // (e.g. `!!`) are supported.
        $delimiter = $this->delimiter;

        while ($i < $len) {
            // Check for $$ delimiter (toggle in/out of dollar-quoted block)
            if (!$inSlashBlock && $i + 1 < $len && $sql[$i] === '$' && $sql[$i + 1] === '$') {
                $current .= '$$';
                $i += 2;
                $inDollarBlock = !$inDollarBlock;
                continue;
            }

            // Check for // delimiter (toggle in/out of slash-delimited block).
            // The `//` must NOT be preceded by a colon, so a URL scheme
            // (`https://…`) or any `://` literal inside a migration is never
            // treated as a stored-proc block delimiter (which would otherwise
            // swallow everything between two `//` occurrences and skip statement
            // splitting). Mirrors Python's negative lookbehind `(?<!:)//`.
            if (!$inDollarBlock && $i + 1 < $len && $sql[$i] === '/' && $sql[$i + 1] === '/'
                && !($i > 0 && $sql[$i - 1] === ':')) {
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

            // Block comment: /* ... */ — stripped (a ';' inside it is not a
            // delimiter; the comment text is dropped from the executed SQL).
            if ($i + 1 < $len && $sql[$i] === '/' && $sql[$i + 1] === '*') {
                $endPos = strpos($sql, '*/', $i + 2);
                $i = ($endPos !== false) ? $endPos + 2 : $len;
                continue;
            }

            // Line comment: -- ... — stripped to end of line. The newline is left
            // for the next iteration so line structure (and NEXT-line statement
            // boundaries) survive, and a ';' inside the comment never splits.
            if ($i + 1 < $len && $sql[$i] === '-' && $sql[$i + 1] === '-') {
                $endPos = strpos($sql, "\n", $i + 2);
                $i = ($endPos !== false) ? $endPos : $len;
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

            // Statement delimiter (multi-character aware). A `SET TERM`
            // directive is consumed (never emitted as SQL) and switches the
            // active terminator; every other completed statement is collected.
            $dlen = strlen($delimiter);
            if ($dlen > 0 && $sql[$i] === $delimiter[0] && substr($sql, $i, $dlen) === $delimiter) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $newTerm = self::parseSetTerm($trimmed);
                    if ($newTerm !== null) {
                        $delimiter = $newTerm;
                    } else {
                        $statements[] = $trimmed;
                    }
                }
                $current = '';
                $i += $dlen;
                continue;
            }

            $current .= $sql[$i];
            $i++;
        }

        // Don't forget the last statement (may not end with delimiter). A
        // trailing `SET TERM` directive is a no-op — consume it, don't emit it.
        $trimmed = trim($current);
        if ($trimmed !== '' && self::parseSetTerm($trimmed) === null) {
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

    /** Pattern to match CREATE TABLE <name> — name may be quoted ("x"),
     *  bracketed ([x] MSSQL) or bare. An optional IF NOT EXISTS is skipped. */
    private const CREATE_TABLE_PATTERN =
        '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|\[([^\]]+)\]|(\w+))/i';

    /**
     * Check if the database adapter is Firebird.
     */
    private function isFirebird(): bool
    {
        // Resolved via detectDialect() so it also matches when $this->db is a
        // Database facade wrapping the concrete adapter (Database::create/fromEnv),
        // which a bare `instanceof FirebirdAdapter` would not.
        return $this->detectDialect() === 'firebird';
    }

    /**
     * Check if the database adapter is MSSQL.
     */
    private function isMSSQL(): bool
    {
        // Resolved via detectDialect() so it unwraps the Database facade — see isFirebird().
        return $this->detectDialect() === 'mssql';
    }

    /**
     * Resolve the SQL dialect for engine-aware DDL (the bookkeeping table),
     * unwrapping any Database / CachedDatabase facade to the concrete adapter.
     * Mirrors ORM::detectDialect(). Returns postgresql | mysql | mssql |
     * firebird | sqlite (sqlite is the safe default).
     */
    private function detectDialect(): string
    {
        $adapter = $this->db;
        while (is_object($adapter) && method_exists($adapter, 'getAdapter')) {
            $next = $adapter->getAdapter();
            if ($next === $adapter || !$next instanceof \Tina4\Database\DatabaseAdapter) {
                break;
            }
            $adapter = $next;
        }

        return match (true) {
            $adapter instanceof \Tina4\Database\PostgresAdapter,
            $adapter instanceof \Tina4\Database\PdoPostgresAdapter => 'postgresql',
            $adapter instanceof \Tina4\Database\MySQLAdapter    => 'mysql',
            $adapter instanceof \Tina4\Database\MSSQLAdapter    => 'mssql',
            $adapter instanceof \Tina4\Database\FirebirdAdapter,
            $adapter instanceof \Tina4\Database\PdoFirebirdAdapter => 'firebird',
            // PdoSqliteAdapter (and the native SQLite3Adapter) fall through to
            // the sqlite default. The pdo_* fallback adapters MUST be matched
            // here or engine-aware DDL (e.g. the bookkeeping table) misfires —
            // pdo_firebird would otherwise emit SQLite AUTOINCREMENT on Firebird.
            default => 'sqlite',
        };
    }

    /**
     * Make CREATE TABLE idempotent on engines lacking IF NOT EXISTS.
     *
     * Firebird and MSSQL do not support `CREATE TABLE IF NOT EXISTS`, so a raw
     * CREATE in a re-run migration raises "object already exists". When the
     * target table already exists on those engines, return a skip reason so the
     * statement is skipped (mirrors the Firebird ALTER-TABLE-ADD idempotency
     * guard). SQLite/MySQL/PostgreSQL support IF NOT EXISTS and are left to the
     * engine. Only a genuine already-exists is skipped — every other error still
     * raises.
     */
    private function shouldSkipCreateTable(string $statement): ?string
    {
        if (!$this->isFirebird() && !$this->isMSSQL()) {
            return null;
        }

        if (!preg_match(self::CREATE_TABLE_PATTERN, $statement, $m)) {
            return null;
        }

        // The matched group is whichever of quoted/bracketed/bare is non-empty.
        $table = '';
        for ($i = 1; $i <= 3; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $table = $m[$i];
                break;
            }
        }
        if ($table === '') {
            return null;
        }

        try {
            if ($this->db->tableExists($table)) {
                return "Table {$table} already exists, skipping CREATE TABLE";
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
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
