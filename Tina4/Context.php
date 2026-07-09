<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Context — a native, zero-dependency code/doc grounding index.
 *
 * Lets a Tina4 app ground its own AI assistant on its own source, offline:
 * it walks the project, chunks code on def/class/function boundaries and docs
 * as prose, and answers keyword/fuzzy queries over a SQLite FTS5 index
 * (PDO ``pdo_sqlite`` — FTS5 + ``bm25()`` are built into modern SQLite, so NO
 * new composer dependency).
 *
 * A faithful PHP port of tina4-python's tina4_python/context (the proven slice
 * of neemee's longmem-harness retrieval). It COMPLEMENTS the ``api_*``
 * reflection tools (Docs): ``api_*`` is exact structural lookup, Context is
 * fuzzy/semantic FTS over source + docs.
 *
 *     use Tina4\Context;
 *     $ctx = new Context(".tina4/context.db");
 *     $ctx->indexRoot("src");
 *     $ctx->search("where is the auth token issued?", 5);
 *     //  -> [["path" => "src/Auth.php", "score" => 2.31, "snippet" => "..."], ...]
 *
 * On-disk index defaults to ``.tina4/context.db`` (gitignored). Guards a
 * SQLite build without FTS5: if absent, the Context degrades to safe no-ops
 * rather than crashing the app — exactly like the Python master.
 */

namespace Tina4;

class Context
{
    // File classification — mirrors neemee's repo walk (extensions carry the dot).
    private const CODE_EXTS = [
        '.py', '.php', '.js', '.mjs', '.ts', '.rb',
        '.pas', '.dpr', '.dpk', '.inc', '.dfm', '.fmx',
    ];
    private const DOC_EXTS = ['.md', '.txt', '.rst', '.twig', '.html'];
    // deploy/CLI/env answers live in config files, not sources — chunk as code
    // (line windows), since sentence chunking shreds YAML/Dockerfiles.
    private const CONFIG_EXTS = ['.toml', '.yml', '.yaml'];
    private const SPECIAL_FILES = [
        'dockerfile', 'makefile', 'docker-compose.yml',
        'package.json', 'composer.json',
        '.env.example', '.env.sample',
    ];
    // Same dirs neemee skips, plus Tina4 runtime dirs that hold no source of
    // truth (our own index/backups, session blobs, logs).
    private const SKIP_DIRS = [
        '.git', '__pycache__', 'node_modules', 'vendor', 'dist',
        'build', 'coverage', '.idea', '.venv', 'venv', '.pytest_cache',
        '.tina4', 'sessions', 'logs',
    ];

    // generic question/code vocabulary that never NAMES a symbol — kept small.
    private const DEF_STOP = [
        'the', 'and', 'what', 'how', 'does', 'can', 'which', 'where', 'when',
        'who', 'why', 'list', 'all', 'available', 'module', 'class', 'function',
        'functions', 'method', 'methods', 'def', 'get', 'set', 'new', 'return',
        'import', 'from', 'with', 'that', 'this', 'are', 'is', 'was', 'for',
        'into', 'use', 'used',
    ];

    // a chunk that DEFINES a queried symbol ('function getToken', 'class Widget')
    // should out-rank one that merely USES it. Matched against the folded body.
    private const DEF_KW = '(?:async def|def|class|function|fn|func|interface|trait)';

    public string $path;
    public bool $available = false;
    /** Set by indexRoot; reindexFile relabels a changed file against it. */
    private ?string $root = null;
    private ?\PDO $conn = null;

