<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * FakeData — comprehensive unit tests.
 */

use PHPUnit\Framework\TestCase;
use Tina4\FakeData;

class FakeDataTest extends TestCase
{
    // ── Constructor & Seeding ──────────────────────────────────────

    public function testConstructorWithoutSeed(): void
    {
        $fake = new FakeData();
        $this->assertInstanceOf(FakeData::class, $fake);
    }

    public function testConstructorWithSeed(): void
    {
        $fake = new FakeData(42);
        $this->assertInstanceOf(FakeData::class, $fake);
    }

    public function testStaticSeedFactory(): void
    {
        $fake = FakeData::seed(42);
        $this->assertInstanceOf(FakeData::class, $fake);
    }

    public function testDeterministicSeedProducesSameResults(): void
    {
        // Collect values from first seeded instance
        $fake1 = new FakeData(123);
        $name1 = $fake1->fullName();
        $email1 = $fake1->email();
        $phone1 = $fake1->phone();

        // Create a fresh instance with the same seed and collect again
        $fake2 = new FakeData(123);
        $name2 = $fake2->fullName();
        $email2 = $fake2->email();
        $phone2 = $fake2->phone();

        $this->assertEquals($name1, $name2);
        $this->assertEquals($email1, $email2);
        $this->assertEquals($phone1, $phone2);
    }

    public function testDifferentSeedsProduceDifferentResults(): void
    {
        $fake1 = new FakeData(1);
        $fake2 = new FakeData(999);

        // Collect several values to avoid rare collisions
        $names1 = [];
        $names2 = [];
        for ($i = 0; $i < 5; $i++) {
            $names1[] = $fake1->fullName();
            $names2[] = $fake2->fullName();
        }
        $this->assertNotEquals($names1, $names2);
    }

    // ── Name Generation ────────────────────────────────────────────

    public function testFirstNameReturnsNonEmptyString(): void
    {
        $fake = new FakeData();
        $name = $fake->firstName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testLastNameReturnsNonEmptyString(): void
    {
        $fake = new FakeData();
        $name = $fake->lastName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testFullNameContainsSpace(): void
    {
        $fake = new FakeData();
        $name = $fake->fullName();
        $this->assertStringContainsString(' ', $name);
    }

    public function testFullNameHasTwoParts(): void
    {
        $fake = new FakeData();
        $parts = explode(' ', $fake->fullName());
        $this->assertCount(2, $parts);
    }

    // ── Email Generation ───────────────────────────────────────────

    public function testEmailContainsAtSign(): void
    {
        $fake = new FakeData();
        $email = $fake->email();
        $this->assertStringContainsString('@', $email);
    }

    public function testEmailContainsDot(): void
    {
        $fake = new FakeData();
        $email = $fake->email();
        $this->assertStringContainsString('.', $email);
    }

    public function testEmailIsLowercase(): void
    {
        $fake = new FakeData(42);
        $email = $fake->email();
        $this->assertEquals(strtolower($email), $email);
    }

    public function testEmailFormat(): void
    {
        $fake = new FakeData();
        $email = $fake->email();
        // Should match firstname.lastname@domain.tld
        $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+@[a-z]+\.[a-z]+$/', $email);
    }

    // ── Phone Generation ───────────────────────────────────────────

    public function testPhoneStartsWithPlusOne(): void
    {
        $fake = new FakeData();
        $phone = $fake->phone();
        $this->assertStringStartsWith('+1 ', $phone);
    }

    public function testPhoneMatchesFormat(): void
    {
        $fake = new FakeData();
        $phone = $fake->phone();
        $this->assertMatchesRegularExpression('/^\+1 \(\d{3}\) \d{3}-\d{4}$/', $phone);
    }

    // ── Address Generation ─────────────────────────────────────────

    public function testAddressContainsComma(): void
    {
        $fake = new FakeData();
        $address = $fake->address();
        $this->assertStringContainsString(',', $address);
    }

    public function testCityReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $city = $fake->city();
        $this->assertIsString($city);
        $this->assertNotEmpty($city);
    }

    public function testCountryReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $country = $fake->country();
        $this->assertIsString($country);
        $this->assertNotEmpty($country);
    }

    public function testZipCodeIsFiveDigits(): void
    {
        $fake = new FakeData();
        $zip = $fake->zipCode();
        $this->assertMatchesRegularExpression('/^\d{5}$/', $zip);
    }

    // ── Company & Job ──────────────────────────────────────────────

