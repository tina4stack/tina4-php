<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency fake data generator and database seeder.
 *
 * Generate realistic fake data for testing and development.
 *
 *     $name  = Seeder::fullName();   // "Alice Johnson"
 *     $email = Seeder::email();      // "alice.johnson@example.com"
 */
class Seeder
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

    // ── Generators ──────────────────────────────────────────────

    public static function firstName(): string
    {
        return self::FIRST_NAMES[random_int(0, count(self::FIRST_NAMES) - 1)];
    }

    public static function lastName(): string
    {
        return self::LAST_NAMES[random_int(0, count(self::LAST_NAMES) - 1)];
    }

    public static function fullName(): string
    {
        return self::firstName() . ' ' . self::lastName();
    }

    public static function email(): string
    {
        $first = strtolower(self::firstName());
        $last = strtolower(self::lastName());
        $domain = self::DOMAINS[random_int(0, count(self::DOMAINS) - 1)];
        return $first . '.' . $last . '@' . $domain;
    }

    public static function phone(): string
    {
        $area = random_int(200, 999);
        $mid = random_int(100, 999);
        $end = random_int(1000, 9999);
        return sprintf('+1 (%03d) %03d-%04d', $area, $mid, $end);
    }

    public static function address(): string
    {
        $num = random_int(1, 999);
        $street = self::STREETS[random_int(0, count(self::STREETS) - 1)];
        $city = self::CITIES[random_int(0, count(self::CITIES) - 1)];
        return $num . ' ' . $street . ', ' . $city;
    }

    public static function city(): string
    {
        return self::CITIES[random_int(0, count(self::CITIES) - 1)];
    }

    public static function country(): string
    {
        return self::COUNTRIES[random_int(0, count(self::COUNTRIES) - 1)];
    }

    public static function zipCode(): string
    {
        return sprintf('%05d', random_int(10000, 99999));
    }

    public static function company(): string
    {
        $last = self::lastName();
        $suffix = self::COMPANY_SUFFIXES[random_int(0, count(self::COMPANY_SUFFIXES) - 1)];
        return $last . ' ' . $suffix;
    }

    public static function jobTitle(): string
    {
        return self::JOB_TITLES[random_int(0, count(self::JOB_TITLES) - 1)];
    }

    public static function paragraph(int $sentences = 3): string
    {
        $parts = [];
        for ($i = 0; $i < $sentences; $i++) {
            $parts[] = self::sentence(random_int(5, 12));
        }
        return implode(' ', $parts);
    }

    public static function sentence(int $words = 8): string
    {
        $w = [];
        for ($i = 0; $i < $words; $i++) {
            $w[] = self::word();
        }
        return ucfirst(implode(' ', $w)) . '.';
    }

    public static function word(): string
    {
        return self::WORDS[random_int(0, count(self::WORDS) - 1)];
    }

    public static function integer(int $min = 0, int $max = 1000): int
    {
        return random_int($min, $max);
    }

    public static function float(float $min = 0, float $max = 1000, int $decimals = 2): float
    {
        $rand = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($rand, $decimals);
    }

    public static function boolean(): bool
    {
        return (bool)random_int(0, 1);
    }

    public static function date(string $start = '2020-01-01', string $end = '2025-12-31'): string
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return date('Y-m-d');
        }
        $ts = random_int($startTs, $endTs);
        return date('Y-m-d', $ts);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
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

    public static function url(): string
    {
        $domain = self::DOMAINS[random_int(0, count(self::DOMAINS) - 1)];
        $path = self::word() . '/' . self::word();
        return 'https://' . $domain . '/' . $path;
    }

    public static function ipAddress(): string
    {
        return random_int(1, 255) . '.' . random_int(0, 255) . '.' .
               random_int(0, 255) . '.' . random_int(1, 254);
    }

    public static function color(): string
    {
        return self::COLORS[random_int(0, count(self::COLORS) - 1)];
    }

    public static function hexColor(): string
    {
        return sprintf('#%06x', random_int(0, 0xFFFFFF));
    }

    /**
     * Generate a fake credit card number (test numbers only, e.g. 4111...).
     */
    public static function creditCard(): string
    {
        $prefixes = ['4111', '4242', '5500', '5105'];
        $prefix = $prefixes[random_int(0, count($prefixes) - 1)];
        $rest = '';
        for ($i = 0; $i < 12; $i++) {
            $rest .= random_int(0, 9);
        }
        return $prefix . $rest;
    }

    public static function currency(): string
    {
        return self::CURRENCIES[random_int(0, count(self::CURRENCIES) - 1)];
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
    public static function seed(string $seedDir = 'src/seeds'): array
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
    public static function run(callable $seeder, int $count = 10): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $seeder();
        }
        return $rows;
    }
}
