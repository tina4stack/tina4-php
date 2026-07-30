<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;
use Tina4\Database\SQLite3Adapter;

/**
 * Locks in SQLite absolute-path parity for DATABASE_URL parsing.
 *
 * The footgun this guards against: a ONE-slash absolute URL such as
 *   sqlite:/private/var/folders/xy/app.db
 * used to be collapsed by parse_url() into a cwd-relative "private/var/.../app.db",
 * so the DB was silently created in a shadow location under the project root
 * instead of the absolute path the caller asked for.
 *
 * These tests use REAL SQLite files (no mocks): they parse the URL with
 * \Tina4\DatabaseUrl, open a live \Tina4\Database\SQLite3Adapter on the parsed
 * path, write and read rows, and assert against the actual filesystem.
 */
class SqliteAbsolutePathParityTest extends TestCase
{
    /** @var string[] Absolute DB files created during a test, removed in tearDown. */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        $this->createdPaths = [];
    }

    /**
     * THE FIX: sqlite: + a single-leading-slash absolute temp path must open the
     * DB at that ABSOLUTE path and must NOT create a cwd-relative shadow.
     */
    public function testSqliteSingleSlashAbsolutePathOpensAtAbsoluteLocation(): void
    {
        // A real, existing temp directory. sys_get_temp_dir() is absolute with a
        // single leading slash (e.g. /var/folders/xx/.../T on macOS) — exactly the
        // footgun shape once prefixed with "sqlite:".
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $this->assertStringStartsWith('/', $tmpDir, 'temp dir must be an absolute path');
        $this->assertDirectoryExists($tmpDir);

        $absPath = $tmpDir . '/tina4_abs_' . uniqid('', true) . '.db';
        $this->createdPaths[] = $absPath;

        $url = 'sqlite:' . $absPath; // ONE leading slash after the scheme
        $this->assertStringStartsWith('sqlite:/', $url);
        $this->assertStringStartsNotWith('sqlite://', $url);

        // Parse: the scheme is stripped on the raw string, the single leading
        // slash is preserved, so the path stays ABSOLUTE.
        $parsed = new DatabaseUrl($url);
        $this->assertSame('sqlite', $parsed->engine);
        $this->assertSame($absPath, $parsed->database, 'single-slash absolute path preserved verbatim');
        $this->assertSame($absPath, $parsed->getDsn());

        // The cwd-relative location the OLD (buggy) collapse would have used.
        $shadowPath = getcwd() . DIRECTORY_SEPARATOR . ltrim($absPath, '/');

        // Real connect + write on the parsed absolute path.
        $db = new SQLite3Adapter($parsed->database);
        $this->assertSame($absPath, $db->getDatabase(), 'adapter opened the absolute path unchanged');
        $this->assertTrue($db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)'));
        $this->assertTrue($db->exec("INSERT INTO items (label) VALUES ('parity')"));
        $db->close();

        // The file exists at the ABSOLUTE path...
        $this->assertFileExists($absPath, 'DB was created at the absolute path');
        // ...and NO cwd-relative shadow was created.
        $this->assertFileDoesNotExist($shadowPath, 'no cwd-relative shadow DB was created');
        // Defensive: the buggy path would also mkdir the first path segment under cwd.
        $firstSegment = getcwd() . DIRECTORY_SEPARATOR . explode('/', ltrim($absPath, '/'))[0];
        $this->assertDirectoryDoesNotExist($firstSegment, 'no cwd-relative shadow directory tree was created');

        // Reopen the same absolute path and read the row back — data persisted.
        $reopen = new SQLite3Adapter((new DatabaseUrl($url))->database);
        $this->assertSame($absPath, $reopen->getDatabase());
        $rows = $reopen->query('SELECT label FROM items ORDER BY id');
        $this->assertCount(1, $rows);
        $this->assertSame('parity', $rows[0]['label']);
        $reopen->close();
    }

    /**
     * NO REGRESSION: sqlite:///<path> (three slashes) stays RELATIVE to cwd.
     * The parsed path has no leading slash.
     */
    public function testThreeSlashFormStaysRelative(): void
    {
        $db = new DatabaseUrl('sqlite:///data/app.db');
        $this->assertSame('data/app.db', $db->database, 'three slashes = relative to cwd');
        $this->assertSame('data/app.db', $db->getDsn());
        $this->assertStringStartsNotWith('/', $db->getDsn(), 'relative form has no leading slash');
    }

    /**
     * NO REGRESSION: sqlite:////<path> (four slashes) is ABSOLUTE.
     * Verified with a real connect + round-trip on a real temp file.
     */
    public function testFourSlashFormIsAbsoluteAndRoundTrips(): void
    {
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $absPath = $tmpDir . '/tina4_fourslash_' . uniqid('', true) . '.db';
        $this->createdPaths[] = $absPath;

        // "sqlite:///" (3 slashes) + an absolute path (leading /) = 4 slashes total.
        $url = 'sqlite:///' . $absPath;
        $this->assertStringStartsWith('sqlite:////', $url);

        $parsed = new DatabaseUrl($url);
        $this->assertSame($absPath, $parsed->database, 'four slashes = absolute path');
        $this->assertSame($absPath, $parsed->getDsn());
        $this->assertStringStartsWith('/', $parsed->getDsn(), 'absolute form keeps its leading slash');

        $db = new SQLite3Adapter($parsed->database);
        $this->assertSame($absPath, $db->getDatabase());
        $this->assertTrue($db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)'));
        $this->assertTrue($db->exec("INSERT INTO t (v) VALUES ('ok')"));
        $db->close();

        $this->assertFileExists($absPath);

        $reopen = new SQLite3Adapter((new DatabaseUrl($url))->database);
        $rows = $reopen->query('SELECT v FROM t');
        $this->assertSame('ok', $rows[0]['v'] ?? null);
        $reopen->close();
    }

    /**
     * NO REGRESSION: sqlite::memory: parses to :memory: and opens a live in-memory DB.
     */
    public function testMemoryFormParsesAndConnects(): void
    {
        $parsed = new DatabaseUrl('sqlite::memory:');
        $this->assertSame(':memory:', $parsed->database);
        $this->assertSame(':memory:', $parsed->getDsn());

        $db = new SQLite3Adapter($parsed->database);
        $this->assertSame(':memory:', $db->getDatabase());
        $this->assertTrue($db->exec('CREATE TABLE m (id INTEGER PRIMARY KEY, v TEXT)'));
        $this->assertTrue($db->exec("INSERT INTO m (v) VALUES ('mem')"));
        $rows = $db->query('SELECT v FROM m');
        $this->assertSame('mem', $rows[0]['v'] ?? null);
        $db->close();
    }

    /**
     * THE REAL USER PATH: new \Tina4\Database\Database($url) routes through
     * createAdapter(), a SEPARATE sqlite parser from DatabaseUrl. It must resolve the
     * one-slash absolute form to the absolute file (footgun), AND keep sqlite:///<rel>
     * relative — which used to THROW "unable to open database file" because createAdapter
     * mis-counted "sqlite:///" and treated three slashes as an absolute "/data" path.
     */
    public function testRealDatabaseConstructorResolvesSqlitePaths(): void
    {
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $absPath = $tmpDir . '/tina4_real_' . uniqid('', true) . '.db';
        $this->createdPaths[] = $absPath;

        // naive one-slash absolute, through the REAL Database constructor
        $db = new \Tina4\Database\Database('sqlite:' . $absPath);
        $db->execute('CREATE TABLE r (id INTEGER PRIMARY KEY, v TEXT)');
        $db->execute("INSERT INTO r (v) VALUES ('real')");
        $this->assertFileExists($absPath, 'real Database() created the DB at the absolute path');
        $firstSegment = getcwd() . DIRECTORY_SEPARATOR . explode('/', ltrim($absPath, '/'))[0];
        $this->assertDirectoryDoesNotExist($firstSegment, 'no cwd-relative shadow from the real Database() path');

        // documented relative form must CONNECT (this threw before the createAdapter fix)
        $relRel = 'data/tina4_real_rel_' . uniqid('', true) . '.db';
        $this->createdPaths[] = getcwd() . '/' . $relRel;
        $rdb = new \Tina4\Database\Database('sqlite:///' . $relRel);
        $rdb->execute('CREATE TABLE r (id INTEGER PRIMARY KEY)');
        $this->assertFileExists(getcwd() . '/' . $relRel, 'sqlite:///<rel> resolves relative to cwd (no longer throws)');
    }
}
