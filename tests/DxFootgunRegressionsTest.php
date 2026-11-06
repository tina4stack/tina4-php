<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Regressions for the LLM DX footguns fixed at parity with the Python master:
 *   #270 — a generated route test must stay GREEN when the handler is filled in.
 *   #297 — `migrate:create create_*_view` scaffolds a VIEW, not a runnable table.
 *   #288 — an empty, unedited scaffold migration is kept pending, not applied.
 *
 * No mocks: real `php bin/tina4php generate ...` subprocess, real phpunit run of
 * the emitted test, real SQLite through \Tina4\Migration.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Migration;

class DxFootgunRegressionsTest extends TestCase
{
    private static string $bin;
    private static string $phpunit;
    private static string $bootstrap;
    private string $origCwd;
    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::$phpunit = realpath(__DIR__ . '/../vendor/bin/phpunit');
        self::$bootstrap = realpath(__DIR__ . '/bootstrap.php');
    }

    protected function setUp(): void
    {
        $this->origCwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4-dxfootgun-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function cli(string $args): array
    {
        $cmd = 'php ' . escapeshellarg(self::$bin) . ' ' . $args . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, "`tina4php {$args}` failed:\n" . implode("\n", $out));
        return $out;
    }

    private function runEmitted(string $relPath): int
    {
        $testFile = $this->tempDir . '/' . $relPath;
        $cmd = escapeshellarg(self::$phpunit)
            . ' --no-configuration --bootstrap ' . escapeshellarg(self::$bootstrap)
            . ' ' . escapeshellarg($testFile) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        return $code;
    }

    // ── #270: the generated route test stays green when the handler is filled ──

    public function testGeneratedRouteTestStaysGreenWhenHandlerFilled(): void
    {
        $this->cli('generate route complaints');
        $this->assertSame(0, $this->runEmitted('tests/ComplaintsTest.php'),
            'generated route test should be green as a stub');

        // Fill list_complaints with a real response, remove the throw.
        $route = $this->tempDir . '/src/routes/complaints.php';
        $src = (string)file_get_contents($route);
        $src = preg_replace(
            '~throw new \\\\RuntimeException\("list_complaints[^\n]*\);[^\n]*~',
            'return $response(["records" => []]);',
            $src,
            1
        );
        file_put_contents($route, $src);

        $this->assertSame(0, $this->runEmitted('tests/ComplaintsTest.php'),
            'filling the handler in turned the suite red (#270)');
    }

    public function testGeneratedRouteTestHasNoSelfDefeatingAssertion(): void
    {
        $this->cli('generate route complaints');
        $test = (string)file_get_contents($this->tempDir . '/tests/ComplaintsTest.php');
        $this->assertStringNotContainsString('IsALiveStub', $test);
        $this->assertStringNotContainsString('expectException(\RuntimeException::class)', $test);
        $this->assertStringContainsString('IsWired', $test);
    }

    // ── #297: a *_view migration scaffolds a VIEW, not a runnable table ──

    public function testViewMigrationScaffoldsAViewNotATable(): void
    {
        $this->cli('generate migration create_open_tickets_view');
        $ups = array_filter(
            glob($this->tempDir . '/migrations/*.sql') ?: [],
            static fn(string $f): bool => !str_ends_with($f, '.down.sql')
        );
        $sql = (string)file_get_contents((string)reset($ups));
        $this->assertStringNotContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('CREATE VIEW', $sql);   // as commented guidance
        foreach (explode("\n", $sql) as $line) {
            if (str_contains($line, 'CREATE VIEW')) {
                $this->assertStringStartsWith('--', ltrim($line), "runnable CREATE VIEW: {$line}");
            }
        }
        // No misleading co-emitted CREATE-TABLE test for a view.
        $this->assertEmpty(glob($this->tempDir . '/tests/*OpenTicketsView*') ?: []);
    }

    // ── #288: an empty scaffold migration is kept pending; real DDL applies ──

    public function testCommentOnlyMigrationIsKeptPendingThenApplies(): void
    {
        $dir = $this->tempDir . '/migrations';
        mkdir($dir, 0755, true);
        $file = $dir . '/000001_add_note.sql';
        file_put_contents($file,
            "-- Migration: add_note\n-- tina4:edit  write the UP migration SQL\n");
        $db = Database::create('sqlite:///' . $this->tempDir . '/app.db');
        $db->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY)');

        $r1 = (new Migration($db, $dir))->migrate();
        $this->assertSame([], $r1['applied'] ?? [], 'empty scaffold must be kept pending');

        file_put_contents($file, "ALTER TABLE widget ADD COLUMN note TEXT DEFAULT '';\n");
        $r2 = (new Migration($db, $dir))->migrate();
        $this->assertSame(['000001_add_note.sql'], $r2['applied'] ?? [], 'filled migration must apply');
        $cols = array_map(static fn($c) => $c['name'] ?? $c, $db->getColumns('widget'));
        $this->assertContains('note', $cols);
    }

    public function testCreateTableScaffoldStillApplies(): void
    {
        $dir = $this->tempDir . '/migrations';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/000001_create_widget.sql',
            "-- Migration: create_widget\n"
            . "CREATE TABLE IF NOT EXISTS widget (\n"
            . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n"
            . "    -- tina4:edit  add columns beyond id + created_at\n"
            . ");\n");
        $db = Database::create('sqlite:///' . $this->tempDir . '/app.db');
        $r = (new Migration($db, $dir))->migrate();
        $this->assertSame(['000001_create_widget.sql'], $r['applied'] ?? []);
        $this->assertTrue($db->tableExists('widget'));
    }
}
