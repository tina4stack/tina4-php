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
     * @param mixed  $db        Database instance with execute() and commit() methods
     * @param string $tableName Name of the table to seed
     * @param int    $count     Number of rows to insert
     * @param array  $fieldMap  Associative array of column_name => callable|value
     * @param array  $overrides Static values applied to every row (override fieldMap)
     *
     * @return int Number of rows inserted
     */
    public static function seedTable(
        mixed $db,
        string $tableName,
        int $count = 10,
        array $fieldMap = [],
        array $overrides = []
    ): int {
        if (empty($fieldMap)) {
            return 0;
        }

        $inserted = 0;
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($fieldMap as $col => $generator) {
                $row[$col] = is_callable($generator) ? $generator() : $generator;
            }
            foreach ($overrides as $col => $value) {
                $row[$col] = $value;
            }

            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($row)));
            $placeholders = implode(', ', array_fill(0, count($row), '?'));
            $values = array_values($row);

            try {
                $db->execute("INSERT INTO `$tableName` ($cols) VALUES ($placeholders)", $values);
                $inserted++;
            } catch (\Throwable $e) {
                // skip failed rows
            }
        }

        try {
            $db->commit();
        } catch (\Throwable $e) {
            // ignore commit errors (may not be needed for all adapters)
        }

        return $inserted;
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
     * Mirrors Python's seed_orm().
     *
     * @param string   $ormClass  Fully-qualified ORM class name (e.g. User::class)
     * @param int      $count     Number of records to insert
     * @param array    $overrides Field overrides — static values or callables receiving a FakeData instance
     * @param bool     $clear     If true, delete all existing records before seeding
     * @param int|null $seed      Optional PRNG seed for reproducible output
     *
     * @return int Number of records inserted
     */
    public static function seedOrm(
        string $ormClass,
        int $count = 10,
        array $overrides = [],
        bool $clear = false,
        ?int $seed = null
    ): int {
        $fake   = new self($seed);
        $orm    = new $ormClass();

        // Attempt to retrieve field definitions from the ORM instance
        $fieldDefs = method_exists($orm, 'getFieldDefinitions')
            ? $orm->getFieldDefinitions()
            : (property_exists($orm, 'fieldDefinitions') ? $orm->fieldDefinitions : []);

        if ($clear && method_exists($orm, 'deleteAll')) {
            try {
                $orm->deleteAll();
            } catch (\Throwable $e) {
                // Ignore clear errors
            }
        }

        $inserted = 0;
        for ($i = 0; $i < $count; $i++) {
            $attrs = [];

            foreach ($fieldDefs as $name => $fieldDef) {
                if (!empty($fieldDef['primary_key']) && !empty($fieldDef['auto_increment'])) {
                    continue;
                }
                if (array_key_exists($name, $overrides)) {
                    $val = $overrides[$name];
                    $attrs[$name] = is_callable($val) ? $val($fake) : $val;
                } else {
                    $attrs[$name] = $fake->forField($fieldDef, $name);
                }
            }

            try {
                $instance = new $ormClass($attrs);
                if ($instance->save()) {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                // Skip failed rows
            }
        }

        return $inserted;
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
