<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — Migration default-path resolution.
 *
 * The Migration constructor default was 'src/migrations' while createMigration(), the CLI,
 * App auto-migrate, Metrics, MCP, and the Python reference all use 'migrations/' (project root).
 * The default is now 'migrations/', with a legacy 'src/migrations/' fallback. Real SQLite, no mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

class MigrationDefaultPathTest extends TestCase
{
    private string $projectDir;
    private string $oldCwd;

    protected function setUp(): void
    {
        $this->oldCwd = getcwd();
        $this->projectDir = sys_get_temp_dir() . '/tina4_mig_default_' . uniqid();
        mkdir($this->projectDir, 0755, true);
        chdir($this->projectDir);           // migrations dir is resolved relative to CWD
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);
        $this->rmrf($this->projectDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeMigration(string $dir, string $table): string
    {
        mkdir($dir, 0755, true);
        $name = '000001_create_' . $table . '.sql';
        file_put_contents($dir . '/' . $name, "CREATE TABLE {$table} (id INTEGER PRIMARY KEY);");
        return $name;
    }

    public function testDefaultUsesMigrationsAtProjectRoot(): void
    {
        $name = $this->writeMigration('migrations', 'widgets_root');
        $res = (new Migration(new SQLite3Adapter(':memory:')))->migrate();   // no dir -> 'migrations'
        $this->assertContains($name, $res['applied'], 'default should apply from migrations/');
    }

    public function testLegacySrcMigrationsFallback(): void
    {
        $name = $this->writeMigration('src/migrations', 'widgets_legacy');   // only the legacy layout
        $res = (new Migration(new SQLite3Adapter(':memory:')))->migrate();   // migrations/ absent -> fallback
        $this->assertContains($name, $res['applied'], 'should fall back to legacy src/migrations/');
    }

    public function testMigrationsRootWinsWhenBothExist(): void
    {
        $rootName = $this->writeMigration('migrations', 'widgets_win');
        $this->writeMigration('src/migrations', 'widgets_lose');
        $res = (new Migration(new SQLite3Adapter(':memory:')))->migrate();
        $this->assertContains($rootName, $res['applied']);
        $this->assertNotContains('000001_create_widgets_lose.sql', $res['applied'],
            'canonical migrations/ must win over legacy src/migrations/');
    }

    public function testExplicitDirIsRespected(): void
    {
        $name = $this->writeMigration('custom_migs', 'widgets_custom');
        $res = (new Migration(new SQLite3Adapter(':memory:'), 'custom_migs'))->migrate();
        $this->assertContains($name, $res['applied']);
    }
}
