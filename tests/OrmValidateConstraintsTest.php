<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 019 (input validation) — the PHP GAP.
 *
 * ORM::validate() used to be a `return []` stub: every model following the
 * documented `$errors = $model->validate()` pattern silently accepted invalid
 * input. These tests pin the real contract — validate() COLLECTS one message
 * per violated constraint, never raises for a constraint, and returns [] only
 * when the model is genuinely valid. Each message is "<field>: <what was
 * wrong>", using the Node reference vocabulary so a 400 body reads the same in
 * every framework.
 *
 * Pure in-memory: validate() touches no database, so these run with no server
 * (the plan's cheapest suite). Every violation test is a GATE — it goes RED on
 * the old `return []` stub and GREEN on the real implementation, which is what
 * proves it is a gate and not a ghost.
 *
 * Cross-framework reference: tina4-nodejs packages/orm/src/validation.ts +
 * BaseModel.validate().
 */

use PHPUnit\Framework\TestCase;
use Tina4\ORM;

// ── Test Models ──────────────────────────────────────────────────────────────

/**
 * A model declaring every constraint in the vocabulary: required, minLength,
 * maxLength, min, max, pattern. Typed properties carry the columns; $fields
 * carries the user-input rules a PHP type cannot express.
 */
class ValidatedUser extends ORM
{
    public string $tableName  = 'validated_users';
    public string $primaryKey = 'id';

    public ?int    $id    = null;
    public string  $name  = '';
    public ?int    $age   = null;
    public string  $email = '';
    public ?string $bio   = null;

    public array $fields = [
        'name'  => ['required' => true, 'minLength' => 2, 'maxLength' => 5],
        'age'   => ['min' => 0, 'max' => 65],
        'email' => ['required' => true, 'pattern' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/'],
        'bio'   => ['maxLength' => 10],
    ];
}

/**
 * A model whose only declared option is `length` — the DDL sizing hint. It must
 * NEVER validate the value: a column can be VARCHAR(3) while the value is
 * unbounded, because length sizes the column and maxLength caps the value.
 */
class DdlLengthOnlyModel extends ORM
{
    public string $tableName = 'ddl_length_only';

    public ?string $code = null;

    public array $fields = [
        'code' => ['length' => 3],
    ];
}

/**
 * A model declaring constraints in snake_case (min_length / max_length) — the
 * cross-framework spelling. Both spellings are accepted.
 */
class SnakeCaseConstraintModel extends ORM
{
    public string $tableName = 'snake_case_constraints';

    public ?string $title = null;

    public array $fields = [
        'title' => ['min_length' => 2, 'max_length' => 5],
    ];
}

// ── Tests ────────────────────────────────────────────────────────────────────

final class OrmValidateConstraintsTest extends TestCase
{
    // --- All-clear: a valid model validates to an empty list -----------------

    public function testAValidModelValidatesToAnEmptyList(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->age   = 30;
        $user->email = 'alan@example.com';

        $this->assertSame([], $user->validate());
    }

    public function testValidateNeverReturnsNullOrFalseForAValidModel(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->age   = 30;
        $user->email = 'alan@example.com';

        $result = $user->validate();
        $this->assertIsArray($result);
        $this->assertNotNull($result);
        $this->assertNotFalse($result);
    }

    // --- Each constraint reports its matching message ------------------------

    public function testMaxLengthViolationIsReported(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alexander';   // 9 chars > maxLength 5
        $user->email = 'alan@example.com';

        $this->assertContains('name: must be at most 5 characters', $user->validate());
    }

    public function testMinLengthViolationIsReported(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'A';           // 1 char < minLength 2
        $user->email = 'alan@example.com';

        $this->assertContains('name: must be at least 2 characters', $user->validate());
    }

    public function testMinViolationIsReported(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->email = 'alan@example.com';
        $user->age   = -5;            // < min 0

        $this->assertContains('age: must be at least 0', $user->validate());
    }

    public function testMaxViolationIsReported(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->email = 'alan@example.com';
        $user->age   = 999;           // > max 65

        $this->assertContains('age: must be at most 65', $user->validate());
    }

    public function testPatternViolationIsReported(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->email = 'not-an-email'; // fails the email pattern

        $this->assertContains('email: does not match required pattern', $user->validate());
    }

    // --- required fires on an empty model ------------------------------------

    public function testRequiredViolationOnAnEmptyModelReportsTheRequiredMessage(): void
    {
        // name and email default to "" (blank); both are required.
        $errors = (new ValidatedUser())->validate();

        $this->assertContains('name: is required', $errors);
        $this->assertContains('email: is required', $errors);
    }

    public function testRequiredTrueMeansTheFieldMustBePresent(): void
    {
        $user = new ValidatedUser();
        $user->name  = '';            // blank -> required fails
        $user->email = 'alan@example.com';

        $errors = $user->validate();
        $this->assertContains('name: is required', $errors);
        // required short-circuits: no minLength message piled on top of it.
        $this->assertNotContains('name: must be at least 2 characters', $errors);
    }

    // --- Every constraint is checked; none is silently skipped ---------------

    public function testValidateReportsRequiredMinLengthMaxLengthMinMaxAndPattern(): void
    {
        // One model violating four DIFFERENT constraints at once. Each field's
        // value is present and non-blank, so required does not short-circuit
        // and every declared constraint gets its chance to fire.
        $user = new ValidatedUser();
        $user->name  = 'A';                     // minLength 2
        $user->age   = 999;                     // max 65
        $user->email = 'bad';                   // pattern
        $user->bio   = str_repeat('z', 50);     // maxLength 10

        $errors = $user->validate();

        $this->assertContains('name: must be at least 2 characters', $errors);
        $this->assertContains('age: must be at most 65', $errors);
        $this->assertContains('email: does not match required pattern', $errors);
        $this->assertContains('bio: must be at most 10 characters', $errors);
        // Exactly those four — no constraint silently unchecked, none invented.
        $this->assertCount(4, $errors);
    }

    // --- Message format: every message names the field it failed -------------

    public function testEveryMessageStartsWithTheFieldNameFollowedByAColon(): void
    {
        $user = new ValidatedUser();
        $user->name  = 'Alexander';             // maxLength
        $user->age   = 999;                     // max
        $user->email = 'nope';                  // pattern

        $errors = $user->validate();
        $this->assertNotEmpty($errors);
        foreach ($errors as $message) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_][a-zA-Z0-9_]*: \S/',
                $message,
                "Message must be formatted '<field>: <what>': {$message}"
            );
        }
    }

    // --- Collect, never raise: the exact Python D1 reproduction --------------

    public function testConstructingAModelWithABadValueDoesNotRaise(): void
    {
        // Assigning an over-long value STORES it — no exception. validate()
        // reports it afterwards. This is the difference between a 400 with a
        // useful body and a 500.
        $user = new ValidatedUser();
        $user->name  = str_repeat('x', 100);    // far over maxLength 5
        $user->email = 'alan@example.com';

        $this->assertContains('name: must be at most 5 characters', $user->validate());
    }

    public function testATypeErrorIsStillRaisedAtAssignmentNotReturnedAsAMessage(): void
    {
        // A CONSTRAINT violation is collectable user input; a TYPE error is a
        // programming error and stays a raise, enforced by PHP's own type
        // system at assignment — it never reaches validate().
        $user = new ValidatedUser();
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line — intentional type violation for the test */
        $user->age = ['not', 'an', 'integer'];
    }

    // --- length (DDL hint) vs maxLength (validation) --------------------------

    public function testADdlLengthHintDoesNotValidateTheValue(): void
    {
        $model = new DdlLengthOnlyModel();
        $model->code = 'abcdefghij';            // 10 chars, only `length` => 3 declared

        // length sizes the column; it is NOT a value constraint.
        $this->assertSame([], $model->validate());
    }

    public function testMaxLengthValidatesWhereLengthDoesNot(): void
    {
        // Same over-long value: length ignores it, maxLength catches it.
        $lengthOnly = new DdlLengthOnlyModel();
        $lengthOnly->code = 'abcdefghij';
        $this->assertSame([], $lengthOnly->validate());

        $capped = new ValidatedUser();
        $capped->name  = 'abcdefghij';          // maxLength 5
        $capped->email = 'alan@example.com';
        $this->assertContains('name: must be at most 5 characters', $capped->validate());
    }

    // --- Both option spellings are accepted ----------------------------------

    public function testSnakeCaseConstraintKeysAreAccepted(): void
    {
        $tooLong = new SnakeCaseConstraintModel();
        $tooLong->title = 'toolong';            // 7 chars > max_length 5
        $this->assertContains('title: must be at most 5 characters', $tooLong->validate());

        $tooShort = new SnakeCaseConstraintModel();
        $tooShort->title = 'a';                 // 1 char < min_length 2
        $this->assertContains('title: must be at least 2 characters', $tooShort->validate());
    }

    // --- An unconstrained / null optional field is left alone ----------------

    public function testAnAbsentOptionalFieldIsNotConstrained(): void
    {
        // bio is null (optional, maxLength only) -> skipped, no message.
        $user = new ValidatedUser();
        $user->name  = 'Alan';
        $user->email = 'alan@example.com';

        $this->assertSame([], $user->validate());
    }
}
