<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency fake data generator and database seeder.
 *
 * Generate realistic fake data for testing and development.
 *
 *     $fake  = new FakeData();
 *     $name  = $fake->name();   // "Alice Johnson"
 *     $email = $fake->email();      // "alice.johnson@example.com"
 *
 *     // Deterministic output with a seed:
 *     $fake  = new FakeData(42);
 *     $name  = $fake->name();   // same every time
 */
class FakeData
{
    // ── Word banks ──────────────────────────────────────────────

    private const FIRST_NAMES = [
        'Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry',
        'Ivy', 'Jack', 'Kate', 'Leo', 'Mia', 'Noah', 'Olivia', 'Pete',
        'Quinn', 'Rose', 'Sam', 'Tina', 'Uma', 'Vince', 'Wendy', 'Xander',
        'Yara', 'Zane', 'Anna', 'Ben', 'Chloe', 'Dan', 'Emma', 'Felix',
        'Gina', 'Hugo', 'Iris', 'James', 'Kira', 'Liam', 'Maya', 'Nate',
        'Opal', 'Paul', 'Rita', 'Sean', 'Tara', 'Uri', 'Vera', 'Wade',
        'Xena', 'Yuri', 'Zara', 'Aiden', 'Bella', 'Caleb',
    ];

    private const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
        'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson',
        'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee',
        'Perez', 'Thompson', 'White', 'Harris', 'Clark', 'Lewis', 'Young',
        'Walker', 'Hall', 'Allen', 'King', 'Wright', 'Scott', 'Green',
        'Baker', 'Adams', 'Nelson', 'Hill', 'Ramirez', 'Campbell', 'Mitchell',
        'Roberts', 'Carter', 'Phillips', 'Evans', 'Turner', 'Torres', 'Parker',
        'Collins', 'Edwards', 'Stewart', 'Morris',
    ];

    private const DOMAINS = [
        'example.com', 'test.org', 'demo.net', 'mail.dev', 'inbox.io',
        'sample.com', 'tryout.org', 'fakemail.net',
    ];

    private const WORDS = [
        'the', 'quick', 'brown', 'fox', 'jumps', 'over', 'lazy', 'dog',
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
        'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore',
        'magna', 'aliqua', 'enim', 'minim', 'veniam', 'quis', 'nostrud',
        'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'commodo',
    ];

    private const CITIES = [
        'New York', 'London', 'Tokyo', 'Paris', 'Sydney', 'Berlin', 'Toronto',
        'Cape Town', 'Mumbai', 'Singapore', 'Dubai', 'Amsterdam', 'Seoul',
        'Los Angeles', 'Chicago', 'Houston', 'Madrid', 'Rome',
    ];

    private const COUNTRIES = [
        'United States', 'United Kingdom', 'Canada', 'Australia', 'Germany',
        'France', 'Japan', 'India', 'Brazil', 'South Africa', 'Netherlands',
        'Spain', 'Italy', 'Mexico', 'South Korea', 'Singapore',
    ];

    private const STREETS = [
        'Main St', 'Oak Ave', 'Park Rd', 'Cedar Ln', 'Elm St', 'Pine Dr',
        'Maple Way', 'River Rd', 'Lake Blvd', 'Hill Ct', 'Valley View',
        'Broadway', 'Church St', 'High St', 'Mill Rd',
    ];

    private const JOB_TITLES = [
        'Software Engineer', 'Product Manager', 'Data Analyst', 'Designer',
        'Marketing Manager', 'Sales Representative', 'Accountant', 'HR Manager',
        'Project Manager', 'DevOps Engineer', 'QA Tester', 'Business Analyst',
        'CTO', 'CEO', 'CFO', 'Consultant', 'Teacher', 'Nurse', 'Doctor',
        'Architect', 'Writer', 'Journalist', 'Photographer', 'Chef',
    ];

    private const COMPANY_SUFFIXES = [
        'Inc', 'LLC', 'Corp', 'Ltd', 'Group', 'Solutions', 'Technologies',
        'Systems', 'Services', 'Partners', 'Labs', 'Industries',
    ];

    private const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
        'SEK', 'NZD', 'ZAR', 'BRL', 'INR', 'KRW', 'MXN',
    ];

    // ── Instance state ────────────────────────────────────────────

    private ?int $seed;

    // ── Constructor ───────────────────────────────────────────────

    public function __construct(?int $seed = null)
    {
        $this->seed = $seed;
        if ($seed !== null) {
            mt_srand($seed);
        }
    }

    /**
     * Static factory — create a seeded FakeData instance.
     *
     *     $fake = FakeData::seed(42);
     *     $fake->name(); // deterministic
     */
    public static function seed(int $seed): self
    {
        return new self($seed);
    }

    // ── Internal helpers ──────────────────────────────────────────

    /**
     * Generate a random integer in the given range using the seedable MT engine.
     */
    private function rand(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /**
     * Pick a random element from an array.
     */
    private function pick(array $list): string
    {
        return $list[$this->rand(0, count($list) - 1)];
    }

    // ── Generators ──────────────────────────────────────────────

    public function firstName(): string
    {
        return $this->pick(self::FIRST_NAMES);
    }

    public function lastName(): string
    {
        return $this->pick(self::LAST_NAMES);
    }

    public function email(): string
    {
        $first = strtolower($this->firstName());
        $last = strtolower($this->lastName());
        $domain = $this->pick(self::DOMAINS);
        return $first . '.' . $last . '@' . $domain;
    }

    public function phone(): string
    {
        $area = $this->rand(200, 999);
        $mid = $this->rand(100, 999);
        $end = $this->rand(1000, 9999);
        return sprintf('+1 (%03d) %03d-%04d', $area, $mid, $end);
    }

    public function address(): string
    {
        $num = $this->rand(1, 999);
        $street = $this->pick(self::STREETS);
        $city = $this->pick(self::CITIES);
        return $num . ' ' . $street . ', ' . $city;
    }

    public function city(): string
    {
        return $this->pick(self::CITIES);
    }

    public function country(): string
    {
        return $this->pick(self::COUNTRIES);
    }

    public function zipCode(): string
    {
        return sprintf('%05d', $this->rand(10000, 99999));
    }

    public function company(): string
    {
        $last = $this->lastName();
        $suffix = $this->pick(self::COMPANY_SUFFIXES);
        return $last . ' ' . $suffix;
    }

    public function jobTitle(): string
    {
        return $this->pick(self::JOB_TITLES);
    }

    public function paragraph(int $sentences = 3): string
    {
        $parts = [];
        for ($i = 0; $i < $sentences; $i++) {
            $parts[] = $this->sentence($this->rand(5, 12));
        }
        return implode(' ', $parts);
    }

    public function sentence(int $words = 8): string
    {
        $w = [];
        for ($i = 0; $i < $words; $i++) {
            $w[] = $this->word();
        }
        return ucfirst(implode(' ', $w)) . '.';
    }

    public function word(): string
    {
        return $this->pick(self::WORDS);
    }

    public function integer(int $min = 0, int $max = 1000): int
    {
        return $this->rand($min, $max);
    }

    public function boolean(): bool
    {
        return (bool)$this->rand(0, 1);
    }

    public function date(string $start = '2020-01-01', string $end = '2025-12-31'): string
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return date('Y-m-d');
        }
        $ts = $this->rand($startTs, $endTs);
        return date('Y-m-d', $ts);
    }

    public function uuid(): string
    {
        // Build 16 random bytes using the seedable MT engine
        $data = '';
        for ($i = 0; $i < 16; $i++) {
            $data .= chr($this->rand(0, 255));
        }
        // Set version 4
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant 10xx
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }

    public function url(): string
    {
        $domain = $this->pick(self::DOMAINS);
        $path = $this->word() . '/' . $this->word();
        return 'https://' . $domain . '/' . $path;
    }

    public function ipAddress(): string
    {
        return $this->rand(1, 255) . '.' . $this->rand(0, 255) . '.' .
               $this->rand(0, 255) . '.' . $this->rand(1, 254);
    }

    public function colorHex(): string
    {
        return sprintf('#%06x', $this->rand(0, 0xFFFFFF));
    }

    /**
     * Generate a fake credit card number (test numbers only, e.g. 4111...).
     */
    public function creditCard(): string
    {
        $prefixes = ['4111', '4242', '5500', '5105'];
        $prefix = $prefixes[$this->rand(0, count($prefixes) - 1)];
        $rest = '';
        for ($i = 0; $i < 12; $i++) {
            $rest .= $this->rand(0, 9);
        }
        return $prefix . $rest;
    }

    public function currency(): string
    {
        return $this->pick(self::CURRENCIES);
    }

    /**
     * Returns a full name — matches Python's name() method.
     */
    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    /**
     * Returns multi-paragraph text.
     *
     * @param int $paragraphs Number of paragraphs to generate
     */
    public function text(int $paragraphs = 3): string
    {
        $parts = [];
        for ($i = 0; $i < $paragraphs; $i++) {
            $parts[] = $this->paragraph();
        }
        return implode("\n\n", $parts);
    }

    /**
     * Returns a random element from the given array.
     * Matches Python's choice() method.
     *
     * @param array $items
     */
    public function choice(array $items): mixed
    {
        return $items[$this->rand(0, count($items) - 1)];
    }

    /**
     * Random float in range with specified decimals. Matches Python's numeric().
     *
     * @param float $min
     * @param float $max
     * @param int   $decimals
     */
    public function numeric(float $min = 0.0, float $max = 1000.0, int $decimals = 2): float
    {
        $rand = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($rand, $decimals);
    }

    /**
     * Seed a database table with fake data using raw SQL inserts.
     *
     * Visible-but-resilient (P1): each row is wrapped. On a row failure the
     * cause is logged (with the row index) and the row is skipped — unless
     * $strict is true, in which case the FIRST failure RE-RAISES. At the end a
     * one-line summary is logged ("seeded N, M failed"). This replaces the old
     * silent-swallow behaviour: never silent, never fragile.
     *
     * @param mixed  $db        Database instance with execute() and commit() methods
     * @param string $tableName Name of the table to seed
     * @param int    $count     Number of rows to insert
     * @param array  $fieldMap  Associative array of column_name => callable|value
     * @param array  $overrides Static values applied to every row (override fieldMap)
     * @param bool   $clear     P2 — if true, delete every existing row in the
     *                          table before seeding so re-runs don't duplicate
     *                          rows or trip unique-PK violations.
     * @param int|null $seed    P3 — optional PRNG seed. Provided for signature
     *                          parity with seedOrm; seedTable's determinism comes
     *                          from the caller's FakeData passed via $fieldMap
     *                          (seed a FakeData(N) and pass its generators).
     * @param bool   $strict    P1 — if true, re-raise on the first failed row
     *                          instead of skipping.
     *
     * @return SeedSummary {seeded, failed, errors} — also usable as the int
     *                     row-count for backward compatibility ((int) cast and
     *                     count() both yield `seeded`).
     */
    public static function seedTable(
        mixed $db,
        string $tableName,
        int $count = 10,
        array $fieldMap = [],
        array $overrides = [],
        bool $clear = false,
        ?int $seed = null,
        bool $strict = false
    ): SeedSummary {
        if (empty($fieldMap)) {
            return new SeedSummary(0, 0, []);
        }

        if ($clear) {
            self::clearTable($db, $tableName);
        }

        $seeded = 0;
        $failed = 0;
        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $row = [];
                foreach ($fieldMap as $col => $generator) {
                    $row[$col] = is_callable($generator) ? $generator() : $generator;
                }
                foreach ($overrides as $col => $value) {
                    $row[$col] = is_callable($value) ? $value() : $value;
                }

                $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($row)));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $values = array_values($row);

                $ok = $db->execute("INSERT INTO `$tableName` ($cols) VALUES ($placeholders)", $values);
                // Some adapters return false (not raise) on a bad statement —
                // convert that to a counted failure so it never passes as success.
                if ($ok === false) {
                    $cause = (is_object($db) && method_exists($db, 'getError')) ? $db->getError() : null;
                    throw new \RuntimeException(
                        "execute() returned false inserting into '{$tableName}'"
                        . ($cause ? ": {$cause}" : '')
                    );
                }
                $seeded++;
            } catch (\Throwable $e) {
                if ($strict) {
                    Log::error("Seeder: row {$i} failed seeding '{$tableName}' (strict): " . $e->getMessage());
                    throw $e;
                }
                $failed++;
                $errors[] = ['row' => $i, 'message' => $e->getMessage()];
                Log::warning("Seeder: row {$i} failed seeding '{$tableName}', skipped: " . $e->getMessage());
            }
        }

        try {
            $db->commit();
        } catch (\Throwable $e) {
            // Autocommit-on engines / pooled standalone writes may not need an
            // explicit commit; never let the summary itself crash.
        }

        Log::info("Seeder: '{$tableName}' — seeded {$seeded}, {$failed} failed");
        return new SeedSummary($seeded, $failed, $errors);
    }

    /**
     * Delete every row in a table. Tolerant — logs and continues on error (P2).
     */
    private static function clearTable(mixed $db, string $tableName): void
    {
        try {
            $db->delete($tableName, '1=1');
        } catch (\Throwable $e) {
            Log::warning("Seeder: could not clear '{$tableName}': " . $e->getMessage());
        }
    }

    // ── Seeder runner ───────────────────────────────────────────

    /**
     * Discover and run seed files from a directory.
     *
     * Each seed file should return a callable that produces a single row as an associative array.
     *
     * @param string $seedDir Directory containing seed PHP files
     * @return array Summary of seeded files
     */
    public function seedDir(string $seedDir = 'src/seeds'): array
    {
        $results = [];

        if (!is_dir($seedDir)) {
            return $results;
        }

        $files = glob($seedDir . '/*.php');
        if ($files === false) {
            return $results;
        }

        sort($files);

        foreach ($files as $file) {
            $seeder = require $file;
            if (is_callable($seeder)) {
                $results[basename($file)] = 'loaded';
            }
        }

        return $results;
    }

    /**
     * Generate a datetime string like "2023-04-15 14:32:07".
     *
     * @param int $startYear Start year (default 2020)
     * @param int $endYear   End year (default 2025)
     */
    public function datetime(int $startYear = 2020, int $endYear = 2025): string
    {
        $startTs = mktime(0, 0, 0, 1, 1, $startYear);
        $endTs   = mktime(23, 59, 59, 12, 31, $endYear);
        $ts      = $this->rand($startTs, $endTs);
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Generate a fake value from a field definition array and optional column name.
     *
     * Mirrors Python's FakeData.for_field().
     *
     * @param array  $fieldDef   Field definition with keys: type, primary_key, auto_increment, max_length, min, max
     * @param string $columnName Optional column name for heuristic matching
     *
     * @return mixed Generated value, or null if the field is an auto-increment primary key
     */
    public function forField(array $fieldDef, string $columnName = ''): mixed
    {
        if (!empty($fieldDef['primary_key']) && !empty($fieldDef['auto_increment'])) {
            return null;
        }

        $col   = strtolower($columnName);
        $ftype = strtolower($fieldDef['type'] ?? 'string');

        // Column name heuristics
        if ($col !== '') {
            if (str_contains($col, 'email')) {
                return $this->email();
            }
            if (str_contains($col, 'phone') || str_contains($col, 'tel') || str_contains($col, 'mobile')) {
                return $this->phone();
            }
            if (in_array($col, ['full_name', 'fullname', 'name'], true)) {
                return $this->name();
            }
            if (str_contains($col, 'first_name') || (str_contains($col, 'first') && str_contains($col, 'name'))) {
                return $this->firstName();
            }
            if (str_contains($col, 'last_name') || (str_contains($col, 'last') && str_contains($col, 'name'))) {
                return $this->lastName();
            }
            if (str_contains($col, 'address') || str_contains($col, 'street')) {
                return $this->address();
            }
            if (str_contains($col, 'city') || str_contains($col, 'town')) {
                return $this->city();
            }
            if (str_contains($col, 'country')) {
                return $this->country();
            }
            if (str_contains($col, 'zip') || str_contains($col, 'postal')) {
                return $this->zipCode();
            }
            if (str_contains($col, 'company') || str_contains($col, 'org')) {
                return $this->company();
            }
            if (str_contains($col, 'url') || str_contains($col, 'website') || str_contains($col, 'link')) {
                return $this->url();
            }
            if (str_contains($col, 'uuid') || str_contains($col, 'guid')) {
                return $this->uuid();
            }
        }

        // Field type fallback
        return match (true) {
            in_array($ftype, ['string', 'text'], true) => $this->sentence(),
            $ftype === 'integer'                        => $this->integer(
                (int) ($fieldDef['min'] ?? 0),
                (int) ($fieldDef['max'] ?? 1000)
            ),
            in_array($ftype, ['numeric', 'float', 'decimal'], true) => $this->numeric(
                (float) ($fieldDef['min'] ?? 0.0),
                (float) ($fieldDef['max'] ?? 1000.0)
            ),
            $ftype === 'boolean'  => $this->boolean(),
            $ftype === 'datetime' => $this->datetime(),
            $ftype === 'date'     => $this->date(),
            default               => $this->word(),
        };
    }

    /**
     * Seed an ORM model class with auto-generated fake data.
     *
     * Mirrors Python's seed_orm(). Visible-but-resilient (P1): each row is
     * wrapped. On a row failure the cause is logged (with the row index) and
     * the row is skipped — unless $strict is true, in which case the FIRST
     * failure RE-RAISES. A one-line summary is logged at the end.
     *
     * Key detail (P1): ORM::save() returns false (not raise) on a constraint
     * failure because it does its own rollback internally — so a falsy save()
     * is converted to a counted failure (it does NOT pass as success).
     *
     * @param string   $ormClass  Fully-qualified ORM class name (e.g. User::class)
     * @param int      $count     Number of records to insert
     * @param array    $overrides Field overrides — static values or callables receiving a FakeData instance
     * @param bool     $clear     P2 — if true, delete all existing records before seeding
     * @param int|null $seed      P3 — optional PRNG seed for reproducible output
     * @param bool     $strict    P1 — if true, re-raise on the first failed row instead of skipping
     *
     * @return SeedSummary {seeded, failed, errors} — also usable as the int
     *                     row-count for backward compatibility.
     */
    public static function seedOrm(
        string $ormClass,
        int $count = 10,
        array $overrides = [],
        bool $clear = false,
        ?int $seed = null,
        bool $strict = false
    ): SeedSummary {
        $fake = new self($seed);
        $orm  = new $ormClass();

        $fieldDefs = method_exists($orm, 'getFieldDefinitions')
            ? $orm->getFieldDefinitions()
            : [];

        if (empty($fieldDefs)) {
            Log::error("Seeder: No fields found on {$ormClass}");
            return new SeedSummary(0, 0, []);
        }

        if ($clear) {
            self::clearOrm($ormClass);
        }

        // P4a — resolve FK columns to REAL parent PKs so a child row references
        // an existing parent. Snapshotted once (parents are seeded first by
        // seedModels's topo-sort, so the parent table is populated by now).
        $fkPools = self::foreignKeyPools($ormClass, $fieldDefs);

        $seeded = 0;
        $failed = 0;
        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $attrs = [];
                foreach ($fieldDefs as $name => $fieldDef) {
                    // Skip auto-increment / database-generated PKs.
                    if (!empty($fieldDef['primary_key']) && !empty($fieldDef['auto_increment'])) {
                        continue;
                    }
                    if (array_key_exists($name, $overrides)) {
                        $val = $overrides[$name];
                        $attrs[$name] = is_callable($val) ? $val($fake) : $val;
                    } elseif (!empty($fkPools[$name])) {
                        $attrs[$name] = $fake->choice($fkPools[$name]);
                    } else {
                        $attrs[$name] = self::generateForField($fake, $fieldDef, $name);
                    }
                }

                self::validateTypes($fieldDefs, $attrs, $ormClass);

                $instance = new $ormClass($attrs);
                // save() returns $this on success, false on failure (it rolls
                // back internally). A falsy save is a counted failure — never
                // let it slip through as success.
                if ($instance->save() === false) {
                    throw new \RuntimeException("save() returned false for {$ormClass}");
                }
                $seeded++;
            } catch (\Throwable $e) {
                if ($strict) {
                    Log::error("Seeder: row {$i} failed seeding {$ormClass} (strict): " . $e->getMessage());
                    throw $e;
                }
                $failed++;
                $errors[] = ['row' => $i, 'message' => $e->getMessage()];
                Log::warning("Seeder: row {$i} failed seeding {$ormClass}, skipped: " . $e->getMessage());
            }
        }

        Log::info("Seeder: {$ormClass} — seeded {$seeded}, {$failed} failed");
        return new SeedSummary($seeded, $failed, $errors);
    }

    /**
     * Batch-seed several ORM models, ordering by their foreignKeys dependency
     * graph (P4a).
     *
     * Parent tables seed before children (topological sort over the
     * $foreignKeys declarations); when $clear is true the clear runs in the
     * REVERSE order so children are removed before parents — no FK violations
     * regardless of the order the caller lists the models in.
     *
     * @param array<int, string> $ormClasses List of ORM class names to seed.
     * @param int                $count      Rows per model.
     * @param array              $overrides  Per-model overrides as
     *                                       [ClassName => [field => value]] or a
     *                                       single flat dict applied to every model.
     * @param bool               $clear      Clear each table first (reverse-topo order).
     * @param int|null           $seed       PRNG seed (P3) — applied per model.
     * @param bool               $strict     Re-raise on the first failed row.
     *
     * @return array<string, SeedSummary> [ClassName => SeedSummary] per model.
     */
    public static function seedModels(
        array $ormClasses,
        int $count = 10,
        array $overrides = [],
        bool $clear = false,
        ?int $seed = null,
        bool $strict = false
    ): array {
        $ordered = self::topoSortModels($ormClasses);

        if ($clear) {
            foreach (array_reverse($ordered) as $model) {
                self::clearOrm($model);
            }
        }

        $results = [];
        foreach ($ordered as $model) {
            $short = self::shortClassName($model);
            // Per-model overrides keyed by class name (short or FQN), else a
            // flat override dict applied to every model.
            $modelOverrides = $overrides;
            if (array_key_exists($model, $overrides) && is_array($overrides[$model])) {
                $modelOverrides = $overrides[$model];
            } elseif (array_key_exists($short, $overrides) && is_array($overrides[$short])) {
                $modelOverrides = $overrides[$short];
            }
            $results[$short] = self::seedOrm(
                $model,
                $count,
                $modelOverrides,
                false,
                $seed,
                $strict
            );
        }
        return $results;
    }

    /**
     * Delete every row backing an ORM model. Tolerant — logs and continues (P2).
     */
    private static function clearOrm(string $ormClass): void
    {
        try {
            $orm = new $ormClass();
            $db = method_exists($orm, 'getDb') ? $orm->getDb() : null;
            if ($db === null && method_exists($ormClass, 'getGlobalDb')) {
                $db = $ormClass::getGlobalDb();
            }
            if ($db !== null) {
                $db->delete($orm->tableName, '1=1');
            }
        } catch (\Throwable $e) {
            Log::warning("Seeder: could not clear {$ormClass}: " . $e->getMessage());
        }
    }

    /**
     * For each foreign-key column on the model, fetch the existing primary-key
     * values of the referenced table so seeded child rows reference a real
     * parent (P4a). Returns [propertyName => [pkValue, ...]]; columns with no
     * resolvable / empty parent table are omitted.
     *
     * @param string $ormClass
     * @param array  $fieldDefs Output of ORM::getFieldDefinitions()
     * @return array<string, array<int, mixed>>
     */
    private static function foreignKeyPools(string $ormClass, array $fieldDefs): array
    {
        $pools = [];
        $orm = new $ormClass();
        $db = method_exists($orm, 'getDb') ? $orm->getDb() : null;
        if ($db === null && method_exists($ormClass, 'getGlobalDb')) {
            $db = $ormClass::getGlobalDb();
        }
        if ($db === null) {
            return $pools;
        }

        $ns = self::classNamespace($ormClass);

        foreach ($fieldDefs as $name => $fieldDef) {
            $fk = $fieldDef['foreign_key'] ?? null;
            if (empty($fk) || empty($fk['model'])) {
                continue;
            }
            try {
                $targetClass = self::resolveModelClass($fk['model'], $ns);
                if ($targetClass === null) {
                    continue;
                }
                $target = new $targetClass();
                $pkColumn = method_exists($target, 'getDbColumn')
                    ? $target->getDbColumn($target->primaryKey)
                    : $target->primaryKey;
                // query() returns a plain array of rows on every adapter AND
                // the Database facade (fetch() shape differs between the two).
                $records = $db->query("SELECT {$pkColumn} FROM {$target->tableName}");
                $values = [];
                foreach ($records as $r) {
                    if (isset($r[$pkColumn]) && $r[$pkColumn] !== null) {
                        $values[] = $r[$pkColumn];
                    }
                }
                if (!empty($values)) {
                    $pools[$name] = $values;
                }
            } catch (\Throwable $e) {
                Log::warning("Seeder: could not resolve FK pool for {$name}: " . $e->getMessage());
            }
        }
        return $pools;
    }

    /**
     * Topologically sort ORM model class names so parents (referenced tables)
     * come before children (tables with a foreign key pointing at them).
     *
     * Uses the models' $foreignKeys declarations. Models not in the input list
     * are ignored as dependencies (you only seed what you pass). Cycles /
     * unresolved deps fall back to the caller's declared order for the
     * remainder so nothing is ever dropped (parity with Python's Kahn-style
     * stable topo).
     *
     * @param array<int, string> $ormClasses
     * @return array<int, string>
     */
    private static function topoSortModels(array $ormClasses): array
    {
        // Dedupe, preserve order.
        $inSet = array_values(array_unique($ormClasses));

        // Map both FQN and short name → canonical class in the input set.
        $nameToModel = [];
        foreach ($inSet as $model) {
            $nameToModel[$model] = $model;
            $nameToModel[self::shortClassName($model)] = $model;
        }

        $depsMap = [];
        foreach ($inSet as $model) {
            $deps = [];
            try {
                $orm = new $model();
                foreach (($orm->foreignKeys ?? []) as $fkColumn => $config) {
                    $refName = is_string($config) ? $config : ($config['model'] ?? '');
                    if ($refName === '') {
                        continue;
                    }
                    $target = $nameToModel[$refName]
                        ?? $nameToModel[self::shortClassName($refName)]
                        ?? null;
                    if ($target !== null && $target !== $model) {
                        $deps[$target] = true;
                    }
                }
            } catch (\Throwable $e) {
                // A model that can't be instantiated has no resolvable deps.
            }
            $depsMap[$model] = $deps;
        }

        $ordered = [];
        $placed = [];
        $remaining = $inSet;
        $progressed = true;
        while (!empty($remaining) && $progressed) {
            $progressed = false;
            $still = [];
            foreach ($remaining as $model) {
                $depsSatisfied = true;
                foreach (array_keys($depsMap[$model]) as $dep) {
                    if (!isset($placed[$dep])) {
                        $depsSatisfied = false;
                        break;
                    }
                }
                if ($depsSatisfied) {
                    $ordered[] = $model;
                    $placed[$model] = true;
                    $progressed = true;
                } else {
                    $still[] = $model;
                }
            }
            $remaining = $still;
        }
        // Cycle / unresolved deps — append in declared order so nothing drops.
        foreach ($remaining as $model) {
            $ordered[] = $model;
        }
        return $ordered;
    }

    /**
     * P4c — when a generated value's PHP type clearly mismatches the target
     * column type, LOG a warning. Never hard-fails (an int landing in a bool
     * column is fine since bool maps to int in most engines).
     *
     * @param array  $fieldDefs Output of ORM::getFieldDefinitions()
     * @param array  $attrs     Generated attributes about to be inserted
     * @param string $modelName
     */
    private static function validateTypes(array $fieldDefs, array $attrs, string $modelName): void
    {
        $expectedPhp = ['int' => 'integer', 'float' => 'double', 'bool' => 'boolean'];
        foreach ($attrs as $name => $value) {
            if ($value === null) {
                continue;
            }
            $def = $fieldDefs[$name] ?? null;
            if ($def === null) {
                continue;
            }
            $logical = $def['type'] ?? 'string';
            if (!isset($expectedPhp[$logical])) {
                continue;
            }
            $actual = gettype($value);
            // int → bool column and bool → int column are both acceptable.
            if (in_array($logical, ['int', 'bool'], true)
                && in_array($actual, ['integer', 'boolean'], true)) {
                continue;
            }
            // int passed to a float column is fine.
            if ($logical === 'float' && in_array($actual, ['integer', 'double'], true)) {
                continue;
            }
            $expected = $expectedPhp[$logical];
            if ($actual !== $expected) {
                Log::warning(
                    "Seeder: {$modelName}.{$name} expected {$expected} but generated "
                    . "{$actual} (" . var_export($value, true) . ") — inserting anyway"
                );
            }
        }
    }

    /**
     * Generate a fake value for an ORM field definition + property name.
     * Mirrors Python's _generate_for_field(). Maps the logical type
     * (int|float|bool|datetime|string) plus name heuristics to a generator.
     */
    private static function generateForField(self $fake, array $fieldDef, string $col): mixed
    {
        $colLower = strtolower(self::camelToSnakeCol($col));
        $type = $fieldDef['type'] ?? 'string';

        if ($type === 'int') {
            if (str_contains($colLower, 'age')) {
                return $fake->integer(18, 85);
            }
            if (str_contains($colLower, 'year')) {
                return $fake->integer(1950, 2025);
            }
            return $fake->integer(1, 10000);
        }

        if ($type === 'float') {
            return $fake->numeric(0.01, 9999.99, 2);
        }

        if ($type === 'datetime') {
            return $fake->datetime();
        }

        if ($type === 'bool') {
            return $fake->boolean();
        }

        // string / text and everything else
        if (str_contains($colLower, 'email')) {
            return $fake->email();
        }
        if (in_array($colLower, ['name', 'full_name', 'fullname'], true)) {
            return $fake->name();
        }
        if (str_contains($colLower, 'first') && str_contains($colLower, 'name')) {
            return $fake->firstName();
        }
        if (str_contains($colLower, 'last') && str_contains($colLower, 'name')) {
            return $fake->lastName();
        }
        foreach (['phone', 'tel', 'mobile'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->phone();
            }
        }
        foreach (['url', 'website', 'link'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->url();
            }
        }
        foreach (['address', 'street'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->address();
            }
        }
        foreach (['city', 'town'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->city();
            }
        }
        if (str_contains($colLower, 'country')) {
            return $fake->country();
        }
        foreach (['zip', 'postal'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->zipCode();
            }
        }
        foreach (['company', 'org'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->company();
            }
        }
        foreach (['color', 'colour'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->colorHex();
            }
        }
        foreach (['uuid', 'guid'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->uuid();
            }
        }
        foreach (['description', 'summary', 'bio', 'about', 'content', 'body'] as $needle) {
            if (str_contains($colLower, $needle)) {
                return $fake->paragraph();
            }
        }
        return $fake->sentence();
    }

    // ── Seeder internal helpers ─────────────────────────────────────

    /** Normalise a property name to snake_case for heuristic matching. */
    private static function camelToSnakeCol(string $name): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    /** Short (unqualified) class name from a possibly-namespaced class name. */
    private static function shortClassName(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** Namespace prefix (with trailing backslash) of a class, or '' if none. */
    private static function classNamespace(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? '' : substr($class, 0, $pos + 1);
    }

    /**
     * Resolve a referenced-model name (short or FQN) to a loadable class name,
     * trying the FQN as-is then within the declaring class's namespace.
     */
    private static function resolveModelClass(string $model, string $namespace): ?string
    {
        if (class_exists($model)) {
            return $model;
        }
        $candidate = $namespace . self::shortClassName($model);
        if (class_exists($candidate)) {
            return $candidate;
        }
        if (class_exists('\\' . $model)) {
            return '\\' . $model;
        }
        return null;
    }

    /**
     * Run a callable seeder function a given number of times.
     *
     * @param callable $seeder Function that returns a single row (associative array)
     * @param int      $count  Number of rows to generate
     * @return array   Generated rows
     */
    public function run(callable $seeder, int $count = 10): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $seeder();
        }
        return $rows;
    }
}
