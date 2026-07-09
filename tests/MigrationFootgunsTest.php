<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for the migration footgun fixes (v3.13.39) — mirrors
 * tina4-python tests/test_migration_footguns.py.
 *
 * [10] `//` delimiter no longer swallows a URL (`https://…`) as a stored-proc block.
 * [8]  discovery sort is numeric-aware (`9_` before `10_`).
 * [9]  CREATE TABLE is idempotent on engines lacking IF NOT EXISTS (Firebird/MSSQL),
 *      NOT on SQLite, and NOT when the table is absent.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Migration;

/**
 * A fake MSSQL adapter that does NOT connect (the real ctor requires ext-sqlsrv).
 * It is `instanceof \Tina4\Database\MSSQLAdapter`, which is all the CREATE-TABLE
 * idempotency guard checks, and lets us control tableExists().
 */
class FakeMSSQLAdapter extends \Tina4\Database\MSSQLAdapter
{
    public function __construct(private bool $exists)
    {
        // Intentionally do NOT call parent::__construct() — no real connection.
    }

    // The Migration ctor checks for the tracking table; report it present so
    // ensureMigrationsTable() takes the no-op path (no real connection needed).
    public function tableExists(string $tableName): bool
    {
        if ($tableName === 'tina4_migration') {
            return true;
        }
        return $this->exists;
    }

    public function getColumns(string $tableName): array
    {
        // Report the canonical v3 tracking-table shape so ensureMigrationsTable()
        // treats it as already-current (no v2 / older-v3 in-place upgrade).
        return [
            ['name' => 'id'],
            ['name' => 'migration_name'],
            ['name' => 'description'],
            ['name' => 'batch'],
            ['name' => 'executed_at'],
            ['name' => 'passed'],
        ];
    }
}

/** Fake Firebird adapter — same trick. */
class FakeFirebirdAdapter extends \Tina4\Database\FirebirdAdapter
{
    public function __construct(private bool $exists)
    {
    }

    public function tableExists(string $tableName): bool
    {
        if ($tableName === 'tina4_migration') {
            return true;
        }
        return $this->exists;
    }

    public function getColumns(string $tableName): array
    {
        // Report the canonical v3 tracking-table shape so ensureMigrationsTable()
        // treats it as already-current (no v2 / older-v3 in-place upgrade).
        return [
            ['name' => 'id'],
            ['name' => 'migration_name'],
            ['name' => 'description'],
            ['name' => 'batch'],
            ['name' => 'executed_at'],
            ['name' => 'passed'],
        ];
    }
}

class MigrationFootgunsTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/tina4_mig_footguns_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    private function newMigration(\Tina4\Database\DatabaseAdapter $db): Migration
    {
        return new Migration($db, $this->migrationsDir);
    }

    private function invoke(Migration $m, string $method, array $args)
    {
        $ref = new \ReflectionMethod(Migration::class, $method);
        return $ref->invokeArgs($m, $args);
    }

    private static function staticInvoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(Migration::class, $method);
        return $ref->invokeArgs(null, $args);
    }

    // ── [10] `//` delimiter must not swallow a URL ──────────────────────

    public function testSplitDoesNotSwallowUrlScheme(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $sql = "INSERT INTO cfg (k, v) VALUES ('home', 'https://a.example.com');\n"
            . "INSERT INTO cfg (k, v) VALUES ('cb', 'https://b.example.com');";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(2, $stmts, 'URL `//` was captured as a block, breaking split');
        $this->assertStringContainsString('https://a.example.com', $stmts[0]);
        $this->assertStringContainsString('https://b.example.com', $stmts[1]);
    }

    public function testSplitStillHandlesRealStoredProcBlock(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // A genuine `// ... //` stored-proc block (delimiters not preceded by `:`)
        // is still kept intact as one statement.
        $sql = "CREATE PROCEDURE foo() // BEGIN SELECT 1; SELECT 2; END //;";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $found = false;
        foreach ($stmts as $s) {
            if (str_contains($s, 'BEGIN SELECT 1; SELECT 2; END')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'real // stored-proc block was split incorrectly');
    }

    // ── smart/curly quotes normalized before split ──────────────────────

    public function testSmartQuotesNormalizedBeforeSplit(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // Smart double quotes around an identifier + smart single quotes around
        // a value — as an editor/doc/chat app would produce. They must become
        // straight ASCII so the statement actually runs.
        $sql = "CREATE TABLE \u{201C}users\u{201D} (name TEXT DEFAULT \u{2018}guest\u{2019});";

        $joined = implode(' ', $this->invoke($m, 'splitStatements', [$sql]));

        foreach (["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{2032}", "\u{2033}"] as $smart) {
            $this->assertStringNotContainsString($smart, $joined, "smart quote survived");
        }
        $this->assertStringContainsString('"users"', $joined);
        $this->assertStringContainsString("'guest'", $joined);
    }

    public function testNormalizeQuotesPreservesStraightAndContent(): void
    {
        // Straight quotes and ordinary content are returned byte-for-byte.
        $sql = "INSERT INTO t (v) VALUES ('plain');";
        $this->assertSame($sql, self::staticInvoke('normalizeQuotes', [$sql]));
    }

    // ── SET TERM switches the delimiter so PSQL bodies survive ──────────
    //
    // Firebird triggers / procedures / EXECUTE BLOCK have ';'-terminated inner
    // statements. Run under the DEFAULT ';' runner (auto-migrate) they used to be
    // shredded on those inner ';'. A `SET TERM <new> <cur>` directive — the
    // universal isql/FlameRobin/gfix idiom — switches the terminator so the body
    // stays one statement.

    public function testSetTermKeepsFirebirdTriggerBodyIntact(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $sql = "SET TERM ^ ;\n"
            . "CREATE OR ALTER TRIGGER t_bi FOR t ACTIVE BEFORE INSERT AS\n"
            . "BEGIN\n"
            . "  IF (NEW.id IS NULL) THEN NEW.id = GEN_ID(GEN_T, 1);\n"
            . "END^\n"
            . "SET TERM ; ^";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(1, $stmts, 'trigger body was split on its inner ";"');
        $this->assertStringStartsWith('CREATE OR ALTER TRIGGER', $stmts[0]);
        $this->assertStringEndsWith('END', $stmts[0]);
        $this->assertStringContainsString('NEW.id = GEN_ID(GEN_T, 1);', $stmts[0]);
    }

    public function testSetTermDirectivesAreConsumedNotEmitted(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $sql = "SET TERM ^ ;\nEXECUTE BLOCK AS BEGIN a; b; END^\nSET TERM ; ^";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(1, $stmts);
        foreach ($stmts as $s) {
            $this->assertStringNotContainsString('SET TERM', $s, 'SET TERM leaked as a SQL statement');
        }
    }

    public function testSetTermRestoresPreviousDelimiter(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // After `SET TERM ; ^`, plain ';' splitting resumes.
        $sql = "CREATE TABLE a (id INT);\n"
            . "SET TERM ^ ;\n"
            . "CREATE TRIGGER a_bi FOR a AS BEGIN NEW.id = 1; END^\n"
            . "SET TERM ; ^\n"
            . "INSERT INTO a VALUES (1);\nUPDATE a SET id = 2;";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(4, $stmts, 'delimiter not restored to ";" after the block');
        $this->assertStringStartsWith('CREATE TABLE', $stmts[0]);
        $this->assertStringStartsWith('CREATE TRIGGER', $stmts[1]);
        $this->assertStringStartsWith('INSERT INTO', $stmts[2]);
        $this->assertStringStartsWith('UPDATE', $stmts[3]);
    }

    public function testSetTermSupportsMultiCharTerminator(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $sql = "SET TERM !! ;\nCREATE TRIGGER t FOR x AS BEGIN NEW.a = 1; END!!\nSET TERM ; !!";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(1, $stmts);
        $this->assertStringNotContainsString('!!', $stmts[0]);
        $this->assertStringNotContainsString('SET TERM', $stmts[0]);
    }

    public function testPlainSemicolonUnaffectedBySetTermSupport(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // No SET TERM → ordinary ';' splitting, behaviour unchanged.
        $sql = "CREATE TABLE a (id INT);\nINSERT INTO a VALUES (1);\nUPDATE a SET id = 2;";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(3, $stmts);
        foreach ($stmts as $s) {
            $this->assertFalse(str_ends_with($s, ';'), 'statement kept its trailing ";"');
        }
    }

    // ── [8] numeric-aware discovery order ───────────────────────────────

    public function testGetMigrationFilesIsNumericAware(): void
    {
        foreach (['10_b.sql', '9_a.sql', '2_x.sql', 'alpha.sql'] as $name) {
            file_put_contents($this->migrationsDir . '/' . $name, '-- ' . $name);
        }
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));

        $names = array_map('basename', $m->getMigrationFiles());

        $this->assertSame(['2_x.sql', '9_a.sql', '10_b.sql', 'alpha.sql'], $names);
    }

    public function testSortKeyIsNumericAware(): void
    {
        $names = ['10_b.sql', '9_a.sql', '2_x.sql', 'alpha.sql'];
        usort($names, function (string $a, string $b): int {
            return self::staticInvoke('migrationSortKey', [$a])
                <=> self::staticInvoke('migrationSortKey', [$b]);
        });
        $this->assertSame(['2_x.sql', '9_a.sql', '10_b.sql', 'alpha.sql'], $names);
    }

    // ── [9] CREATE TABLE idempotency on Firebird/MSSQL ──────────────────

    public function testCreateTableSkippedOnMssqlWhenTableExists(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('users', $reason);
    }

    public function testCreateTableSkippedOnFirebirdQuotedWhenExists(): void
    {
        $m = $this->newMigration(new FakeFirebirdAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE "Orders" (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Orders', $reason);
    }

    public function testCreateTableSkippedOnMssqlBracketedWhenExists(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE [Things] (id INT)']);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Things', $reason);
    }

    public function testCreateTableNotSkippedWhenAbsent(): void
    {
        $m = $this->newMigration(new FakeFirebirdAdapter(false));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNull($reason);
    }

    public function testCreateTableNotSkippedOnSqliteLeftToIfNotExists(): void
    {
        // SQLite supports IF NOT EXISTS → never skipped by this guard, even when
        // the table really exists.
        $db = new SQLite3Adapter(':memory:');
        $db->exec('CREATE TABLE users (id INTEGER)');
        $m = $this->newMigration($db);
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['CREATE TABLE users (id INT)']);
        $this->assertNull($reason);
    }

    public function testNonCreateStatementIgnored(): void
    {
        $m = $this->newMigration(new FakeMSSQLAdapter(true));
        $reason = $this->invoke($m, 'shouldSkipCreateTable', ['INSERT INTO users VALUES (1)']);
        $this->assertNull($reason);
    }

    // ── [#54] a ';' inside a comment or string literal must NOT split a statement ──

    public function testSemicolonInLineCommentDoesNotFragment(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        // The exact issue #54 repro: a ';' inside a '-- …' line comment used to
        // fragment this ONE CREATE TABLE into broken pieces.
        $sql = "CREATE TABLE users (\n"
            . "    id INTEGER PRIMARY KEY,   -- drop then re-add; old way\n"
            . "    name TEXT NOT NULL\n"
            . ");";

        $stmts = $this->invoke($m, 'splitStatements', [$sql]);

        $this->assertCount(1, $stmts, "';' inside a -- comment fragmented the statement");
        $this->assertStringContainsString('id INTEGER PRIMARY KEY', $stmts[0]);
        $this->assertStringContainsString('name TEXT NOT NULL', $stmts[0]);
        $this->assertStringNotContainsString('old way', $stmts[0], 'line comment text was not stripped');
    }

    public function testSemicolonInBlockCommentDoesNotFragment(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $stmts = $this->invoke($m, 'splitStatements', ['CREATE TABLE t (id INTEGER /* a; b; c */, name TEXT);']);
        $this->assertCount(1, $stmts);
        $this->assertStringNotContainsString('a; b; c', $stmts[0], 'block comment text was not stripped');
    }

    public function testSemicolonInStringLiteralDoesNotSplit(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $stmts = $this->invoke($m, 'splitStatements', ["INSERT INTO t (v) VALUES ('a;b;c'); INSERT INTO t (v) VALUES ('d');"]);
        $this->assertCount(2, $stmts, "';' inside a string literal split the statement");
        $this->assertStringContainsString("'a;b;c'", $stmts[0]);
    }

    public function testDoubleDashInStringLiteralIsNotComment(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $stmts = $this->invoke($m, 'splitStatements', ["INSERT INTO t (v) VALUES ('a--b'); INSERT INTO t (v) VALUES ('c');"]);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString("'a--b'", $stmts[0], "'--' inside a string literal was wrongly stripped as a comment");
    }

    public function testDoubledQuoteEscapeKeepsSemicolonInsideLiteral(): void
    {
        $m = $this->newMigration(new SQLite3Adapter(':memory:'));
        $stmts = $this->invoke($m, 'splitStatements', ["INSERT INTO t (v) VALUES ('O''Brien; Jr'); SELECT 1;"]);
        $this->assertCount(2, $stmts, 'doubled-quote escape mishandled, split wrongly');
        $this->assertStringContainsString("'O''Brien; Jr'", $stmts[0]);
    }

    public function testMigrateAppliesABatchInNumericOrder(): void
    {
        // Regression for a real prod incident: several migrations pending in ONE
        // run, all rewriting the SAME table (later supersedes earlier). If migrate()
        // applies them in filesystem/hash order instead of numeric order, the table
        // is left at a non-final migration's shape. End-to-end against a REAL SQLite
        // DB (no mocks). The sort-key unit test above locks the KEY; this locks the
        // sort actually driving apply order through migrate() (e.g. catches a
        // pending filter regressed to an unordered diff).
        // UNPADDED prefixes on purpose: 1..10 so a LEXICAL sort ("10" < "2")
        // differs from numeric order. Fails under BOTH no-sort AND a lexical-only
        // sort (a numeric-sort regression), not just total disorder. (Zero-padded
        // names sort correctly under a plain lexical sort and would NOT catch it.)
        file_put_contents(
            $this->migrationsDir . '/0_init.sql',
            "CREATE TABLE probe_final (n INTEGER);\n"
            . "INSERT INTO probe_final (n) VALUES (0);\n"
            . "CREATE TABLE probe_log (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER);"
        );
        // Write 1..10 in SCRAMBLED order so creation order != numeric order.
        foreach ([7, 3, 10, 1, 5, 9, 2, 8, 4, 6] as $n) {
            file_put_contents(
                sprintf('%s/%d_step.sql', $this->migrationsDir, $n),
                sprintf("UPDATE probe_final SET n = %d;\nINSERT INTO probe_log (n) VALUES (%d);", $n, $n)
            );
        }

        $db = new SQLite3Adapter(':memory:');
        $result = $this->newMigration($db)->migrate();

        $expected = ['0_init.sql'];
        for ($n = 1; $n <= 10; $n++) {
            $expected[] = "{$n}_step.sql";
        }
        $this->assertSame([], $result['errors'], 'batch reported errors');
        $this->assertSame($expected, $result['applied'], 'batch applied out of numeric order');

        // The real apply sequence recorded in the DB is strictly numeric (a lexical
        // sort would put 10 before 2). The raw adapter's fetch() returns ['data' => rows].
        $log = array_map(
            static fn (array $r): int => (int) $r['n'],
            $db->fetch('SELECT n FROM probe_log ORDER BY id')['data']
        );
        $this->assertSame(range(1, 10), $log, 'migrations ran out of order');

        // The last-applied migration wins — the exact contract that broke in prod.
        $final = $db->fetch('SELECT n FROM probe_final')['data'];
        $this->assertSame(10, (int) $final[0]['n']);
    }

    public function testMigrateAppliesStatementWithSemicolonInComment(): void
    {
        // End-to-end against a REAL SQLite DB (no mocks): the issue #54 repro
        // migration must apply cleanly and create the table.
        file_put_contents(
            $this->migrationsDir . '/000001_create_users.sql',
            "CREATE TABLE users (\n"
            . "    id INTEGER PRIMARY KEY,   -- drop then re-add; old way\n"
            . "    name TEXT NOT NULL\n"
            . ");"
        );
        $db = new SQLite3Adapter(':memory:');
        $m = $this->newMigration($db);

        $result = $m->migrate();

        $this->assertContains('000001_create_users.sql', $result['applied'], 'repro migration did not apply');
        $this->assertSame([], $result['errors'], 'repro migration reported errors');
        $this->assertTrue($db->tableExists('users'), 'repro migration did not create the table');
        // The table is real and usable: exec() is fail-loud, so a broken/missing
        // column from a fragmented statement would raise here. Reaching the
        // assertion (no throw) proves the comment-bearing CREATE applied whole.
        $this->assertNotFalse(
            $db->exec("INSERT INTO users (name) VALUES ('Alice')"),
            'INSERT into the migrated table failed'
        );
    }
}
