<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Seeder;

class SeederV3Test extends TestCase
{
    public function testFirstName(): void
    {
        $name = Seeder::firstName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testLastName(): void
    {
        $name = Seeder::lastName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testFullName(): void
    {
        $name = Seeder::fullName();
        $this->assertIsString($name);
        $this->assertStringContainsString(' ', $name);
    }

    public function testEmail(): void
    {
        $email = Seeder::email();
        $this->assertStringContainsString('@', $email);
        $this->assertStringContainsString('.', $email);
    }

    public function testPhone(): void
    {
        $phone = Seeder::phone();
        $this->assertMatchesRegularExpression('#^\+1 \(\d{3}\) \d{3}-\d{4}$#', $phone);
    }

    public function testAddress(): void
    {
        $address = Seeder::address();
        $this->assertIsString($address);
        $this->assertStringContainsString(',', $address);
    }

    public function testCity(): void
    {
        $this->assertIsString(Seeder::city());
        $this->assertNotEmpty(Seeder::city());
    }

    public function testCountry(): void
    {
        $this->assertIsString(Seeder::country());
        $this->assertNotEmpty(Seeder::country());
    }

    public function testZipCode(): void
    {
        $zip = Seeder::zipCode();
        $this->assertMatchesRegularExpression('#^\d{5}$#', $zip);
    }

    public function testCompany(): void
    {
        $company = Seeder::company();
        $this->assertIsString($company);
        $this->assertStringContainsString(' ', $company);
    }

    public function testJobTitle(): void
    {
        $this->assertIsString(Seeder::jobTitle());
        $this->assertNotEmpty(Seeder::jobTitle());
    }

    public function testSentence(): void
    {
        $sentence = Seeder::sentence(5);
        $this->assertStringEndsWith('.', $sentence);
        // First letter should be uppercase
        $this->assertMatchesRegularExpression('#^[A-Z]#', $sentence);
    }

    public function testParagraph(): void
    {
        $para = Seeder::paragraph(3);
        // Should contain multiple sentences (dots)
        $dots = substr_count($para, '.');
        $this->assertGreaterThanOrEqual(3, $dots);
    }

    public function testWord(): void
    {
        $word = Seeder::word();
        $this->assertIsString($word);
        $this->assertNotEmpty($word);
        $this->assertStringNotContainsString(' ', $word);
    }

    public function testInteger(): void
    {
        $val = Seeder::integer(10, 20);
        $this->assertGreaterThanOrEqual(10, $val);
        $this->assertLessThanOrEqual(20, $val);
    }

    public function testFloat(): void
    {
        $val = Seeder::float(1.0, 5.0, 2);
        $this->assertGreaterThanOrEqual(1.0, $val);
        $this->assertLessThanOrEqual(5.0, $val);
    }

    public function testBoolean(): void
    {
        $val = Seeder::boolean();
        $this->assertIsBool($val);
    }

    public function testDate(): void
    {
        $date = Seeder::date('2023-01-01', '2023-12-31');
        $this->assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}$#', $date);
        $this->assertGreaterThanOrEqual('2023-01-01', $date);
        $this->assertLessThanOrEqual('2023-12-31', $date);
    }

    public function testUuid(): void
    {
        $uuid = Seeder::uuid();
        $this->assertMatchesRegularExpression(
            '#^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#',
            $uuid
        );
    }

    public function testUrl(): void
    {
        $url = Seeder::url();
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('/', $url);
    }

    public function testIpAddress(): void
    {
        $ip = Seeder::ipAddress();
        $this->assertMatchesRegularExpression('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $ip);
    }

    public function testColor(): void
    {
        $this->assertIsString(Seeder::color());
        $this->assertNotEmpty(Seeder::color());
    }

    public function testHexColor(): void
    {
        $hex = Seeder::hexColor();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
    }

    public function testCreditCard(): void
    {
        $cc = Seeder::creditCard();
        $this->assertSame(16, strlen($cc));
        $this->assertMatchesRegularExpression('#^\d{16}$#', $cc);
    }

    public function testCurrency(): void
    {
        $currency = Seeder::currency();
        $this->assertSame(3, strlen($currency));
    }

    public function testRunGeneratesRows(): void
    {
        $rows = Seeder::run(function () {
            return [
                'name' => Seeder::fullName(),
                'email' => Seeder::email(),
            ];
        }, 5);
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('email', $row);
        }
    }

    public function testSeedEmptyDir(): void
    {
        $result = Seeder::seed('/nonexistent/seed/dir');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
