<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 19 - Input and request validation: the shared conformance contract.
 * Parity with tina4-nodejs/test/validationContract.test.ts,
 * tina4-python/tests/test_validation_contract.py,
 * tina4-ruby/spec/validation_contract_spec.rb.
 *
 * Two validators, both REAL-tested (no mocks, no doubles):
 *   1. the chainable request-body Validator (advisory) - every rule pos + neg;
 *   2. ORM field validation ENFORCED on save() (VALID-PHP-ENFORCE-UNTESTED - the
 *      enforcement path had NO test): a $fields-constrained model with invalid
 *      data returns false from save() AND writes NOTHING - proven by a real
 *      COUNT(*) against a real SQLite database after the refused save;
 *   3. both validators emit ONE canonical message per rule (VALID-TWO-MESSAGES).
 *
 * Case names are shared verbatim across the four frameworks and gated by
 * tina4-documentation/scripts/audit-contract-fixtures.py.
 */

use PHPUnit\Framework\TestCase;
use Tina4\ORM;
use Tina4\Validator;
use Tina4\Database\SQLite3Adapter;

/**
 * A $fields-constrained model: typed properties carry the columns, $fields
 * carries the user-input rules a PHP type cannot express.
 */
class ValidationPerson extends ORM
{
    public string  $tableName  = 'validation_person';
    public string  $primaryKey = 'id';

    public ?int    $id   = null;
    public string  $name = '';
    public ?int    $age  = null;
    public ?string $code = null;

    public array $fields = [
        'name' => ['required' => true, 'minLength' => 2, 'maxLength' => 5],
        'age'  => ['min' => 0, 'max' => 65],
        'code' => ['pattern' => '/^[0-9]+$/'],
    ];
}