    public function testCompanyReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $company = $fake->company();
        $this->assertIsString($company);
        $this->assertNotEmpty($company);
    }

    public function testCompanyHasTwoParts(): void
    {
        $fake = new FakeData();
        $company = $fake->company();
        $parts = explode(' ', $company);
        $this->assertGreaterThanOrEqual(2, count($parts));
    }

    public function testJobTitleReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $job = $fake->jobTitle();
        $this->assertIsString($job);
        $this->assertNotEmpty($job);
    }

    // ── Text Generation ────────────────────────────────────────────

    public function testWordReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $word = $fake->word();
        $this->assertIsString($word);
        $this->assertNotEmpty($word);
    }

    public function testSentenceEndsWithPeriod(): void
    {
        $fake = new FakeData();
        $sentence = $fake->sentence();
        $this->assertStringEndsWith('.', $sentence);
    }

    public function testSentenceStartsWithUppercase(): void
    {
        $fake = new FakeData();
        $sentence = $fake->sentence();
        $this->assertTrue(ctype_upper($sentence[0]));
    }

    public function testSentenceWordCount(): void
    {
        $fake = new FakeData();
        $sentence = $fake->sentence(5);
        // Remove trailing period and count words
        $words = explode(' ', rtrim($sentence, '.'));
        $this->assertCount(5, $words);
    }

    public function testParagraphHasMultipleSentences(): void
    {
        $fake = new FakeData();
        $paragraph = $fake->paragraph(3);
        // Count sentences by counting periods
        $periodCount = substr_count($paragraph, '.');
        $this->assertEquals(3, $periodCount);
    }

    public function testParagraphDefaultSentences(): void
    {
        $fake = new FakeData();
        $paragraph = $fake->paragraph();
        $periodCount = substr_count($paragraph, '.');
        $this->assertEquals(3, $periodCount);
    }

    // ── Numeric Generation ─────────────────────────────────────────

    public function testIntegerInDefaultRange(): void
    {
        $fake = new FakeData();
        $value = $fake->integer();
        $this->assertGreaterThanOrEqual(0, $value);
        $this->assertLessThanOrEqual(1000, $value);
    }

    public function testIntegerInCustomRange(): void
    {
        $fake = new FakeData();
        $value = $fake->integer(10, 20);
        $this->assertGreaterThanOrEqual(10, $value);
        $this->assertLessThanOrEqual(20, $value);
    }

    public function testFloatInDefaultRange(): void
    {
        $fake = new FakeData();
        $value = $fake->float();
        $this->assertIsFloat($value);
        $this->assertGreaterThanOrEqual(0, $value);
        $this->assertLessThanOrEqual(1000, $value);
    }

    public function testFloatInCustomRange(): void
    {
        $fake = new FakeData();
        $value = $fake->float(1.0, 2.0, 4);
        $this->assertGreaterThanOrEqual(1.0, $value);
        $this->assertLessThanOrEqual(2.0, $value);
    }

    public function testFloatDecimalPrecision(): void
    {
        $fake = new FakeData();
        $value = $fake->float(0, 100, 3);
        $parts = explode('.', (string)$value);
        if (isset($parts[1])) {
            $this->assertLessThanOrEqual(3, strlen($parts[1]));
        }
        $this->assertTrue(true); // Always passes if no decimal part
    }

    public function testBooleanReturnsBool(): void
    {
        $fake = new FakeData();
        $value = $fake->boolean();
        $this->assertIsBool($value);
    }

    // ── Date Generation ────────────────────────────────────────────

    public function testDateFormatIsYmd(): void
    {
        $fake = new FakeData();
        $date = $fake->date();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    public function testDateInRange(): void
    {
        $fake = new FakeData();
        $date = $fake->date('2023-01-01', '2023-12-31');
        $ts = strtotime($date);
        $this->assertGreaterThanOrEqual(strtotime('2023-01-01'), $ts);
        $this->assertLessThanOrEqual(strtotime('2023-12-31'), $ts);
    }

    public function testDateWithInvalidRangeFallsBack(): void
    {
        $fake = new FakeData();
        $date = $fake->date('not-a-date', 'also-invalid');
        // Should return today's date
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    // ── UUID Generation ────────────────────────────────────────────

    public function testUuidFormat(): void
    {
        $fake = new FakeData();
        $uuid = $fake->uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testUuidVersionBit(): void
    {
        $fake = new FakeData();
        $uuid = $fake->uuid();
        // Version 4 UUID has '4' as first character of third group
        $parts = explode('-', $uuid);
        $this->assertStringStartsWith('4', $parts[2]);
    }

    public function testUuidUniqueness(): void
    {
        $fake = new FakeData();
        $uuids = [];
        for ($i = 0; $i < 10; $i++) {
            $uuids[] = $fake->uuid();
        }
        $this->assertCount(10, array_unique($uuids));
    }

    // ── URL Generation ─────────────────────────────────────────────

    public function testUrlStartsWithHttps(): void
    {
        $fake = new FakeData();
        $url = $fake->url();
        $this->assertStringStartsWith('https://', $url);
    }

    public function testUrlContainsPath(): void
    {
        $fake = new FakeData();
        $url = $fake->url();
        // URL should have at least two path segments
        $parts = parse_url($url);
        $this->assertNotEmpty($parts['path'] ?? '');
    }

    // ── IP Address Generation ──────────────────────────────────────

    public function testIpAddressFormat(): void
    {
        $fake = new FakeData();
        $ip = $fake->ipAddress();
        $this->assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP));
    }

    public function testIpAddressHasFourOctets(): void
    {
        $fake = new FakeData();
        $ip = $fake->ipAddress();
        $parts = explode('.', $ip);
        $this->assertCount(4, $parts);
    }

    // ── Color Generation ───────────────────────────────────────────

    public function testColorReturnsNonEmpty(): void
    {
        $fake = new FakeData();
        $color = $fake->color();
        $this->assertIsString($color);
        $this->assertNotEmpty($color);
    }

    public function testHexColorFormat(): void
    {
        $fake = new FakeData();
        $hex = $fake->hexColor();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
    }

    // ── Credit Card Generation ─────────────────────────────────────

    public function testCreditCardLength(): void
    {
        $fake = new FakeData();
        $cc = $fake->creditCard();
        $this->assertEquals(16, strlen($cc));
    }

    public function testCreditCardStartsWithKnownPrefix(): void
    {
        $fake = new FakeData();
        $cc = $fake->creditCard();
        $prefix = substr($cc, 0, 4);
        $this->assertContains($prefix, ['4111', '4242', '5500', '5105']);
    }

    public function testCreditCardIsAllDigits(): void
    {
        $fake = new FakeData();
        $cc = $fake->creditCard();
        $this->assertTrue(ctype_digit($cc));
    }

    // ── Currency Generation ────────────────────────────────────────

    public function testCurrencyIsThreeLetters(): void
    {
        $fake = new FakeData();
        $currency = $fake->currency();
        $this->assertEquals(3, strlen($currency));
        $this->assertTrue(ctype_upper($currency));
    }

    // ── Seeder Runner ──────────────────────────────────────────────

    public function testRunGeneratesCorrectCount(): void
    {
        $fake = new FakeData();
        $rows = $fake->run(fn() => ['name' => $fake->fullName()], 5);
        $this->assertCount(5, $rows);
    }

    public function testRunReturnsAssociativeArrays(): void
    {
        $fake = new FakeData();
        $rows = $fake->run(fn() => ['id' => $fake->integer(), 'name' => $fake->fullName()], 3);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function testRunDefaultCount(): void
    {
        $fake = new FakeData();
        $rows = $fake->run(fn() => ['x' => 1]);
        $this->assertCount(10, $rows);
    }

    public function testSeedDirNonExistentDirectory(): void
    {
        $fake = new FakeData();
        $results = $fake->seedDir('/nonexistent/path/12345');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ── Consistency across methods ─────────────────────────────────

    public function testAllMethodsReturnExpectedTypes(): void
    {
        $fake = new FakeData(42);

        $this->assertIsString($fake->firstName());
        $this->assertIsString($fake->lastName());
        $this->assertIsString($fake->fullName());
        $this->assertIsString($fake->email());
        $this->assertIsString($fake->phone());
        $this->assertIsString($fake->address());
        $this->assertIsString($fake->city());
        $this->assertIsString($fake->country());
        $this->assertIsString($fake->zipCode());
        $this->assertIsString($fake->company());
        $this->assertIsString($fake->jobTitle());
        $this->assertIsString($fake->word());
        $this->assertIsString($fake->sentence());
        $this->assertIsString($fake->paragraph());
        $this->assertIsInt($fake->integer());
        $this->assertIsFloat($fake->float());
        $this->assertIsBool($fake->boolean());
        $this->assertIsString($fake->date());
        $this->assertIsString($fake->uuid());
        $this->assertIsString($fake->url());
        $this->assertIsString($fake->ipAddress());
        $this->assertIsString($fake->color());
        $this->assertIsString($fake->hexColor());
        $this->assertIsString($fake->creditCard());
        $this->assertIsString($fake->currency());
    }
}