    /**
     * @param string        $path      on-disk index file (its parent dir is created)
     * @param callable|null $fts5Check overrides FTS5 detection (tests exercise the
     *                                 graceful-degradation path); defaults to a real probe.
     */
    public function __construct(string $path = './.tina4/context.db', ?callable $fts5Check = null)
    {
        $this->path = $path;
        $check = $fts5Check ?? [self::class, 'probeFts5'];
        $this->available = (bool) $check();
        if (!$this->available) {
            return;
        }
        $parent = dirname($path);
        if ($parent !== '' && $parent !== '.' && !is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        $this->conn = new \PDO('sqlite:' . $path);
        $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // tina4: no per-connection lock. PHP's request model is synchronous and
        // share-nothing (unlike the Python master, where a dev-MCP worker thread
        // shares one connection and needs a threading.Lock). Add one only if a
        // future async SAPI shares a Context across concurrent handlers.
        $this->ensureTable();
    }

    // ── FTS5 availability ───────────────────────────────────────

    /** Whether this PHP's PDO SQLite supports FTS5 (real probe). */
    public static function fts5Available(): bool
    {
        return self::probeFts5();
    }

    /** True if an in-memory SQLite can create an FTS5 virtual table. */
    public static function probeFts5(): bool
    {
        try {
            $db = new \PDO('sqlite::memory:');
            $db->exec('CREATE VIRTUAL TABLE _probe USING fts5(x)');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── schema ──────────────────────────────────────────────────

    private function ensureTable(): void
    {
        // `body` holds fold(text) so query/index tokenization is symmetric;
        // `raw` (UNINDEXED) keeps the original text to return as a snippet;
        // `path`/`cid` (UNINDEXED) are metadata used for upsert + citation.
        $this->conn->exec(
            'CREATE VIRTUAL TABLE IF NOT EXISTS chunks '
            . 'USING fts5(cid UNINDEXED, path UNINDEXED, raw UNINDEXED, body)'
        );
    }

    /** Drop and recreate the index (full rebuild starting point). */
    public function reset(): void
    {
        if (!$this->available) {
            return;
        }
        $this->conn->exec('DROP TABLE IF EXISTS chunks');
        $this->ensureTable();
    }

    // ── indexing ────────────────────────────────────────────────

    /**
     * (index, chunkText) pairs — code/config files on def/class/function
     * boundaries, everything else (docs) as prose.
     *
     * @return array<int, array{0:int, 1:string}>
     */
    private function chunksFor(string $label, string $text): array
    {
        $ext = self::extensionOf($label);
        $special = in_array(strtolower(basename($label)), self::SPECIAL_FILES, true);
        if (in_array($ext, self::CODE_EXTS, true)
            || in_array($ext, self::CONFIG_EXTS, true)
            || $special) {
            return ContextChunker::chunkCode($text, $label);
        }
        return ContextChunker::chunkText($text);
    }

    /**
     * UPSERT one file into the index: delete this path's existing chunks,
     * re-chunk the current contents, insert. A single file save re-indexes
     * just that file. `$label` is the stored/citation path (defaults to
     * `$file`) and MUST be stable across calls for the same file so the delete
     * targets the right rows. Returns rows inserted.
     */
    public function indexPath(string $file, ?string $label = null): int
    {
        if (!$this->available) {
            return 0;
        }
        $stored = $label !== null ? $label : $file;
        $text = @file_get_contents($file);
        if ($text === false) {
            return 0;
        }
        $rows = [];
        foreach ($this->chunksFor($stored, $text) as [$i, $chunk]) {
            $rows[] = ["{$stored}:{$i}", $stored, $chunk, ContextChunker::fold($chunk)];
        }
        $this->conn->beginTransaction();
        try {
            $this->conn->prepare('DELETE FROM chunks WHERE path = ?')->execute([$stored]);
            if (!empty($rows)) {
                $insert = $this->conn->prepare(
                    'INSERT INTO chunks(cid, path, raw, body) VALUES (?, ?, ?, ?)'
                );
                foreach ($rows as $row) {
                    $insert->execute($row);
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
        return count($rows);
    }

    /**
     * True if a file should be indexed (the per-file filter used by both
     * indexRoot and reindexFile). Directory skipping is handled separately.
     */
    private static function eligible(string $filename): bool
    {
        $fn = strtolower($filename);
        if (str_ends_with($fn, '.min.js')) {
            return false;
        }
        $ext = self::extensionOf($fn);
        return in_array($ext, self::CODE_EXTS, true)
            || in_array($ext, self::DOC_EXTS, true)
            || in_array($ext, self::CONFIG_EXTS, true)
            || in_array($fn, self::SPECIAL_FILES, true);
    }

    /**
     * Walk `$root`, indexing every eligible file (skips vendor/build/runtime
     * dirs). Paths are stored RELATIVE to `$root` for clean citations. Records
     * `$root` so reindexFile can relabel a changed file consistently. Returns
     * the total number of chunks inserted.
     */
    public function indexRoot(string $root): int
    {
        if (!$this->available) {
            return 0;
        }
        $resolved = realpath($root);
        if ($resolved === false) {
            return 0;
        }
        $this->root = $resolved;
        return $this->walk($resolved, $resolved);
    }

    /** Recursive tree walk with skip-dir + dot-dir pruning. */
    private function walk(string $dir, string $root): int
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }
        sort($entries);
        $dirs = [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                if (in_array($entry, self::SKIP_DIRS, true) || str_starts_with($entry, '.')) {
                    continue;
                }
                $dirs[] = $entry;
            } else {
                $files[] = $entry;
            }
        }
        $total = 0;
        foreach ($files as $fn) {
            if (!self::eligible($fn)) {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $fn;
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($full, strlen($root)), DIRECTORY_SEPARATOR));
            $total += $this->indexPath($full, $rel);
        }
        foreach ($dirs as $d) {
            $total += $this->walk($dir . DIRECTORY_SEPARATOR . $d, $root);
        }
        return $total;
    }

    /**
     * Re-index a single changed file into the LIVE index — the hook the dev
     * reload trigger (POST /__dev/api/reload) calls so code_search tracks edits
     * without a rebuild. Resolves `$changedPath` against the indexed root, then:
     * outside root / under a skip-or-dot dir / ineligible → skip (-1);
     * deleted → drop its chunks (0); otherwise UPSERT (rows). No-op (-1) until
     * indexRoot has run (nothing to keep fresh yet).
     */
    public function reindexFile(string $changedPath): int
    {
        if (!$this->available || $this->root === null) {
            return -1;
        }
        // The reload trigger reports paths relative to the project root (cwd
        // during `tina4 serve`); the index root may be a subdir like src/.
        $path = $changedPath;
        if (!self::isAbsolutePath($path)) {
            $cwd = getcwd();
            $path = ($cwd !== false ? $cwd : '.') . DIRECTORY_SEPARATOR . $changedPath;
        }
        // Resolve symlinks on the parent dir (handles /tmp -> /private/tmp on
        // macOS) even when the file itself was deleted — the parent still
        // exists. realpath() of the whole path would return false for a
        // deleted file, so we can't use it directly.
        $realDir = realpath(dirname($path));
        if ($realDir === false) {
            return -1;                               // parent gone → outside/invalid
        }
        $abs = $realDir . DIRECTORY_SEPARATOR . basename($path);

        $rootPrefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($abs . DIRECTORY_SEPARATOR, $rootPrefix)) {
            return -1;                               // outside the indexed root
        }
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($rootPrefix)));
        $parts = explode('/', $rel);
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_DIRS, true)) {
                return -1;                           // under a skipped dir
            }
        }
        foreach (array_slice($parts, 0, -1) as $dirPart) {
            if (str_starts_with($dirPart, '.')) {
                return -1;                           // under a dot dir
            }
        }
        if (!self::eligible(basename($rel))) {
            return -1;
        }
        if (!file_exists($abs)) {                     // deleted → drop its chunks
            $this->conn->prepare('DELETE FROM chunks WHERE path = ?')->execute([$rel]);
            return 0;
        }
        return $this->indexPath($abs, $rel);
    }

    // ── query ───────────────────────────────────────────────────

    /**
     * Build the FTS5 MATCH string: each folded query token (plus its light
     * stem) as a quoted OR term. Quoting keeps it injection-safe (tokens are
     * `[a-z0-9]+`, so no quote can appear inside one). Returns null when the
     * query has no usable tokens.
     */
    private function matchExpr(string $query): ?string
    {
        $toks = [];
        foreach (ContextChunker::terms($query) as $t) {
            $toks[$t] = true;
            $toks[ContextChunker::lightStem($t)] = true;
        }
        unset($toks['']);
        if (empty($toks)) {
            return null;
        }
        $keys = array_keys($toks);
        sort($keys);
        $quoted = array_map(static fn ($t) => '"' . $t . '"', $keys);
        return implode(' OR ', $quoted);
    }

    private static function isTestlike(?string $path): bool
    {
        $s = strtolower($path ?? '');
        foreach (['/test', 'test/', 'test_', '_test.', '.test.', '/example', 'example/'] as $needle) {
            if (str_contains($s, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the folded body DEFINES any of `$symbols` (a def keyword directly
     * followed by the symbol, not merely a use of it).
     *
     * @param string[] $symbols
     */
    private function defines(string $foldedBody, array $symbols): bool
    {
        if (empty($symbols)) {
            return false;
        }
        $escaped = array_map(static fn ($s) => preg_quote($s, '/'), $symbols);
        $pattern = '/' . self::DEF_KW . '\s+(?:' . implode('|', $escaped) . ')(?![a-z0-9])/';
        return preg_match($pattern, $foldedBody) === 1;
    }

    /**
     * Return the top-`$k` chunks as `[["path"=>, "score"=>, "snippet"=>], ...]`,
     * ranked by `bm25()` then reordered with two stable, proven passes:
     *   - source-over-tests: a test that merely mentions a symbol sinks below
     *     the source that defines it (skipped when the query is about tests);
     *   - definition-first: a chunk that DEFINES a queried symbol rises above
     *     chunks that only use it.
     * Score is a higher-is-better float (SQLite's bm25 sign flipped).
     *
     * @return array<int, array{path:string, score:float, snippet:string}>
     */
    public function search(string $query, int $k = 5): array
    {
        if (!$this->available) {
            return [];
        }
        $expr = $this->matchExpr($query);
        if ($expr === null) {
            return [];
        }
        $poolN = max($k * 3, 15);
        $stmt = $this->conn->prepare(
            'SELECT path, raw, body, bm25(chunks) AS s FROM chunks '
            . 'WHERE chunks MATCH ? ORDER BY s LIMIT ?'
        );
        $stmt->bindValue(1, $expr, \PDO::PARAM_STR);
        $stmt->bindValue(2, $poolN, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
        if (empty($rows)) {
            return [];
        }

        // candidate symbol names from the query (drop generic vocab).
        $symbols = [];
        foreach (ContextChunker::terms($query) as $t) {
            if (strlen($t) >= 3 && !in_array($t, self::DEF_STOP, true)) {
                $symbols[$t] = true;
            }
        }
        $symbols = array_keys($symbols);
        $aboutTests = str_contains(strtolower($query), 'test');

        // Reorder by (is_test, is_def, bm25_index) — bm25 order breaks ties.
        $ranked = [];
        foreach ($rows as $idx => $row) {
            [$path, $raw, $body, $s] = $row;
            $isTest = (!$aboutTests && self::isTestlike($path)) ? 1 : 0;
            $isDef = $this->defines($body, $symbols) ? 0 : 1;
            $ranked[] = ['key' => [$isTest, $isDef, $idx], 'row' => $row];
        }
        usort($ranked, static fn ($a, $b) => $a['key'] <=> $b['key']);

        $out = [];
        foreach (array_slice($ranked, 0, $k) as $item) {
            [$path, $raw, $body, $s] = $item['row'];
            $out[] = [
                'path' => $path,
                'score' => round(-(float) $s, 6),    // bm25: more negative = better
                'snippet' => self::snippet($raw),
            ];
        }
        return $out;
    }

    private static function snippet(?string $raw, int $limit = 280): string
    {
        $text = trim($raw ?? '');
        if (strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(substr($text, 0, $limit)) . ' ...';
    }

    // ── misc ────────────────────────────────────────────────────

    public function count(): int
    {
        if (!$this->available) {
            return 0;
        }
        return (int) $this->conn->query('SELECT count(*) FROM chunks')->fetchColumn();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function close(): void
    {
        $this->conn = null;
    }

    // ── helpers ─────────────────────────────────────────────────

    /** Extension INCLUDING the leading dot ('.php'), or '' when there is none. */
    private static function extensionOf(string $name): string
    {
        $base = basename($name);
        $dot = strrpos($base, '.');
        // A leading-dot-only name ('.env') has no extension in the os.path sense.
        if ($dot === false || $dot === 0) {
            return '';
        }
        return substr($base, $dot);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    // ── process-wide shared index ───────────────────────────────
    // code_search (dev MCP) and the dev-reload reindex hook must share ONE
    // index so a saved file is immediately searchable. Keyed by canonical db
    // path. Mirrors the Python master's default_context / existing_context.

    /** @var array<string, Context> */
    private static array $sharedContexts = [];

    /**
     * Get (or create) the process-wide Context at `$db` (default
     * `<cwd>/.tina4/context.db`). If `$root` is given and the index is empty,
     * builds it once. This is what code_search uses so the reload hook can keep
     * the SAME index fresh.
     */
    public static function defaultContext(?string $root = null, ?string $db = null): self
    {
        $target = $db ?? self::defaultDbPath();
        $key = self::dbKey($target);
        if (!isset(self::$sharedContexts[$key])) {
            self::$sharedContexts[$key] = new self($target);
        }
        $ctx = self::$sharedContexts[$key];
        if ($root !== null && $ctx->available && $ctx->isEmpty()) {
            $ctx->indexRoot($root);
        }
        return $ctx;
    }

    /**
     * Return the already-created shared Context for `$db` (or null). Used by the
     * reload hook so a file change reindexes an EXISTING index but never creates
     * one on its own (nothing to keep fresh until code_search runs).
     */
    public static function existingContext(?string $db = null): ?self
    {
        return self::$sharedContexts[self::dbKey($db ?? self::defaultDbPath())] ?? null;
    }

    /** Drop the shared registry (test helper). */
    public static function clearShared(): void
    {
        self::$sharedContexts = [];
    }

    private static function defaultDbPath(): string
    {
        $cwd = getcwd();
        return ($cwd !== false ? $cwd : '.') . DIRECTORY_SEPARATOR . '.tina4' . DIRECTORY_SEPARATOR . 'context.db';
    }

    /**
     * Canonical, symlink-resolved key for a db path — stable whether or not the
     * file exists yet (resolves the longest existing ancestor, keeps the
     * non-existent tail). Mirrors Python's Path.resolve() on a not-yet-created
     * path, so defaultContext() and a later existingContext() agree on the key.
     */
    private static function dbKey(string $path): string
    {
        if ($path !== '' && !self::isAbsolutePath($path)) {
            $cwd = getcwd();
            $path = ($cwd !== false ? $cwd : '.') . DIRECTORY_SEPARATOR . $path;
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        $sep = DIRECTORY_SEPARATOR;
        $parts = preg_split('#[\\\\/]+#', $path) ?: [];
        $tail = [];
        for ($i = count($parts); $i > 0; $i--) {
            $prefix = implode($sep, array_slice($parts, 0, $i));
            if ($prefix === '') {
                $prefix = $sep;
            }
            $realPrefix = realpath($prefix);
            if ($realPrefix !== false) {
                return rtrim($realPrefix, $sep) . (empty($tail) ? '' : $sep . implode($sep, $tail));
            }
            array_unshift($tail, $parts[$i - 1]);
        }
        return $path;
    }
}
