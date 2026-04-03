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
 *     $name  = $fake->fullName();   // "Alice Johnson"
 *     $email = $fake->email();      // "alice.johnson@example.com"
 *
 *     // Deterministic output with a seed:
 *     $fake  = new FakeData(42);
 *     $name  = $fake->fullName();   // same every time
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

    private const COLORS = [
        'red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink',
        'cyan', 'magenta', 'brown', 'black', 'white', 'gray', 'teal',
        'navy', 'coral', 'salmon', 'indigo', 'violet', 'lime',
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
     *     $fake->fullName(); // deterministic
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

    public function fullName(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
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

    public function float(float $min = 0, float $max = 1000, int $decimals = 2): float
    {
        $rand = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($rand, $decimals);
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

    public function color(): string
    {
        return $this->pick(self::COLORS);
    }

    public function hexColor(): string
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
