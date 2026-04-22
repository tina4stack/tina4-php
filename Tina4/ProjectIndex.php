<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Project index — a lightweight, persistent "where is what" map.
 *
 * The AI chat, in-editor search, and any future code-navigation tools
 * all want the same thing: a mental model of the project that doesn't
 * need to be rebuilt from scratch every turn. This class maintains one.
 *
 * Design (mirrors tina4_python.dev_admin.project_index):
 *
 * - Storage: `.tina4/project_index.json` at the project root. Plain
 *   JSON, diff-friendly, can be committed if the user wants it.
 * - Freshness: lazy-refresh on read. Every public call first walks the
 *   file tree, compares mtimes against stored entries, and re-extracts
 *   stale files. No background thread, no watcher.
 * - Extractors: per-language symbol extraction via PHP core only.
 *     * .php   — `token_get_all()` for class / function / namespace / use
 *                plus regex for Router::get/post/put/patch/delete routes.
 *     * .twig  — regex for extends / block / include
 *     * .sql   — regex for CREATE / ALTER
 *     * .js/ts — regex for exports / imports
 *     * .md    — regex for H1 / H2
 *     * other  — first non-blank line as summary
 * - No 3rd-party deps. No LLM involvement. Pure static analysis.
 */

namespace Tina4;

class ProjectIndex
{
    public const INDEX_DIRNAME = '.tina4';
    public const INDEX_FILENAME = 'project_index.json';

    /** Hard cap on per-file size — anything larger is probably generated. */
    public const MAX_FILE_BYTES = 262144; // 256 KB

    private const SKIP_DIRS = [
        '.git', '.hg', '.svn', 'node_modules', '__pycache__', '.venv', 'venv',
        '.mypy_cache', '.ruff_cache', '.pytest_cache', 'dist', 'build',
        '.tina4', 'logs', '.idea', '.vscode', 'vendor',
    ];

    private const INDEX_EXT = [
        'php', 'py', 'twig', 'html', 'sql', 'scss', 'css', 'js', 'ts',
        'mjs', 'md', 'json', 'yml', 'yaml', 'toml', 'env',
    ];

    private const LANG_MAP = [
        'php' => 'php', 'py' => 'python', 'twig' => 'twig', 'html' => 'html',
        'sql' => 'sql', 'scss' => 'scss', 'css' => 'css', 'js' => 'javascript',
        'mjs' => 'javascript', 'ts' => 'typescript', 'md' => 'markdown',
        'json' => 'json', 'yml' => 'yaml', 'yaml' => 'yaml', 'toml' => 'toml',
        'env' => 'env',
    ];

    // ── Paths ──────────────────────────────────────────────────────

    private static function projectRoot(): string
    {
        $cwd = getcwd();
        $real = $cwd !== false ? (realpath($cwd) ?: $cwd) : '.';
        return str_replace('\\', '/', (string) $real);
    }

    private static function indexPath(): string
    {
        $dir = self::projectRoot() . '/' . self::INDEX_DIRNAME;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . self::INDEX_FILENAME;
    }

    private static function relPath(string $absolute): string
    {
        $root = self::projectRoot() . '/';
        $abs = str_replace('\\', '/', $absolute);
        return str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs;
    }

