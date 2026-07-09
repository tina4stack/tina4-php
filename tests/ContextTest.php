<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

// MessageLog / DevAdmin live together in DevAdmin.php and PSR-4 cannot
// autoload the secondary classes individually, so force-include the file for
// the reload-trigger test below.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\Context;
use Tina4\ContextChunker;
use Tina4\DevAdmin;
use Tina4\McpDevTools;
use Tina4\McpServer;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Real, no-mock tests for the native Context code/doc grounding subsystem
 * (PHP parity with tina4-python tests/test_context.py).
 *
 * Every test uses a REAL temp SQLite file and REAL temp source files — there
 * is nothing to mock. They pin the behaviours the port promises:
 *
 *   1. ranking: a definition out-ranks a test that merely mentions the symbol
 *      (definition-first reorder over bm25()), and source-over-tests sinks a
 *      testlike path below non-test source;
 *   2. reindex-on-change: indexPath is an UPSERT — new content found, the
 *      file's old chunks gone, no row duplication;
 *   3. reindexFile against a src/ subdir root + skip outside-root / ineligible
 *      / skip-dir + drop-on-delete;
 *   4. FTS5 availability guard: detected for real, and a Context that finds
 *      FTS5 absent degrades to safe no-ops instead of crashing;
 *   5. result shape + score ordering, doc-as-prose + vendor-dir skipping;
 *   6. the process-wide shared singleton;
 *   7. the dev-MCP code_search tool over a real temp project.
 *
 * Non-overlapping symbol names (quokka/giraffe/wombat/…) are used in ranking
 * and reindex tests so shared FTS tokens can never cross-match into a false
 * pass or failure.
 */
class ContextTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];
    private ?string $savedCwd = null;

    protected function setUp(): void
    {
        Context::clearShared();
        $this->savedCwd = getcwd() ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->savedCwd !== null) {
            @chdir($this->savedCwd);
        }
        Context::clearShared();
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    // ── helpers ─────────────────────────────────────────────────

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4ctx_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function write(string $root, string $rel, string $body): string
    {
        $path = $root . DIRECTORY_SEPARATOR . $rel;
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($path, $body);
        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param array<int, array{path:string, score:float, snippet:string}> $res */
    private function paths(array $res): array
    {
        return array_map(static fn ($r) => $r['path'], $res);
    }

    // ── 1. ranking — definition above a test that mentions the symbol ──

    public function testDefinitionOutranksTestThatMentionsSymbol(): void
    {
        $root = $this->tempDir() . '/app';
        $this->write($root, 'src/inventory.php',
            "<?php\nfunction quokka_stocktake(\$item)\n{\n    // tally current stock for an item\n    return \$item;\n}\n");
        // A test file that MENTIONS quokka_stocktake many times (bm25 loves this)
        // but does NOT define it — it must not out-rank the definition. Its class
        // (InventoryAudit) and method names share no token with the symbol.
        $this->write($root, 'tests/InventoryAudit.php',
            "<?php\nclass InventoryAudit\n{\n    public function auditRun()\n    {\n"
            . "        assert(quokka_stocktake(1));\n        assert(quokka_stocktake(2));\n"
            . "        assert(quokka_stocktake(3));\n        assert(quokka_stocktake(4));\n    }\n}\n");

        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $this->assertGreaterThan(0, $ctx->indexRoot($root));

        $res = $ctx->search('quokka_stocktake', 5);
        $paths = $this->paths($res);

        // both files surface
        $this->assertTrue(
            (bool) array_filter($paths, static fn ($p) => str_ends_with($p, 'inventory.php') && !str_contains($p, 'test')),
            'definition file should surface: ' . json_encode($paths)
        );
        $this->assertTrue(
            (bool) array_filter($paths, static fn ($p) => str_contains($p, 'InventoryAudit')),
            'test file should surface: ' . json_encode($paths)
        );

        // the definition (non-test source) ranks ABOVE the test file
        $srcIdx = $this->firstIndex($res, static fn ($p) => str_ends_with($p, 'inventory.php') && !str_contains($p, 'test'));
        $testIdx = $this->firstIndex($res, static fn ($p) => str_contains($p, 'InventoryAudit'));
        $this->assertNotNull($srcIdx);
        $this->assertNotNull($testIdx);
        $this->assertLessThan($testIdx, $srcIdx, 'definition should out-rank the test: ' . json_encode($paths));

        // and the very top hit is the definition, not a test file
        $this->assertStringNotContainsString('test', $res[0]['path'], json_encode($paths));
        $ctx->close();
    }

    /** Source-over-tests: a testlike path sinks below non-test source that
     *  matches equally well (neither defines the queried symbol). */
    public function testSourceOverTestsSinksTestlikePath(): void
    {
        $root = $this->tempDir() . '/app';
        // A doc (non-test) and an example file (testlike path) that BOTH merely
        // mention the symbol — neither defines it. is_test is the only
        // differentiator, so the doc must rank above the example.
        $this->write($root, 'src/guide.md',
            "# Overview\n\nThe flibbertigibbet subsystem coordinates the pipeline across nodes.\n");
        $this->write($root, 'example/Sample.php',
            "<?php\n// demonstrates flibbertigibbet usage in a sample\n\$out = flibbertigibbet_helper();\n");

        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($root);

        $res = $ctx->search('flibbertigibbet', 5);
        $paths = $this->paths($res);
        $docIdx = $this->firstIndex($res, static fn ($p) => str_ends_with($p, 'guide.md'));
        $exampleIdx = $this->firstIndex($res, static fn ($p) => str_contains($p, 'example/'));
        $this->assertNotNull($docIdx, 'doc should surface: ' . json_encode($paths));
        $this->assertNotNull($exampleIdx, 'example should surface: ' . json_encode($paths));
        $this->assertLessThan($exampleIdx, $docIdx, 'non-test source should out-rank the testlike example: ' . json_encode($paths));
        $ctx->close();
    }

    // ── 2. reindex-on-change — UPSERT: new found, old gone, no duplication ──

    public function testReindexOnChangeIsUpsert(): void
    {
        $root = $this->tempDir() . '/app';
        $file = $this->write($root, 'src/mod.php', "<?php\nfunction wombat()\n{\n    return 1;\n}\n");

        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexPath($file, 'src/mod.php');

        $this->assertContains('src/mod.php', $this->paths($ctx->search('wombat')), 'wombat should be found first');
        $rowsBefore = $ctx->count();

        // edit the file: wombat -> giraffe, then re-index just this file
        file_put_contents($file, "<?php\nfunction giraffe()\n{\n    return 2;\n}\n");
        $ctx->indexPath($file, 'src/mod.php');

        // new content retrievable
        $this->assertContains('src/mod.php', $this->paths($ctx->search('giraffe')), 'giraffe should be found after reindex');
        // OLD content for this file is gone (upsert deleted the stale chunks)
        $this->assertNotContains('src/mod.php', $this->paths($ctx->search('wombat')), 'wombat chunks must be gone');
        // no duplication — same file, same chunk count => same total rows
        $this->assertSame($rowsBefore, $ctx->count(), 'UPSERT must delete-then-insert, not append');
        $ctx->close();
    }

    public function testIndexPathSecondCallReplacesNotAppends(): void
    {
        $root = $this->tempDir() . '/app';
        $file = $this->write($root, 'src/dup.php', "<?php\nfunction only_one()\n{\n    return 42;\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexPath($file, 'src/dup.php');
        $n1 = $ctx->count();
        $ctx->indexPath($file, 'src/dup.php');
        $this->assertSame($n1, $ctx->count(), 're-indexing an unchanged file must not append duplicates');
        $ctx->close();
    }

    // ── 3. FTS5 availability guard ──────────────────────────────

    public function testFts5AvailableOnThisBuild(): void
    {
        // real detector — modern PDO SQLite ships FTS5, so it must report true
        $this->assertTrue(Context::fts5Available());
    }

    public function testContextDegradesGracefullyWithoutFts5(): void
    {
        $root = $this->tempDir() . '/app';
        $this->write($root, 'src/x.php', "<?php\nfunction y()\n{\n    return 1;\n}\n");

        // inject a predicate that reports FTS5 as ABSENT — exercises the real
        // graceful-degradation branch (no mocking of PDO or files).
        $ctx = new Context($this->tempDir() . '/.tina4/context.db', static fn () => false);
        $this->assertFalse($ctx->available);
        $this->assertSame(0, $ctx->indexRoot($root));
        $this->assertSame(0, $ctx->indexPath($root . '/src/x.php', 'src/x.php'));
        $this->assertSame([], $ctx->search('anything'));
        $ctx->close();

        // a normal Context on this build IS available
        $ok = new Context($this->tempDir() . '/.tina4/ok.db');
        $this->assertTrue($ok->available);
        $ok->close();
    }

    // ── 4. result shape, score ordering, doc chunking, dir skipping ──

    public function testSearchResultShapeAndScoreOrdering(): void
    {
        $root = $this->tempDir() . '/app';
        $this->write($root, 'src/Widget.php',
            "<?php\nfunction build_widget(\$spec)\n{\n    return new Widget(\$spec);\n}\n\nclass Widget\n{\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($root);

        $res = $ctx->search('build_widget', 5);
        $this->assertNotEmpty($res, 'expected at least one hit');
        foreach ($res as $r) {
            $this->assertArrayHasKey('path', $r);
            $this->assertArrayHasKey('score', $r);
            $this->assertArrayHasKey('snippet', $r);
            $this->assertIsFloat($r['score']);
            $this->assertIsString($r['snippet']);
            $this->assertNotSame('', $r['snippet']);
        }
        // higher score = better; results are sorted descending
        $scores = array_map(static fn ($r) => $r['score'], $res);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, json_encode($scores));
        $ctx->close();
    }

    public function testIndexesDocsAsProseAndSkipsVendorDirs(): void
    {
        $root = $this->tempDir() . '/app';
        $this->write($root, 'docs/guide.md',
            "# Overview\n\nThe whirligig subsystem manages the flux capacitor and its temporal bindings.\n");
        // these live under dirs the walk skips — they must NOT be indexed
        $this->write($root, '__pycache__/junk.py', "def whirligig_flux_capacitor(): pass\n");
        $this->write($root, 'node_modules/lib.js', "function whirligigFluxCapacitor(){}\n");
        $this->write($root, 'vendor/pkg/Thing.php', "<?php\nfunction whirligig_flux_capacitor(){}\n");

        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($root);

        $paths = $this->paths($ctx->search('whirligig flux capacitor', 5));
        $this->assertTrue((bool) array_filter($paths, static fn ($p) => str_ends_with($p, 'guide.md')), json_encode($paths));
        $this->assertEmpty(array_filter($paths, static fn ($p) => str_contains($p, '__pycache__')), json_encode($paths));
        $this->assertEmpty(array_filter($paths, static fn ($p) => str_contains($p, 'node_modules')), json_encode($paths));
        $this->assertEmpty(array_filter($paths, static fn ($p) => str_contains($p, 'vendor')), json_encode($paths));
        $ctx->close();
    }

    public function testEmptyOrWhitespaceQueryReturnsEmpty(): void
    {
        $root = $this->tempDir() . '/app';
        $this->write($root, 'src/a.php', "<?php\nfunction a()\n{\n    return 1;\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($root);
        $this->assertSame([], $ctx->search(''));
        $this->assertSame([], $ctx->search('   '));
        $ctx->close();
    }

    // ── 5. reindexFile — UPSERT against the index root ──────────

    public function testReindexFileUpsertsAgainstASubdirRoot(): void
    {
        // The reload trigger reports project-root-relative paths ('src/mod.php'),
        // but the index root may be a subdir (src/); reindexFile must relabel and
        // upsert correctly.
        $tmp = $this->tempDir();
        $this->write($tmp, 'src/mod.php', "<?php\nfunction wombat()\n{\n    return 1;\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($tmp . '/src');                 // root = src/, label = "mod.php"
        $this->assertContains('mod.php', $this->paths($ctx->search('wombat')));

        file_put_contents($tmp . '/src/mod.php', "<?php\nfunction giraffe()\n{\n    return 2;\n}\n");
        chdir($tmp);
        $this->assertGreaterThanOrEqual(1, $ctx->reindexFile('src/mod.php'));   // project-root-relative

        $this->assertContains('mod.php', $this->paths($ctx->search('giraffe')), 'new content found');
        $this->assertNotContains('mod.php', $this->paths($ctx->search('wombat')), 'old content gone');
        $ctx->close();
    }

    public function testReindexFileSkipsOutsideRootIneligibleAndSkipdirs(): void
    {
        $tmp = $this->tempDir();
        $this->write($tmp, 'src/a.php', "<?php\nfunction aardvark()\n{\n    return 1;\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($tmp . '/src');
        chdir($tmp);

        $this->write($tmp, 'README.md', "# top-level, outside src/\n");
        $this->assertSame(-1, $ctx->reindexFile('README.md'));            // outside the index root

        $this->write($tmp, 'src/data.bin', 'x');
        $this->assertSame(-1, $ctx->reindexFile('src/data.bin'));         // ineligible extension

        $this->write($tmp, 'src/__pycache__/j.php', "<?php\nfunction j(){}\n");
        $this->assertSame(-1, $ctx->reindexFile('src/__pycache__/j.php')); // skip-dir
        $ctx->close();
    }

    public function testReindexFileDropsADeletedFile(): void
    {
        $tmp = $this->tempDir();
        $file = $this->write($tmp, 'src/gone.php', "<?php\nfunction unique_gone_sym()\n{\n    return 1;\n}\n");
        $ctx = new Context($this->tempDir() . '/.tina4/context.db');
        $ctx->indexRoot($tmp . '/src');
        $this->assertContains('gone.php', $this->paths($ctx->search('unique_gone_sym')));

        unlink($file);
        chdir($tmp);
        $this->assertSame(0, $ctx->reindexFile('src/gone.php'));           // delete → drop rows

        $this->assertSame([], $ctx->search('unique_gone_sym'), "deleted file's chunks must be gone");
        $ctx->close();
    }

    // ── 6. process-wide shared singleton ────────────────────────

    public function testDefaultContextIsASharedSingleton(): void
    {
        Context::clearShared();
        $tmp = $this->tempDir();
        $db = $tmp . '/.tina4/ctx.db';
        $this->write($tmp, 'src/x.php', "<?php\nfunction findme_sym()\n{\n    return 1;\n}\n");

        $a = Context::defaultContext($tmp . '/src', $db);
        $this->assertSame($a, Context::defaultContext(null, $db), 'same db path → same instance');
        $this->assertSame($a, Context::existingContext($db));
        $this->assertNull(Context::existingContext($tmp . '/nope.db'));
        $this->assertTrue((bool) array_filter($this->paths($a->search('findme_sym')), static fn ($p) => str_contains($p, 'x.php')));

        $a->close();
        Context::clearShared();
    }

    // ── 7. the dev-MCP code_search tool over a real temp project ──

    public function testCodeSearchToolRegisteredAndFindsSymbol(): void
    {
        $tmp = $this->tempDir();
        mkdir($tmp . '/src', 0777, true);
        file_put_contents($tmp . '/src/service.php',
            "<?php\nfunction widget_factory(\$name)\n{\n    // create a configured widget\n    return ['name' => \$name];\n}\n");

        chdir($tmp);
        $server = new McpServer('/test-code-search', 'code_search test');
        McpDevTools::register($server);

        // tool is registered (sibling of api_search)
        $listed = json_decode($server->handleMessage([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ]), true);
        $names = array_map(static fn ($t) => $t['name'], $listed['result']['tools']);
        $this->assertContains('code_search', $names, json_encode($names));

        $resp = json_decode($server->handleMessage([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'code_search', 'arguments' => ['query' => 'widget_factory']],
        ]), true);
        $this->assertArrayHasKey('result', $resp, json_encode($resp));
        $payload = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertIsArray($payload, json_encode($resp));
        $paths = array_map(static fn ($r) => $r['path'], $payload);
        $this->assertTrue((bool) array_filter($paths, static fn ($p) => str_ends_with($p, 'service.php')), json_encode($payload));
    }

    /** @param callable(string):bool $pred */
    private function firstIndex(array $res, callable $pred): ?int
    {
        foreach ($res as $i => $r) {
            if ($pred($r['path'])) {
                return $i;
            }
        }
        return null;
    }
}
