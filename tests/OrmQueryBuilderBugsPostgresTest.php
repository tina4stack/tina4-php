<?php

/**
 * Live-PostgreSQL regression tests for four ORM / QueryBuilder bugs found
 * during PostgreSQL doc verification. Each was invisible on SQLite (the
 * default CI engine) and only surfaced against a real Postgres server.
 *
 *   Bug 1 — QueryBuilder::first()/count() read the raw adapter ['data' => ...]
 *           shape, which is null on the DatabaseResult the Database facade
 *           returns. first() → null, count() → 0 despite matching rows.
 *
 *   Bug 3 — belongsTo with a snake_case FK (foreignKeys = ['author_id' =>
 *           'Author']) + autoMap stores the value under the camelCase property
 *           authorId, but the relationship code read $this->author_id (the DB
 *           column) → null. Lazy $post->author, explicit belongsTo($c,
 *           'author_id'), and include:['author'] all returned null. The reverse
 *           hasMany grouping (Author->posts) was broken the same way.
 *
 *   Bug 4 — PostgresAdapter::fetch() appended `LIMIT n OFFSET m` even when the
 *           user SQL already ended with its own LIMIT, producing
 *           `... LIMIT 1 LIMIT 100 OFFSET 0` → PG syntax error → swallowed →
 *           silent empty result.
 *
 * (Bug 2 — groupBy().get() dropping a group — could not be reproduced; the
 *  query returns every group, so the original report was a data artifact. A
 *  guard test below pins the correct behaviour.)
 *
 * Boots the live PostgreSQL used for doc verification
 * (postgres://tina4:tina4@localhost:5432/tina4_php) and skips automatically
 * when ext-pgsql is missing or the server is unreachable.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseResult;
use Tina4\QueryBuilder;

// ── Test ORM models (Bug 3) ─────────────────────────────────────────────────

class QbBugAuthor extends \Tina4\ORM
{
    public string $tableName = 'qbbug_authors';
    public string $primaryKey = 'id';

    public int $id = 0;
    public string $name = '';

    // Reverse side of the relationship.
    public array $hasMany = ['posts' => 'QbBugPost.author_id'];
}

class QbBugPost extends \Tina4\ORM
{
    public string $tableName = 'qbbug_posts';
    public string $primaryKey = 'id';

    public int $id = 0;
    public string $title = '';
    public int $authorId = 0;     // autoMap → DB column author_id

    // The documented snake_case FK form. Auto-wires belongsTo('author') here
    // and hasMany on QbBugAuthor.
    public array $foreignKeys = ['author_id' => 'QbBugAuthor'];
}

class OrmQueryBuilderBugsPostgresTest extends TestCase
{
    private const PG_HOST = 'localhost';
    private const PG_PORT = 5432;
    private const PG_USER = 'tina4';
    private const PG_PASS = 'tina4';
    private const PG_DB   = 'tina4_php';

    private ?Database $db = null;
    private static ?DatabaseAdapter $savedGlobalDb = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension.');
        }
        if (!self::pgReachable()) {
            $this->markTestSkipped(sprintf(
                'PostgreSQL not reachable at %s:%d — skip integration test',
                self::PG_HOST, self::PG_PORT
            ));
        }

        $url = sprintf('postgres://%s:%d/%s', self::PG_HOST, self::PG_PORT, self::PG_DB);
        $this->db = Database::create($url, autoCommit: true, username: self::PG_USER, password: self::PG_PASS);

        self::$savedGlobalDb = \Tina4\ORM::getGlobalDb();
        \Tina4\ORM::setGlobalDb($this->db);

        $this->dropAll();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->dropAll();
            } finally {
                if (self::$savedGlobalDb !== null) {
                    \Tina4\ORM::setGlobalDb(self::$savedGlobalDb);
                }
                $this->db->close();
            }
            $this->db = null;
        }
    }

    private function dropAll(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS qbbug_posts');
        $this->db->execute('DROP TABLE IF EXISTS qbbug_authors');
        $this->db->execute('DROP TABLE IF EXISTS qbbug_widgets');
        $this->db->commit();
    }

    private function seed(): void
    {
        $this->db->execute('CREATE TABLE qbbug_authors (id SERIAL PRIMARY KEY, name VARCHAR(100))');
        $this->db->execute('CREATE TABLE qbbug_posts (id SERIAL PRIMARY KEY, title VARCHAR(100), author_id INTEGER)');
        $this->db->execute('CREATE TABLE qbbug_widgets (id SERIAL PRIMARY KEY, name VARCHAR(100), qty INTEGER, active BOOLEAN)');
        $this->db->commit();

        $this->db->execute("INSERT INTO qbbug_authors (id, name) VALUES (1, 'Alice')");
        $this->db->execute("INSERT INTO qbbug_posts (id, title, author_id) VALUES (10, 'Hello', 1)");
        $this->db->execute("INSERT INTO qbbug_posts (id, title, author_id) VALUES (11, 'World', 1)");

        $this->db->execute("INSERT INTO qbbug_widgets (name, qty, active) VALUES ('w1', 5, TRUE)");
        $this->db->execute("INSERT INTO qbbug_widgets (name, qty, active) VALUES ('w2', 3, TRUE)");
        $this->db->execute("INSERT INTO qbbug_widgets (name, qty, active) VALUES ('w3', 2, FALSE)");
        $this->db->commit();
    }

    private static function pgReachable(): bool
    {
        $sock = @fsockopen(self::PG_HOST, self::PG_PORT, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    // ── Bug 1 — QueryBuilder first()/count() against the Database facade ─────

    public function testQueryBuilderFirstReturnsRow(): void
    {
        // The facade really hands QueryBuilder a DatabaseResult — the shape
        // that previously made first()/count() misbehave.
        $this->assertInstanceOf(
            DatabaseResult::class,
            $this->db->fetch('SELECT * FROM qbbug_widgets')
        );

        $row = QueryBuilder::fromTable('qbbug_widgets', $this->db)
            ->where('qty >= ?', [3])
            ->orderBy('id ASC')
            ->first();

        $this->assertIsArray($row, 'first() must return the matching row (was null pre-fix)');
        $this->assertSame('w1', $row['name']);
    }

    public function testQueryBuilderFirstReturnsNullWhenNoMatch(): void
    {
        $row = QueryBuilder::fromTable('qbbug_widgets', $this->db)
            ->where('name = ?', ['nope'])
            ->first();

        $this->assertNull($row);
    }

    public function testQueryBuilderCountReturnsCorrectInteger(): void
    {
        $count = QueryBuilder::fromTable('qbbug_widgets', $this->db)
            ->where('qty >= ?', [3])
            ->count();

        $this->assertSame(2, $count, 'count() must return 2 matching widgets (was 0 pre-fix)');
    }

    public function testQueryBuilderExists(): void
    {
        // Note: use an integer predicate, not a bound PHP boolean — binding a
        // PHP false through pg_query_params is a separate, pre-existing adapter
        // quirk unrelated to these four bugs.
        $this->assertTrue(
            QueryBuilder::fromTable('qbbug_widgets', $this->db)->where('qty = ?', [2])->exists()
        );
        $this->assertFalse(
            QueryBuilder::fromTable('qbbug_widgets', $this->db)->where('qty > ?', [999])->exists()
        );
    }

    // ── Bug 2 — groupBy().get() returns every group (regression guard) ───────

    public function testGroupByReturnsAllGroups(): void
    {
        $result = QueryBuilder::fromTable('qbbug_widgets', $this->db)
            ->select('active', 'SUM(qty) as total')
            ->groupBy('active')
            ->get();

        $records = $result instanceof DatabaseResult ? $result->records : ($result['data'] ?? $result);
        $this->assertCount(2, $records, 'grouped query must return both active=true and active=false groups');

        $totals = [];
        foreach ($records as $r) {
            $totals[$r['active'] ? 'true' : 'false'] = (int) $r['total'];
        }
        $this->assertSame(8, $totals['true']);   // 5 + 3
        $this->assertSame(2, $totals['false']);  // 2
    }

    // ── Bug 3 — belongsTo with snake_case FK + autoMap ───────────────────────

    public function testBelongsToLazyMagicProperty(): void
    {
        $post = QbBugPost::findById(10);
        $this->assertNotNull($post);

        // The value lives under the camelCase property + autoMap field mapping.
        $this->assertSame(1, $post->authorId);
        $this->assertSame('author_id', $post->fieldMapping['authorId'] ?? null);

        $author = $post->author;
        $this->assertNotNull($author, 'lazy $post->author must resolve the parent (was null pre-fix)');
        $this->assertSame('Alice', $author->name);
    }

    public function testBelongsToExplicitSnakeCaseColumn(): void
    {
        $post = QbBugPost::findById(10);

        $author = $post->belongsTo(QbBugAuthor::class, 'author_id');
        $this->assertNotNull($author, "belongsTo(\$c, 'author_id') must resolve (was null pre-fix)");
        $this->assertSame('Alice', $author->name);
    }

    public function testBelongsToEagerInclude(): void
    {
        $post = QbBugPost::findById(10, include: ['author']);
        $this->assertNotNull($post);

        $author = $post->author;
        $this->assertNotNull($author, "include:['author'] must populate the parent (was null pre-fix)");
        $this->assertSame('Alice', $author->name);
    }

    public function testHasManyEagerReverseSide(): void
    {
        // Author.find(id, include=['posts']) — the reverse hasMany grouping
        // read children FKs by column name and dropped them all pre-fix.
        $author = QbBugAuthor::findById(1, include: ['posts']);
        $this->assertNotNull($author);

        $posts = $author->posts;
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts, "Author->posts must eager-load both posts (was 0 pre-fix)");
    }

    // ── Bug 4 — fetch() must not double-append LIMIT ─────────────────────────

    public function testFetchHonoursUserLimitClause(): void
    {
        $result = $this->db->fetch('SELECT * FROM qbbug_widgets ORDER BY id DESC LIMIT 1');

        $this->assertNull($this->db->error(), 'fetch() must not produce a double-LIMIT syntax error');
        $this->assertCount(1, $result->records, 'fetch() must honour the user LIMIT 1');
        $this->assertSame('w3', $result->records[0]['name']);
    }

    public function testFetchWithUserLimitAndOffset(): void
    {
        $result = $this->db->fetch('SELECT * FROM qbbug_widgets ORDER BY id ASC LIMIT 1 OFFSET 1');

        $this->assertNull($this->db->error());
        $this->assertCount(1, $result->records);
        $this->assertSame('w2', $result->records[0]['name']);
    }
}
