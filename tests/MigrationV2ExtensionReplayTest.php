<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Parity investigation for tina4-python#128 (v3 commit 3db7332), applied to tina4-php.
 *
 * The Python bug: Python v2 recorded a migration's identifier as the raw filename
 * WITH its extension (".sql"/".py"), but Python v3 matches recorded names against
 * file STEMS (no extension). The two never matched, so an upgraded v2 history looked
 * entirely unapplied and replayed on the first v3 boot. Python's fix teaches its
 * v2->v3 resolver to also try the extension-stripped form of the recorded name.
 *
 * tina4-php is NOT affected by that failure mode, and these tests lock that in:
 *   - PHP stores `migration_name` WITH the file extension end to end. A normal
 *     migrate() records basename($file) (Migration::migrate, WITH ".sql"), and the
 *     v2->v3 backfill resolves each legacy row to a file basename or falls back to
 *     "<prefix>.sql" (Migration::upgradeV2ToV3) — both extension-bearing.
 *   - The applied-check compares the on-disk basename (WITH extension) against that
 *     stored `migration_name` (Migration::getPendingMigrations: `in_array($base,
 *     $appliedNames, true)` where `$base = basename($file)`), plus a 14-char
 *     timestamp-prefix fallback on the legacy `migration_id` column.
 *
 * So an extension-bearing recorded name is PHP's native, correct format and matches
 * the file on disk exactly — there is no stem comparison to strip an extension from,
 * so the Python failure mode cannot occur here. These tests prove an extension-bearing
 * v2-style record is seen as applied and does NOT re-run, while a genuinely unapplied
 * migration still runs. Real SQLite, no mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

class MigrationV2ExtensionReplayTest extends TestCase
{
    private SQLite3Adapter $db;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_v2ext_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->removeDir($this->migrationsDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function rowCount(string $table): int
    {
        $rows = $this->db->query("SELECT COUNT(*) AS c FROM {$table}");
        return (int)($rows[0]['c'] ?? $rows[0]['C'] ?? 0);
    }

    // ── Positive: an extension-bearing migration_name is seen as APPLIED and does
    //    NOT re-run. The migration is non-idempotent, so a replay fails loudly. ──

    public function testExtensionBearingMigrationNameSeenAsAppliedAndDoesNotRerun(): void
    {
        // A non-idempotent applied migration: re-running its INSERT trips the UNIQUE
        // constraint (mirrors the Python production case — Hertex app-portal, 90
        // migrations replayed on a 0.2.206 -> 3.13.52 upgrade).
        $this->db->exec("CREATE TABLE checklist_item_group (group_name TEXT UNIQUE)");
        $this->db->exec("INSERT INTO checklist_item_group (group_name) VALUES ('window_&_entrance')");

        $fileName = '0000002_data_migration_for_show_room.sql';
        file_put_contents(
            $this->migrationsDir . '/' . $fileName,
            "INSERT INTO checklist_item_group (group_name) VALUES ('window_&_entrance');"
        );

        // Canonical v3 table, then record the migration v2-style: migration_name
        // carries the ".sql" extension (PHP's native format / what the v2->v3
        // backfill writes).
        $migration = new Migration($this->db, $this->migrationsDir);
        $migration->recordMigration($fileName, 1);

        // The applied-with-extension record must be recognised as applied → not pending.
        $this->assertNotContains(
            $fileName,
            $migration->getPending(),
            'An extension-bearing migration_name must be recognised as applied'
        );

        $result = $migration->migrate();

        $this->assertSame([], $result['errors'], 'Nothing should re-run, so no error');
        $this->assertSame(
            [],
            $result['applied'],
            'An already-applied extension-bearing migration must NOT re-run'
        );
        $this->assertSame(
            1,
            $this->rowCount('checklist_item_group'),
            'A replay would have inserted a duplicate row (or failed on the UNIQUE constraint)'
        );
    }

    // ── End-to-end: a real PHP v2 table upgraded in place must not replay its
    //    history. Non-idempotent, so a replay fails loudly instead of silently
    //    succeeding the way a CREATE TABLE IF NOT EXISTS would. ──

    public function testV2HistoryWithExtensionDoesNotReplayAfterUpgrade(): void
    {
        $this->db->exec("CREATE TABLE checklist_item_group (group_name TEXT UNIQUE)");
        $this->db->exec("INSERT INTO checklist_item_group (group_name) VALUES ('entrance')");

        // The real file on disk carries the ".sql" extension.
        file_put_contents(
            $this->migrationsDir . '/20240301120000_data_migration.sql',
            "INSERT INTO checklist_item_group (group_name) VALUES ('entrance');"
        );

        // A genuine PHP v2 tina4_migration table: migration_id is the 14-char
        // timestamp prefix, and there is no migration_name column at all.
        $this->db->exec(
            "CREATE TABLE tina4_migration ("
            . " migration_id VARCHAR(14) NOT NULL PRIMARY KEY,"
            . " description VARCHAR(1000) DEFAULT '',"
            . " content BLOB,"
            . " passed INTEGER DEFAULT 0"
            . ")"
        );
        $this->db->exec(
            "INSERT INTO tina4_migration (migration_id, description, content, passed)"
            . " VALUES ('20240301120000', 'data migration', '', 1)"
        );

        // Construction triggers upgradeV2ToV3(): it backfills migration_name to the
        // file basename WITH extension, keyed off the 14-char timestamp prefix.
        $migration = new Migration($this->db, $this->migrationsDir);
        $result = $migration->migrate();

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [],
            $result['applied'],
            'An upgraded v2 history must NOT replay on the first v3 migrate()'
        );
        $this->assertSame(1, $this->rowCount('checklist_item_group'));
    }

    // ── Negative: a genuinely unapplied migration still runs. The applied-with-
    //    extension migration is skipped by name while the untracked one applies. ──

    public function testGenuinelyUnappliedMigrationStillRuns(): void
    {
        $appliedFile = '0000002_data_migration_for_show_room.sql';
        file_put_contents(
            $this->migrationsDir . '/' . $appliedFile,
            "CREATE TABLE show_room (id INTEGER);"
        );
        $pendingFile = '0000003_add_widgets.sql';
        file_put_contents(
            $this->migrationsDir . '/' . $pendingFile,
            "CREATE TABLE widgets (id INTEGER);"
        );

        $migration = new Migration($this->db, $this->migrationsDir);
        // Record only the first, v2-style WITH extension, WITHOUT running its DDL;
        // the second file is left untracked.
        $migration->recordMigration($appliedFile, 1);

        $result = $migration->migrate();

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [$pendingFile],
            $result['applied'],
            'Only the genuinely unapplied migration must run'
        );
        $this->assertTrue($this->db->tableExists('widgets'), 'The pending migration ran');
        $this->assertFalse(
            $this->db->tableExists('show_room'),
            'The applied (extension-bearing) migration must NOT re-run'
        );
    }
}
