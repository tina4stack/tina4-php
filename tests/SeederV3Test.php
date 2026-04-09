<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\FakeData;

class SeederV3Test extends TestCase
{
    private FakeData $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeData();
    }

    public function testFirstName(): void
    {
        $name = $this->fake->firstName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testLastName(): void
    {
        $name = $this->fake->lastName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testFullName(): void
    {
        $name = $this->fake->name();
        $this->assertIsString($name);
        $this->assertStringContainsString(' ', $name);
    }

    public function testEmail(): void
    {
        $email = $this->fake->email();
        $this->assertStringContainsString('@', $email);
        $this->assertStringContainsString('.', $email);
    }

    public function testPhone(): void
    {
        $phone = $this->fake->phone();
        $this->assertMatchesRegularExpression('#^\+1 \(\d{3}\) \d{3}-\d{4}$#', $phone);
    }

    public function testAddress(): void
    {
        $address = $this->fake->address();
        $this->assertIsString($address);
        $this->assertStringContainsString(',', $address);
    }

    public function testCity(): void
    {
        $this->assertIsString($this->fake->city());
        $this->assertNotEmpty($this->fake->city());
    }

    public function testCountry(): void
    {
        $this->assertIsString($this->fake->country());
        $this->assertNotEmpty($this->fake->country());
    }

    public function testZipCode(): void
    {
        $zip = $this->fake->zipCode();
        $this->assertMatchesRegularExpression('#^\d{5}$#', $zip);
    }

    public function testCompany(): void
    {
        $company = $this->fake->company();
        $this->assertIsString($company);
        $this->assertStringContainsString(' ', $company);
    }

    public function testJobTitle(): void
    {
        $this->assertIsString($this->fake->jobTitle());
        $this->assertNotEmpty($this->fake->jobTitle());
    }

    public function testSentence(): void
    {
        $sentence = $this->fake->sentence(5);
        $this->assertStringEndsWith('.', $sentence);
        // First letter should be uppercase
        $this->assertMatchesRegularExpression('#^[A-Z]#', $sentence);
    }

    public function testParagraph(): void
    {
        $para = $this->fake->paragraph(3);
        // Should contain multiple sentences (dots)
        $dots = substr_count($para, '.');
        $this->assertGreaterThanOrEqual(3, $dots);
    }

    public function testWord(): void
    {
        $word = $this->fake->word();
        $this->assertIsString($word);
        $this->assertNotEmpty($word);
        $this->assertStringNotContainsString(' ', $word);
    }

    public function testInteger(): void
    {
        $val = $this->fake->integer(10, 20);
        $this->assertGreaterThanOrEqual(10, $val);
        $this->assertLessThanOrEqual(20, $val);
    }

    public function testFloat(): void
    {
        $val = $this->fake->numeric(1.0, 5.0, 2);
        $this->assertGreaterThanOrEqual(1.0, $val);
        $this->assertLessThanOrEqual(5.0, $val);
    }

    public function testBoolean(): void
    {
        $val = $this->fake->boolean();
        $this->assertIsBool($val);
    }

    public function testDate(): void
    {
        $date = $this->fake->date('2023-01-01', '2023-12-31');
        $this->assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}$#', $date);
        $this->assertGreaterThanOrEqual('2023-01-01', $date);
        $this->assertLessThanOrEqual('2023-12-31', $date);
    }

    public function testUuid(): void
    {
        $uuid = $this->fake->uuid();
        $this->assertMatchesRegularExpression(
            '#^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#',
            $uuid
        );
    }

    public function testUrl(): void
    {
        $url = $this->fake->url();
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('/', $url);
    }

    public function testIpAddress(): void
    {
        $ip = $this->fake->ipAddress();
        $this->assertMatchesRegularExpression('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $ip);
    }

    public function testHexColor(): void
    {
        $hex = $this->fake->colorHex();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
    }

    public function testCreditCard(): void
    {
        $cc = $this->fake->creditCard();
        $this->assertSame(16, strlen($cc));
        $this->assertMatchesRegularExpression('#^\d{16}$#', $cc);
    }

    public function testCurrency(): void
    {
        $currency = $this->fake->currency();
        $this->assertSame(3, strlen($currency));
    }

    public function testRunGeneratesRows(): void
    {
        $fake = $this->fake;
        $rows = $fake->run(function () use ($fake) {
            return [
                'name' => $fake->name(),
                'email' => $fake->email(),
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
        $result = $this->fake->seedDir('/nonexistent/seed/dir');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSeededDeterministic(): void
    {
        $fake1 = new FakeData(42);
        $name1 = $fake1->name();
        $email1 = $fake1->email();

        $fake2 = new FakeData(42);
        $name2 = $fake2->name();
        $email2 = $fake2->email();

        $this->assertSame($name1, $name2);
        $this->assertSame($email1, $email2);
    }

    public function testConstructorWithSeed(): void
    {
        $fake1 = new FakeData(99);
        $name1 = $fake1->firstName();
        $int1 = $fake1->integer(1, 1000);

        $fake2 = new FakeData(99);
        $name2 = $fake2->firstName();
        $int2 = $fake2->integer(1, 1000);

        $this->assertSame($name1, $name2);
        $this->assertSame($int1, $int2);
    }

    // ── Seed factory ──────────────────────────────────────────────

    public function testSeedFactory(): void
    {
        $fake = FakeData::seed(42);
        $this->assertInstanceOf(FakeData::class, $fake);
        $name = $fake->name();
        $this->assertIsString($name);
        $this->assertStringContainsString(' ', $name);
    }

    // ── Text generators ───────────────────────────────────────────

    public function testSentenceWordCount(): void
    {
        $sentence = $this->fake->sentence(5);
        $wordCount = count(explode(' ', rtrim($sentence, '.')));
        $this->assertSame(5, $wordCount);
    }

    public function testSentenceCapitalized(): void
    {
        $sentence = $this->fake->sentence();
        $this->assertTrue(ctype_upper($sentence[0]));
    }

    public function testWordNoSpaces(): void
    {
        $word = $this->fake->word();
        $this->assertStringNotContainsString(' ', $word);
        $this->assertNotEmpty($word);
    }

    // ── Numeric edge cases ────────────────────────────────────────

    public function testIntegerMinEqualsMax(): void
    {
        $val = $this->fake->integer(5, 5);
        $this->assertSame(5, $val);
    }

    public function testFloatPrecision(): void
    {
        $val = $this->fake->numeric(0, 100, 3);
        $str = (string)$val;
        if (strpos($str, '.') !== false) {
            $this->assertLessThanOrEqual(3, strlen(explode('.', $str)[1]));
        }
    }

    // ── Identifier generators ─────────────────────────────────────

    public function testUuidFormat(): void
    {
        $uuid = $this->fake->uuid();
        $parts = explode('-', $uuid);
        $this->assertCount(5, $parts);
        $this->assertSame(8, strlen($parts[0]));
        $this->assertSame(4, strlen($parts[1]));
        $this->assertSame(4, strlen($parts[2]));
        $this->assertSame(4, strlen($parts[3]));
        $this->assertSame(12, strlen($parts[4]));
    }

    public function testUuidUniqueness(): void
    {
        $uuids = [];
        for ($i = 0; $i < 50; $i++) {
            $uuids[] = $this->fake->uuid();
        }
        $this->assertCount(50, array_unique($uuids));
    }

    public function testUrlFormat(): void
    {
        $url = $this->fake->url();
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('/', substr($url, 8));
    }

    // ── Contact generators ────────────────────────────────────────

    public function testEmailFormat(): void
    {
        $email = $this->fake->email();
        $parts = explode('@', $email);
        $this->assertCount(2, $parts);
        $this->assertNotEmpty($parts[0]);
        $this->assertStringContainsString('.', $parts[1]);
    }

    public function testPhoneFormat(): void
    {
        $phone = $this->fake->phone();
        $this->assertStringStartsWith('+1 (', $phone);
        $this->assertStringContainsString(')', $phone);
        $this->assertStringContainsString('-', $phone);
    }

    public function testAddressHasNumberAndStreet(): void
    {
        $addr = $this->fake->address();
        $parts = explode(',', $addr);
        $this->assertGreaterThanOrEqual(2, count($parts));
        // First part should have a number
        $this->assertMatchesRegularExpression('/\d/', $parts[0]);
    }

    // ── Seed directory ────────────────────────────────────────────

    public function testSeedDirNonexistent(): void
    {
        $result = $this->fake->seedDir('/nonexistent/seed/dir');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSeedDirValid(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_seeder_test_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/test_seed.php', '<?php return function() { return ["name" => "Alice"]; };');

        $result = $this->fake->seedDir($dir);
        $this->assertArrayHasKey('test_seed.php', $result);
        $this->assertSame('loaded', $result['test_seed.php']);

        unlink($dir . '/test_seed.php');
        rmdir($dir);
    }

    // ── Run generator ─────────────────────────────────────────────

    public function testRunGeneratesCorrectCount(): void
    {
        $fake = $this->fake;
        $rows = $fake->run(function () use ($fake) {
            return ['name' => $fake->name()];
        }, 15);
        $this->assertCount(15, $rows);
    }

    public function testRunZeroRows(): void
    {
        $rows = $this->fake->run(function () {
            return ['name' => 'test'];
        }, 0);
        $this->assertCount(0, $rows);
    }

    // ── Deterministic output extended ──────────────────────────────

    public function testSeededDeterministicInteger(): void
    {
        $fake1 = new FakeData(77);
        $int1 = $fake1->integer(1, 1000);
        $email1 = $fake1->email();
        $uuid1 = $fake1->uuid();

        $fake2 = new FakeData(77);
        $int2 = $fake2->integer(1, 1000);
        $email2 = $fake2->email();
        $uuid2 = $fake2->uuid();

        $this->assertSame($int1, $int2);
        $this->assertSame($email1, $email2);
        $this->assertSame($uuid1, $uuid2);
    }

    // ── Column type heuristics ────────────────────────────────────

    public function testHexColorFormat(): void
    {
        $hex = $this->fake->colorHex();
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
    }

    public function testCreditCardLength(): void
    {
        $cc = $this->fake->creditCard();
        $this->assertSame(16, strlen($cc));
        $this->assertMatchesRegularExpression('/^\d{16}$/', $cc);
    }

    public function testCurrencyLength(): void
    {
        $currency = $this->fake->currency();
        $this->assertSame(3, strlen($currency));
    }

    public function testIpAddressFormat(): void
    {
        $ip = $this->fake->ipAddress();
        $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip);
    }

    public function testBooleanType(): void
    {
        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $results[] = $this->fake->boolean();
        }
        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testDateRange(): void
    {
        $date = $this->fake->date('2023-01-01', '2023-12-31');
        $this->assertGreaterThanOrEqual('2023-01-01', $date);
        $this->assertLessThanOrEqual('2023-12-31', $date);
    }

    public function testDateFormat(): void
    {
        $date = $this->fake->date();
        $parts = explode('-', $date);
        $this->assertCount(3, $parts);
        $year = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        $this->assertGreaterThanOrEqual(2020, $year);
        $this->assertLessThanOrEqual(2025, $year);
        $this->assertGreaterThanOrEqual(1, $month);
        $this->assertLessThanOrEqual(12, $month);
        $this->assertGreaterThanOrEqual(1, $day);
        $this->assertLessThanOrEqual(31, $day);
    }
}
