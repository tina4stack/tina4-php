<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/audit-doc-drift.php';

/**
 * Mutation-proof for the doc-drift gate (scripts/audit-doc-drift.php).
 *
 * Every assertion breaks the thing the gate guards and proves the gate goes
 * RED, then proves the real repo is GREEN -- so the gate is a gate, not a
 * ghost. The gate introspects the LIVE Tina4 classes with Reflection, so a temp
 * CLAUDE.md that names a real class + a fake method is genuinely checked against
 * the loaded package, not a fixture.
 */
final class DocDriftGateTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    private string $tempRoot = '';

    protected function tearDown(): void
    {
        if ($this->tempRoot !== '' && is_dir($this->tempRoot)) {
            @unlink($this->tempRoot . '/CLAUDE.md');
            @rmdir($this->tempRoot);
        }
        $this->tempRoot = '';
    }

    private function writeClaudeMd(string $body): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/tina4-docdrift-' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0700, true);
        file_put_contents($this->tempRoot . '/CLAUDE.md', $body);
        return $this->tempRoot;
    }

    /** GREEN: the shipped CLAUDE.md must resolve fully against the live code. */
    public function testRealRepoIsClean(): void
    {
        $problems = Tina4DocDriftAudit::check(realpath(self::REPO_ROOT));
        $this->assertSame([], $problems, "unexpected doc drift:\n" . implode("\n", $problems));
    }

    /** RED: a CapWord base that resolves to no Tina4 class is flagged. */
    public function testFlagsNonexistentClass(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nuse Tina4\\Auth;\nreturn CRUD::toCrud(\$request, []);\n```\n"
        );
        $problems = Tina4DocDriftAudit::check($root);
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('CRUD::toCrud', implode("\n", $problems));
    }

    /** GREEN: a real class + a real method passes. */
    public function testAcceptsRealApi(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nAuth::hashPassword('secret');\nRouter::get('/', fn() => 'hi');\n```\n"
        );
        $this->assertSame([], Tina4DocDriftAudit::check($root));
    }

    /** RED: a real Tina4 class with a method it does not own is flagged. */
    public function testFlagsMissingMethodOnRealClass(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nAuth::summonEverything();\n```\n"
        );
        $problems = Tina4DocDriftAudit::check($root);
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('summonEverything', implode("\n", $problems));
    }

    /** RED: a use import that resolves to no real Tina4 type is flagged. */
    public function testFlagsMissingUseImport(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nuse Tina4\\CrudMagicThatDoesNotExist;\n```\n"
        );
        $problems = Tina4DocDriftAudit::check($root);
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('CrudMagicThatDoesNotExist', implode("\n", $problems));
    }

    /** GREEN: a real use import passes. */
    public function testAcceptsRealUseImport(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nuse Tina4\\Database\\Database;\n\$db = Database::create('sqlite::memory:');\n```\n"
        );
        $this->assertSame([], Tina4DocDriftAudit::check($root));
    }

    /** A magic-method class (ORM) is not flagged for a name Reflection can't see. */
    public function testMagicMethodClassNotFalselyFlagged(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nuse Tina4\\ORM;\nORM::someDynamicFinderName();\n```\n"
        );
        $this->assertSame([], Tina4DocDriftAudit::check($root));
    }

    /** Example model names and fence-local classes are treated as app code. */
    public function testExampleModelsAndLocalClassesIgnored(): void
    {
        $root = $this->writeClaudeMd(
            "```php\nclass Basket extends \\Tina4\\ORM {}\n"
            . "User::create(['name' => 'a']);\nBasket::query();\n```\n"
        );
        $this->assertSame([], Tina4DocDriftAudit::check($root));
    }
}