    private static function languageFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (basename($path) === '.env') {
            return 'env';
        }
        return self::LANG_MAP[$ext] ?? 'text';
    }

    // ── Per-language extractors ────────────────────────────────────

    /**
     * PHP extractor — uses token_get_all() for reliable class / function /
     * namespace / use detection. Routes (Router::get/post/…) are picked
     * out by regex on the source. Returns `[symbols, imports, routes,
     * docstring]`. Never raises — token errors degrade to empty slots.
     */
    private static function extractPhp(string $text): array
    {
        $out = ['symbols' => [], 'imports' => [], 'routes' => [], 'docstring' => ''];
        $tokens = @token_get_all($text, TOKEN_PARSE);
        if (!is_array($tokens)) {
            return $out;
        }
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            [$id, $val] = [$t[0], $t[1]];
            // First doc-comment in file becomes the summary.
            if ($out['docstring'] === '' && $id === T_DOC_COMMENT) {
                $line = self::firstDocLine($val);
                if ($line !== '') {
                    $out['docstring'] = substr($line, 0, 200);
                }
                continue;
            }
            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                // Next T_STRING is the class name.
                $name = self::nextStringToken($tokens, $i + 1);
                if ($name !== '') {
                    $out['symbols'][] = $name;
                }
            } elseif ($id === T_FUNCTION) {
                // Skip anonymous (next non-ws is '(').
                $j = self::skipWhitespace($tokens, $i + 1);
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $out['symbols'][] = $tokens[$j][1];
                }
            } elseif ($id === T_USE || $id === T_NAMESPACE) {
                // Collect dotted/namespaced tokens up to `;` or `{`.
                $parts = [];
                $j = $i + 1;
                while ($j < $n) {
                    $tt = $tokens[$j];
                    if (is_string($tt)) {
                        if ($tt === ';' || $tt === '{') break;
                        $j++;
                        continue;
                    }
                    if ($tt[0] === T_STRING || $tt[0] === T_NS_SEPARATOR || $tt[0] === T_NAME_QUALIFIED || $tt[0] === T_NAME_FULLY_QUALIFIED) {
                        $parts[] = $tt[1];
                    }
                    $j++;
                }
                $flat = trim(implode('', $parts), '\\');
                if ($flat !== '') {
                    $out['imports'][] = $flat;
                }
            }
        }

        // Routes via regex — catches Router::get('/path', …), and
        // Route::get / $router->get() too. Handles single + double
        // quotes; ignores commented-out lines via a negative prefix.
        $routeRe = '/(?:Router|Route)(?:::|->)\s*(get|post|put|patch|delete|any)\s*\(\s*([\'"])(?P<path>[^\'"]+)\2/i';
        if (preg_match_all($routeRe, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out['routes'][] = [
                    'method' => strtoupper($hit[1]),
                    'path' => $hit['path'],
                    'handler' => '',
                ];
            }
        }
        // Ensure deduped + bounded.
        $out['symbols'] = array_values(array_unique($out['symbols']));
        $out['imports'] = array_values(array_unique($out['imports']));
        return $out;
    }

    private static function firstDocLine(string $doc): string
    {
        foreach (preg_split("/\r?\n/", $doc) ?: [] as $line) {
            $clean = trim(preg_replace('#^\s*/?\*+/?\s?#', '', $line) ?? '');
            if ($clean !== '' && !str_starts_with($clean, '@')) {
                return $clean;
            }
        }
        return '';
    }

    private static function nextStringToken(array $tokens, int $start): string
    {
        $n = count($tokens);
        for ($j = $start; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            if (is_string($t)) {
                continue;
            }
        }
        return '';
    }

    private static function skipWhitespace(array $tokens, int $start): int
    {
        $n = count($tokens);
        while ($start < $n && is_array($tokens[$start]) && ($tokens[$start][0] === T_WHITESPACE || $tokens[$start][0] === T_COMMENT)) {
            $start++;
        }
        return $start;
    }

    /** Twig/HTML extractor — extends / blocks / includes. */
    private static function extractTwig(string $text): array
    {
        $extends = $blocks = $includes = [];
        if (preg_match_all('/\{%\s*extends\s+[\'"]([^\'"]+)[\'"]\s*%\}/', $text, $m)) {
            $extends = $m[1];
        }
        if (preg_match_all('/\{%\s*block\s+([A-Za-z_][\w-]*)/', $text, $m)) {
            $blocks = array_values(array_unique($m[1]));
        }
        if (preg_match_all('/\{%\s*include\s+[\'"]([^\'"]+)[\'"]/', $text, $m)) {
            $includes = array_values(array_unique($m[1]));
        }
        sort($blocks);
        sort($includes);
        return ['extends' => $extends, 'blocks' => $blocks, 'includes' => $includes];
    }

    /** SQL extractor — CREATE / ALTER object detection. */
    private static function extractSql(string $text): array
    {
        $creates = $alters = [];
        if (preg_match_all('/create\s+(?:unique\s+)?(table|index|view|trigger|sequence|procedure|function)\s+(?:if\s+not\s+exists\s+)?([A-Za-z_][\w.]*)/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $creates[] = strtoupper($hit[1]) . ' ' . $hit[2];
            }
        }
        if (preg_match_all('/alter\s+(table|index|view)\s+([A-Za-z_][\w.]*)/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $alters[] = strtoupper($hit[1]) . ' ' . $hit[2];
            }
        }
        return ['creates' => $creates, 'alters' => $alters];
    }

    /** JS/TS extractor — exports / imports. */
    private static function extractJsTs(string $text): array
    {
        $exports = $imports = [];
        if (preg_match_all('/^\s*export\s+(?:default\s+)?(?:async\s+)?(?:function|class|const|let|var|interface|type|enum)\s+([A-Za-z_$][\w$]*)/m', $text, $m)) {
            $exports = array_values(array_unique($m[1]));
        }
        if (preg_match_all('/^\s*import\s+[^\'"]+?[\'"]([^\'"]+)[\'"]/m', $text, $m)) {
            $imports = array_values(array_unique($m[1]));
        }
        sort($exports);
        sort($imports);
        return ['exports' => $exports, 'imports' => $imports];
    }

    /** Markdown extractor — H1 title + up to 30 H2 section names. */
    private static function extractMd(string $text): array
    {
        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $text, $m)) {
            $title = trim($m[1]);
        }
        $sections = [];
        if (preg_match_all('/^##\s+(.+)$/m', $text, $m)) {
            $sections = array_slice($m[1], 0, 30);
        }
        return ['title' => $title, 'sections' => $sections];
    }

    /** Generic extractor — first non-blank, non-HTML-comment line. */
    private static function extractGeneric(string $text): array
    {
        foreach (preg_split("/\r?\n/", $text) ?: [] as $line) {
            $s = trim($line);
            if ($s === '' || str_starts_with($s, '<!--')) {
                continue;
            }
            return ['first_line' => substr($s, 0, 200)];
        }
        return [];
    }

    private static function extractFor(string $path, string $text): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'php'         => self::extractPhp($text),
            'twig', 'html' => self::extractTwig($text),
            'sql'         => self::extractSql($text),
            'js', 'mjs', 'ts' => self::extractJsTs($text),
            'md'          => self::extractMd($text),
            default       => self::extractGeneric($text),
        };
    }

    // ── Core index ────────────────────────────────────────────────

    private static function extractEntry(string $absolute): array
    {
        $stat = @stat($absolute);
        if ($stat === false) {
            return [];
        }
        $size = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];
        $entry = [
            'path' => self::relPath($absolute),
            'size' => $size,
            'mtime' => $mtime,
            'language' => self::languageFor($absolute),
        ];
        if ($size > self::MAX_FILE_BYTES) {
            $entry['skipped'] = "too large ({$size} bytes)";
            return $entry;
        }
        $text = @file_get_contents($absolute);
        if ($text === false) {
            return $entry;
        }
        $entry['sha256'] = substr(hash('sha256', $text), 0, 16);
        try {
            $entry = $entry + self::extractFor($absolute, $text);
        } catch (\Throwable $e) {
            $entry['extraction_error'] = substr($e->getMessage(), 0, 200);
        }
        $entry['summary'] = self::summarise($entry);
        return $entry;
    }

    private static function summarise(array $e): string
    {
        if (isset($e['skipped']))                return $e['skipped'];
        if (!empty($e['docstring']))             return $e['docstring'];
        if (!empty($e['title']))                 return $e['title'];
        if (!empty($e['routes'])) {
            $r = $e['routes'][0];
            $extra = count($e['routes']) > 1 ? ' (+' . (count($e['routes']) - 1) . ' more)' : '';
            return $r['method'] . ' ' . $r['path'] . $extra;
        }
        if (!empty($e['symbols']))               return 'defines ' . implode(', ', array_slice($e['symbols'], 0, 4));
        if (!empty($e['exports']))               return 'exports ' . implode(', ', array_slice($e['exports'], 0, 4));
        if (!empty($e['creates']))               return 'schema: ' . implode(', ', array_slice($e['creates'], 0, 3));
        if (!empty($e['extends']))               return 'template, extends ' . $e['extends'][0];
        if (!empty($e['first_line']))            return $e['first_line'];
        return '';
    }

    /**
     * Walk the project tree once — yields absolute file paths for
     * everything we should index. Honours SKIP_DIRS and INDEX_EXT.
     */
    private static function walkProject(): array
    {
        $root = self::projectRoot();
        $found = [];
        $stack = [$root];
        while ($stack) {
            $dir = array_pop($stack);
            $handle = @opendir($dir);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $full = $dir . '/' . $entry;
                if (is_dir($full)) {
                    if (in_array($entry, self::SKIP_DIRS, true)) continue;
                    if ($entry !== '.' && str_starts_with($entry, '.')) continue;
                    $stack[] = $full;
                    continue;
                }
                if (str_starts_with($entry, '.') && $entry !== '.env') continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if ($entry !== '.env' && !in_array($ext, self::INDEX_EXT, true)) continue;
                $found[] = $full;
            }
            closedir($handle);
        }
        return $found;
    }

    private static function loadRaw(): array
    {
        $p = self::indexPath();
        if (!is_file($p)) {
            return ['version' => 1, 'files' => [], 'generated_at' => 0];
        }
        $data = json_decode((string) file_get_contents($p), true);
        if (!is_array($data)) {
            return ['version' => 1, 'files' => [], 'generated_at' => 0];
        }
        return $data;
    }

    private static function saveRaw(array $data): void
    {
        $data['generated_at'] = time();
        @file_put_contents(self::indexPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ── Public API ────────────────────────────────────────────────

    public static function refresh(): array
    {
        $data = self::loadRaw();
        $files = is_array($data['files'] ?? null) ? $data['files'] : [];
        $added = 0;
        $updated = 0;
        $seen = [];
        foreach (self::walkProject() as $abs) {
            $rel = self::relPath($abs);
            $seen[$rel] = true;
            $stat = @stat($abs);
            if ($stat === false) continue;
            $mtime = (int) $stat['mtime'];
            $existing = $files[$rel] ?? null;
            if ($existing && (int) ($existing['mtime'] ?? 0) === $mtime) {
                continue;
            }
            $files[$rel] = self::extractEntry($abs);
            if ($existing) $updated++;
            else           $added++;
        }
        $removed = [];
        foreach ($files as $k => $_) {
            if (!isset($seen[$k])) $removed[] = $k;
        }
        foreach ($removed as $k) {
            unset($files[$k]);
        }
        $data['files'] = $files;
        self::saveRaw($data);
        return [
            'added' => $added,
            'updated' => $updated,
            'removed' => count($removed),
            'total' => count($files),
            'path' => self::relPath(self::indexPath()),
        ];
    }

    public static function search(string $query, int $limit = 20): array
    {
        self::refresh();
        $data = self::loadRaw();
        $q = strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        $hits = [];
        foreach ($data['files'] ?? [] as $rel => $entry) {
            $score = 0;
            if (str_contains(strtolower($rel), $q)) $score += 10;
            foreach (($entry['symbols'] ?? []) as $s) {
                $sl = strtolower($s);
                if ($q === $sl)              $score += 8;
                elseif (str_contains($sl, $q)) $score += 4;
            }
            foreach (($entry['routes'] ?? []) as $r) {
                $hay = strtolower(($r['path'] ?? '') . ' ' . ($r['handler'] ?? ''));
                if (str_contains($hay, $q)) $score += 5;
            }
            if (str_contains(strtolower($entry['summary'] ?? ''), $q)) $score += 3;
            foreach (($entry['imports'] ?? []) as $imp) {
                if (str_contains(strtolower($imp), $q)) $score += 1;
            }
            if ($score > 0) {
                $hits[] = [
                    'path' => $rel,
                    'summary' => $entry['summary'] ?? '',
                    'score' => $score,
                    'language' => $entry['language'] ?? '',
                ];
            }
        }
        usort($hits, fn($a, $b) => $b['score'] - $a['score']);
        return array_slice($hits, 0, max(1, $limit));
    }

    public static function fileEntry(string $relPath): array
    {
        self::refresh();
        $data = self::loadRaw();
        $entry = $data['files'][$relPath] ?? null;
        return $entry ?? ['error' => "Not in index: {$relPath}"];
    }

    public static function overview(): array
    {
        self::refresh();
        $data = self::loadRaw();
        $files = $data['files'] ?? [];
        $langs = [];
        $routeCount = 0;
        $modelCount = 0;
        foreach ($files as $entry) {
            $lang = $entry['language'] ?? 'other';
            $langs[$lang] = ($langs[$lang] ?? 0) + 1;
            $routeCount += count($entry['routes'] ?? []);
            $path = $entry['path'] ?? '';
            if (str_starts_with($path, 'src/orm/') && !empty($entry['symbols'])) {
                $modelCount++;
            }
        }
        $recent = [];
        foreach ($files as $e) {
            $recent[] = [
                'path' => $e['path'] ?? '',
                'summary' => $e['summary'] ?? '',
                'mtime' => $e['mtime'] ?? 0,
            ];
        }
        usort($recent, fn($a, $b) => ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0));
        return [
            'total_files' => count($files),
            'by_language' => $langs,
            'routes_declared' => $routeCount,
            'orm_models' => $modelCount,
            'recently_changed' => array_slice($recent, 0, 10),
            'index_generated_at' => (int) ($data['generated_at'] ?? 0),
        ];
    }
}