final class ValidationContractTest extends TestCase
{
    private const ROLES = ['admin', 'user', 'guest'];

    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE validation_person (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER, code TEXT)'
        );
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function rowCount(): int
    {
        return (int)($this->db->fetchOne('SELECT COUNT(*) AS c FROM validation_person')['c'] ?? -1);
    }

    private function validPerson(): ValidationPerson
    {
        $p = new ValidationPerson();
        $p->name = 'Al';
        $p->age  = 30;
        $p->code = '123';
        return $p;
    }

    // ── 1. request-body Validator: every rule, positive AND negative ─────────

    public function testRequiredRulePassesAPresentValueAndReportsAMissingOne(): void
    {
        $this->assertTrue((new Validator(['name' => 'Alice']))->required('name')->isValid());
        $bad = (new Validator(['name' => '']))->required('name');
        $this->assertFalse($bad->isValid());
        $this->assertSame('name is required', $bad->errors()[0]['message']);
    }

    public function testEmailRulePassesAValidAddressAndReportsAnInvalidOne(): void
    {
        $this->assertTrue((new Validator(['email' => 'a@b.co']))->email('email')->isValid());
        $this->assertSame('email must be a valid email address',
            (new Validator(['email' => 'nope']))->email('email')->errors()[0]['message']);
    }

    public function testMinLengthRulePassesALongValueAndReportsAShortOne(): void
    {
        $this->assertTrue((new Validator(['name' => 'Alice']))->minLength('name', 2)->isValid());
        $this->assertSame('name must be at least 2 characters',
            (new Validator(['name' => 'A']))->minLength('name', 2)->errors()[0]['message']);
    }

    public function testMaxLengthRulePassesAShortValueAndReportsALongOne(): void
    {
        $this->assertTrue((new Validator(['name' => 'Al']))->maxLength('name', 5)->isValid());
        $this->assertSame('name must be at most 5 characters',
            (new Validator(['name' => 'Alexander']))->maxLength('name', 5)->errors()[0]['message']);
    }

    public function testIntegerRulePassesAnIntegerAndReportsANonInteger(): void
    {
        $this->assertTrue((new Validator(['age' => 30]))->integer('age')->isValid());
        $this->assertSame('age must be an integer',
            (new Validator(['age' => 'abc']))->integer('age')->errors()[0]['message']);
    }

    public function testMinRulePassesAValueAboveTheMinimumAndReportsOneBelow(): void
    {
        $this->assertTrue((new Validator(['age' => 5]))->min('age', 0)->isValid());
        $this->assertSame('age must be at least 0',
            (new Validator(['age' => -5]))->min('age', 0)->errors()[0]['message']);
    }

    public function testMaxRulePassesAValueBelowTheMaximumAndReportsOneAbove(): void
    {
        $this->assertTrue((new Validator(['age' => 40]))->max('age', 65)->isValid());
        $this->assertSame('age must be at most 65',
            (new Validator(['age' => 999]))->max('age', 65)->errors()[0]['message']);
    }

    public function testInListRulePassesAnAllowedValueAndReportsADisallowedOne(): void
    {
        $this->assertTrue((new Validator(['role' => 'admin']))->inList('role', self::ROLES)->isValid());
        $this->assertSame('role must be one of ["admin","user","guest"]',
            (new Validator(['role' => 'root']))->inList('role', self::ROLES)->errors()[0]['message']);
    }

    public function testRegexRulePassesAMatchingValueAndReportsANonMatchingOne(): void
    {
        $this->assertTrue((new Validator(['code' => '123']))->regex('code', '/^[0-9]+$/')->isValid());
        $this->assertSame('code does not match the required format',
            (new Validator(['code' => 'abc']))->regex('code', '/^[0-9]+$/')->errors()[0]['message']);
    }

    public function testIsValidReflectsWhetherAnyRuleFailed(): void
    {
        $this->assertTrue(
            (new Validator(['name' => 'Al', 'age' => 30]))->required('name')->integer('age')->isValid()
        );
        $bad = (new Validator(['name' => '', 'age' => 'x']))->required('name')->integer('age');
        $this->assertFalse($bad->isValid());
        $this->assertCount(2, $bad->errors());
    }

    // ── 2. ORM save() ENFORCES field validation and writes NOTHING ───────────

    public function testARequiredViolationMakesSaveReturnFalseAndWritesNothing(): void
    {
        $p = new ValidationPerson(); // name defaults to '' (blank) -> required fails
        $this->assertFalse($p->save());
        $this->assertSame(0, $this->rowCount());
    }

    public function testAnOverLengthValueMakesSaveReturnFalseAndWritesNothing(): void
    {
        $p = $this->validPerson();
        $p->name = 'Alexander'; // 9 chars > maxLength 5
        $this->assertFalse($p->save());
        $this->assertSame(0, $this->rowCount());
    }

    public function testAnOutOfRangeValueMakesSaveReturnFalseAndWritesNothing(): void
    {
        $p = $this->validPerson();
        $p->age = 999; // > max 65
        $this->assertFalse($p->save());
        $this->assertSame(0, $this->rowCount());
    }

    public function testAPatternViolationMakesSaveReturnFalseAndWritesNothing(): void
    {
        $p = $this->validPerson();
        $p->code = 'abc'; // fails /^[0-9]+$/
        $this->assertFalse($p->save());
        $this->assertSame(0, $this->rowCount());
    }

    public function testAValidModelSavesAndWritesOneRow(): void
    {
        $this->assertNotFalse($this->validPerson()->save());
        $this->assertSame(1, $this->rowCount());
    }

    // ── 3. one canonical vocabulary across BOTH validators ───────────────────

    public function testRequiredEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $orm = (new ValidationPerson())->validate()[0]; // name blank -> required
        $req = (new Validator([]))->required('name')->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('name is required', $orm);
    }

    public function testMinLengthEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $p = new ValidationPerson(); $p->name = 'A';
        $orm = $p->validate()[0];
        $req = (new Validator(['name' => 'A']))->minLength('name', 2)->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('name must be at least 2 characters', $orm);
    }

    public function testMaxLengthEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $p = new ValidationPerson(); $p->name = 'Alexander';
        $orm = $p->validate()[0];
        $req = (new Validator(['name' => 'Alexander']))->maxLength('name', 5)->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('name must be at most 5 characters', $orm);
    }

    public function testMinEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $p = $this->validPerson(); $p->age = -5;
        $orm = $p->validate()[0];
        $req = (new Validator(['age' => -5]))->min('age', 0)->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('age must be at least 0', $orm);
    }

    public function testMaxEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $p = $this->validPerson(); $p->age = 999;
        $orm = $p->validate()[0];
        $req = (new Validator(['age' => 999]))->max('age', 65)->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('age must be at most 65', $orm);
    }

    public function testRegexEmitsTheSameCanonicalMessageFromBothValidators(): void
    {
        $p = $this->validPerson(); $p->code = 'abc';
        $orm = $p->validate()[0];
        $req = (new Validator(['code' => 'abc']))->regex('code', '/^[0-9]+$/')->errors()[0]['message'];
        $this->assertSame($orm, $req);
        $this->assertSame('code does not match the required format', $orm);
    }
}
