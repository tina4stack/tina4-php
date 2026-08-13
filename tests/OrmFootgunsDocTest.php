<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boot-gate for the documented ORM footguns in the tina4-developer-php skill
 * (references/data-and-orm.md → "ORM Lifecycle & Footguns"). Every idiom the
 * doc tells a developer to rely on is EXERCISED here against a REAL in-memory
 * SQLite database — no mocks — with positive AND negative cases. If the
 * framework's write/read contract drifts away from what the skill documents,
 * one of these named tests goes red and the doc is provably stale.
 *
 * Mirrors tina4-python/tests/test_orm_footguns_doc.py, adapted to the PHP
 * contract (which differs from Python on the delete-family and constructor
 * paths — see the individual tests). Also pins the v3.13.60 save() DX hint
 * (ORM::writeErrorHint()) that this skill pass added: getError() must turn a
 * bare "no such table" / missing-is_deleted cause into an actionable fix.
 *
 * Engine-agnostic: in-memory SQLite, so it runs with no DB server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseException;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

// ── Test Models ──────────────────────────────────────────────────────────────

/** A plain note. Used for the create-table / save happy path + missing-table. */
class DocNote extends ORM
{
    public string $tableName  = 'doc_notes';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $title = null;
}

/**
 * Points at a table that is NEVER created — save() hits "no such table", so we
 * can assert the DX hint fires and the raw cause is preserved.
 */
class DocGhost extends ORM
{
    public string $tableName  = 'doc_ghost_missing';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $label = null;
}

/**
 * A soft-delete model that declares is_deleted (the CORRECT idiom the fixed
 * skill example uses). createTable() builds a table WITH the column, so the
 * whole soft-delete lifecycle works.
 */
class DocArticle extends ORM
{
    public string $tableName  = 'doc_articles';
    public string $primaryKey = 'id';
    public bool   $softDelete  = true;
    public bool   $autoMap    = true;

    public ?int    $id         = null;
    public ?string $title      = null;
    public int     $is_deleted = 0;   // declared here; createTable() also INJECTS it for a soft-delete model that omits it (SOFTDEL-DEC-02)
}

/**
 * A soft-delete model whose table is created WITHOUT is_deleted (schema drift).
 * save() writes is_deleted → the driver rejects it → the DX hint must point at
 * declaring the column.
 */
class DocSoftDrift extends ORM
{
    public string $tableName  = 'doc_soft_drift';
    public string $primaryKey = 'id';
    public bool   $softDelete  = true;
    public bool   $autoMap    = true;

    public ?int    $id         = null;
    public ?string $title      = null;
    public int     $is_deleted = 0;
}

/**
 * A model whose `_db` names a connection that was never registered — every
 * query must raise the "bind a database first" precondition, in isolation from
 * the global bound in setUp().
 */
class DocUnbound extends ORM
{
    public string $tableName = 'doc_unbound';
    public string $primaryKey = 'id';
    /** A named connection that is never registered via bindDatabase(). */
    public DatabaseAdapter|string|null $_db = 'no_such_connection';

    public ?int $id = null;
}

class OrmFootgunsDocTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE doc_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        // doc_soft_drift is created WITHOUT is_deleted on purpose (schema drift).
        $this->db->exec('CREATE TABLE doc_soft_drift (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ── save() fails SOFT (returns false, never raises) + cause recoverable ──

    /**
     * The documented write contract: save() returns $this on success and false
     * on failure — it NEVER raises — and the cause is recoverable via
     * getError()/error()/lastError. `try { save() } catch` is the documented
     * anti-pattern; prove the happy + validation-free DB-error shapes.
     */
    public function testSaveReturnsSelfOrFalseNeverRaises(): void
    {
        $ok = (new DocNote($this->db, ['title' => 'hello']))->save();
        $this->assertInstanceOf(DocNote::class, $ok, 'a valid save() returns the fluent instance');
        $this->assertNull($ok->getError(), 'getError() clears on success');

        // A driver error (missing table) returns false — not an exception.
        $ghost = new DocGhost($this->db);
        $ghost->label = 'x';
        $result = $ghost->save();
        $this->assertFalse($result, 'save() into a missing table returns false, does NOT raise');
        $this->assertNotNull($ghost->getError(), 'the cause is recoverable via getError()');
        $this->assertSame($ghost->getError(), $ghost->lastError, 'getError() mirrors lastError');
    }

    // ── save() DX hint (v3.13.60) — "no such table" → createTable()/migrate ──

    /**
     * A "no such table" cause must be augmented with an actionable fix while
     * keeping the raw driver text (so nothing is masked). This is the boot-gate
     * that the documented save() hint actually appears.
     */
    public function testSaveHintOnMissingTable(): void
    {
        $ghost = new DocGhost($this->db);
        $ghost->label = 'x';
        $ghost->save();

        $error = (string) $ghost->getError();
        $this->assertStringContainsStringIgnoringCase('no such table', $error, 'raw driver cause is preserved');
        $this->assertStringContainsString('createTable()', $error, 'the fix hint names createTable()');
        $this->assertStringContainsStringIgnoringCase('migration', $error, 'the fix hint offers a migration');
    }

    /**
     * A $softDelete model whose is_deleted column is missing must get the
     * declare-is_deleted hint (the second-commonest write footgun).
     */
    public function testSaveHintOnMissingIsDeletedColumn(): void
    {
        $drift = new DocSoftDrift($this->db);
        $drift->title = 'x';
        $result = $drift->save();

        $this->assertFalse($result, 'writing a missing is_deleted column fails soft');
        $error = (string) $drift->getError();
        $this->assertStringContainsString('is_deleted', $error, 'the cause names is_deleted');
        $this->assertStringContainsStringIgnoringCase('softDelete', $error, 'the hint explains softDelete needs the column');
    }

    /**
     * The hint must NOT fire on an unrelated error — a NOT NULL violation keeps
     * its raw cause with no spurious "table does not exist" tail.
     */
    public function testHintDoesNotMaskUnrelatedError(): void
    {
        $this->db->exec('CREATE TABLE doc_strict (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        // Insert a row whose NOT NULL `name` is absent — the driver rejects it.
        $bad = new class ($this->db) extends ORM {
            public string $tableName = 'doc_strict';
            public bool $autoMap = true;
            public ?int $id = null;
            public ?string $name = null;
        };
        $bad->name = null;
        $bad->save();

        $error = (string) $bad->getError();
        $this->assertNotSame('', $error, 'a NOT NULL violation still records a cause');
        $this->assertStringNotContainsStringIgnoringCase(
            'does not exist',
            $error,
            'a NOT NULL error must NOT be tagged with a spurious missing-table hint'
        );
    }

    // ── createTable() builds from declared props; soft-delete lifecycle works ──

    /**
     * The documented soft-delete idiom end-to-end: declare is_deleted,
     * createTable() (which injects nothing — the column comes from the
     * declaration), then delete/all/withTrashed/restore all behave.
     */
    public function testSoftDeleteLifecycleWithDeclaredColumn(): void
    {
        $this->assertTrue((new DocArticle($this->db))->createTable(), 'createTable() succeeds');
        $this->assertTrue($this->db->tableExists('doc_articles'));

        $article = (new DocArticle($this->db, ['title' => 'A']))->save();
        $this->assertInstanceOf(DocArticle::class, $article);

        $this->assertCount(1, (new DocArticle($this->db))->all(), 'the live row is visible');

        $article->delete();  // soft — sets is_deleted = 1
        $this->assertCount(0, (new DocArticle($this->db))->all(), 'soft-deleted row excluded from all()');
        $this->assertCount(1, (new DocArticle($this->db))->withTrashed(), 'withTrashed() still sees it');

        $this->assertTrue($article->restore(), 'restore() flips is_deleted back');
        $this->assertCount(1, (new DocArticle($this->db))->all(), 'restored row is visible again');
    }

    /**
     * SOFTDEL-DEC-02: createTable() INJECTS is_deleted for a soft-delete model
     * that does NOT declare it, so the soft-delete lifecycle works with no manual
     * column (the old footgun -- a table with no is_deleted column -- is closed).
     */
    public function testCreateTableInjectsIsDeletedForSoftDelete(): void
    {
        $model = new class ($this->db) extends ORM {
            public string $tableName = 'doc_soft_auto';
            public bool $softDelete = true;
            public bool $autoMap = true;
            public ?int $id = null;
            public ?string $title = null;
        };
        $this->assertTrue($model->createTable());
        $row = $this->db->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='doc_soft_auto'"
        );
        $this->assertStringContainsString('is_deleted', $row['sql'], 'createTable() injected the flag column');

        // The injected column is usable: soft delete flags + excludes the row.
        $model->title = 'x';
        $this->assertNotFalse($model->save());
        $this->assertTrue($model->delete());
        $this->assertCount(0, (new $model($this->db))->all());
    }

    // ── delete()/restore() PHP contract — false on a precondition, no raise ──

    /**
     * PHP differs from Python here: delete() on an unsaved instance (no PK)
     * returns FALSE (Python raises). restore() on a non-soft model returns
     * FALSE (Python raises RuntimeError). Pin the PHP contract so a "port"
     * doesn't silently switch it to Python's raising shape.
     */
    public function testDeleteAndRestorePreconditionsReturnFalse(): void
    {
        $unsaved = new DocArticle($this->db);   // id is null → no PK
        $this->assertFalse($unsaved->delete(), 'delete() with no PK returns false (does NOT raise)');

        $plain = new DocNote($this->db);        // not a soft-delete model
        $this->assertFalse($plain->restore(), 'restore() on a non-soft model returns false');
    }

    // ── db execute() raises (does NOT return false) ──────────────────────────

    /**
     * A raw write via the adapter's execute() fails LOUD — it raises a
     * DatabaseException, it does not return false. (save() wraps this and
     * converts it to false, but a direct execute() propagates.)
     */
    public function testExecuteRaisesOnBadSql(): void
    {
        $this->expectException(DatabaseException::class);
        $this->db->execute('INSERT INTO doc_definitely_missing (x) VALUES (1)');
    }

    // ── Bind a database before any ORM call ──────────────────────────────────

    /**
     * An ORM query with no resolvable connection raises — the "bind a database
     * first" precondition. Uses an unregistered NAMED connection so it stays
     * isolated from the global bound in setUp().
     */
    public function testUnboundNamedConnectionRaises(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DocUnbound())->count();
    }

    // ── Route param types are a fixed set ────────────────────────────────────

    /**
     * A typed path param must use a known type. A typo raises
     * InvalidArgumentException at registration (never a silent match-anything
     * fall-through); a known type registers cleanly.
     */
    public function testUnknownRouteParamTypeRaises(): void
    {
        // Known type registers without throwing.
        \Tina4\Router::get('/__footgun_ok/{id:int}', fn ($request, $response) => null);

        $this->expectException(\InvalidArgumentException::class);
        \Tina4\Router::get('/__footgun_bad/{id:inetger}', fn ($request, $response) => null);
    }
}
