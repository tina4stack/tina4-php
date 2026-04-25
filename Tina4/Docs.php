<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Live API RAG — reflection-backed documentation for the running framework
 * and the user's own project surface.
 *
 * Spec: plan/v3/22-LIVE-API-RAG.md — endpoint contract, ranking algorithm,
 * cache strategy and source-tag rules are all frozen there.
 *
 * Zero external dependencies. Stdlib reflection + token_get_all only.
 */

declare(strict_types=1);

namespace Tina4;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use ReflectionType;
use ReflectionParameter;

/**
 * Tina4\Docs — reflects framework + user code into a searchable index.
 */
class Docs
{
    /** Stdlib / commonly-expected method names that should NOT be flagged as drift. */
    private const STDLIB_ALLOWLIST = [
        // PHP built-ins frequently used in docs
        'json_encode', 'json_decode', 'print_r', 'var_dump', 'var_export',
        'array_map', 'array_filter', 'array_merge', 'array_keys', 'array_values',
        'count', 'sizeof', 'strlen', 'substr', 'str_replace', 'str_contains',
        'explode', 'implode', 'trim', 'strtolower', 'strtoupper', 'sprintf',
        'printf', 'file_get_contents', 'file_put_contents', 'is_array', 'is_string',
        'is_null', 'is_int', 'is_bool', 'is_numeric', 'is_callable', 'is_object',
        'isset', 'empty', 'unset', 'define', 'defined', 'getenv', 'setenv',
        'microtime', 'time', 'date', 'strtotime', 'mktime',
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_split',
        'htmlspecialchars', 'htmlentities', 'urlencode', 'urldecode',
        'base64_encode', 'base64_decode', 'hash', 'md5', 'sha1', 'password_hash',
        'password_verify', 'random_bytes', 'bin2hex', 'hex2bin',
        // Python / JS / Ruby names that PHP docs occasionally reference
        'length', 'push', 'pop', 'shift', 'unshift', 'slice', 'splice',
        'append', 'extend', 'get', 'set', 'has', 'put', 'patch', 'post',
        'delete', 'keys', 'values', 'items', 'map', 'filter', 'reduce',
        // PHPUnit / reflection names
        'assertEquals', 'assertTrue', 'assertFalse', 'assertNull', 'assertNotNull',
        'assertSame', 'assertContains', 'assertArrayHasKey', 'expectException',
        // Iterators
        'forEach', 'each', 'next', 'prev', 'current', 'rewind', 'valid', 'key',
    ];

    private string $projectRoot;
    private string $frameworkRoot;
    private string $userNamespace;
    private array $userDirs;
    private string $version;

    /** @var array<string, array>|null Full flat index — null until built */
    private ?array $indexCache = null;

    /** @var int Max user-file mtime at last user rebuild */
    private int $userMtime = 0;

    /** @var int Max framework-file mtime at last framework rebuild */
    private int $frameworkMtime = 0;

    /** @var array<string, array> Framework-only entries, cached across user rebuilds */
    private array $frameworkEntries = [];

    /** @var array<string, array> User-only entries, rebuilt on mtime change */
    private array $userEntries = [];

    /** @var array<string, Docs> Static instance cache for MCP calls */
    private static array $mcpInstances = [];

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        // Framework root is the directory containing this file's parent (Tina4/).
        $this->frameworkRoot = rtrim(str_replace('\\', '/', dirname(__DIR__) . '/Tina4'), '/');
        $this->userDirs = [
            $this->projectRoot . '/src/orm',
            $this->projectRoot . '/src/routes',
            $this->projectRoot . '/src/app',
            $this->projectRoot . '/src/services',
        ];
        $this->userNamespace = $this->detectUserNamespace();
        $this->version = $this->detectVersion();
    }

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Search the merged framework + user index for query-matching entities.
     *
     * @param string $query           Free-text query (split on whitespace + camelCase)
     * @param int    $k               Top-K to return
     * @param string $source          Filter: 'all' (default), 'framework', 'user', 'vendor'
     * @param bool   $includePrivate  Include private/protected/underscore methods
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $k = 5, string $source = 'all', bool $includePrivate = false): array
    {
        $this->ensureIndex();
        $tokens = $this->tokenise($query);
        if (empty($tokens)) {
            return [];
        }
        $joined = strtolower(str_replace(' ', '', $query));
        $results = [];
        foreach ($this->indexCache ?? [] as $entry) {
            // Source filter
            if ($source !== 'all' && ($entry['source'] ?? '') !== $source) {
                continue;
            }
            // Default excludes vendor
            if ($source === 'all' && ($entry['source'] ?? '') === 'vendor') {
                continue;
            }
            // Private-method filter
            if (!$includePrivate && !empty($entry['_private'])) {
                continue;
            }
            $score = $this->scoreEntry($entry, $tokens, $joined);
            if ($score <= 0) {
                continue;
            }
            if (($entry['source'] ?? '') === 'user') {
                $score *= 1.2;
            }
            $hit = $entry;
            unset($hit['_private']);
            $hit['score'] = $score;
            $results[] = $hit;
        }
        usort($results, function (array $a, array $b): int {
            $cmp = ($b['score'] <=> $a['score']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['fqn'] ?? ''), (string)($b['fqn'] ?? ''));
        });
        return array_slice($results, 0, max(1, $k));
    }

    /**
     * Return the full spec for a single class (methods + properties).
     *
     * @return array<string,mixed>|null  Null for unknown / non-reflectable classes.
     */
    public function classSpec(string $fqn): ?array
    {
        $this->ensureIndex();
        $key = $this->classKey($fqn);
        $entry = $this->indexCache[$key] ?? null;
        if ($entry === null || ($entry['kind'] ?? '') !== 'class') {
            return null;
        }
        // Collect method entries from the index (token-parsed, always fresh).
        $methods = [];
        $prefix = $entry['fqn'] . '::';
        foreach ($this->indexCache ?? [] as $k => $e) {
            if (!str_starts_with($k, $prefix)) {
                continue;
            }
            if (($e['kind'] ?? '') !== 'method') {
                continue;
            }
            if (($e['visibility'] ?? 'public') !== 'public') {
                continue;
            }
            $methods[] = [
                'name'       => $e['name'],
                'fqn'        => $e['fqn'],
                'class'      => $e['class'],
                'signature'  => $e['signature'],
                'summary'    => $e['summary'],
                'docblock'   => $e['docblock'] ?? '',
                'file'       => $e['file'],
                'line'       => $e['line'],
                'visibility' => $e['visibility'],
                'static'     => $e['static'] ?? false,
                'source'     => $e['source'],
                'version'    => $e['version'],
            ];
        }
        return [
            'fqn'        => $entry['fqn'],
            'kind'       => 'class',
            'file'       => $entry['file'],
            'line'       => $entry['line'],
            'summary'    => $entry['summary'],
            'source'     => $entry['source'],
            'version'    => $entry['version'],
            'methods'    => $methods,
            'properties' => [],
        ];
    }

    /**
     * Return the spec for a single method, or null if unknown.
     *
     * @return array<string,mixed>|null
     */
    public function methodSpec(string $classFqn, string $methodName): ?array
    {
        $this->ensureIndex();
        $key = $this->classKey($classFqn);
        $classEntry = $this->indexCache[$key] ?? null;
        if ($classEntry === null) {
            return null;
        }
        $methodKey = $classEntry['fqn'] . '::' . $methodName;
        $entry = $this->indexCache[$methodKey] ?? null;
        if ($entry === null || ($entry['kind'] ?? '') !== 'method') {
            return null;
        }
        return [
            'name'       => $entry['name'],
            'fqn'        => $entry['fqn'],
            'class'      => $entry['class'],
            'signature'  => $entry['signature'],
            'summary'    => $entry['summary'],
            'docblock'   => $entry['docblock'] ?? '',
            'file'       => $entry['file'],
            'line'       => $entry['line'],
            'visibility' => $entry['visibility'],
            'static'     => $entry['static'] ?? false,
            'source'     => $entry['source'],
            'version'    => $entry['version'],
            'params'     => [],
            'return'     => '',
        ];
    }

    /**
     * Flat list of every reflected entity (classes + methods), user + framework.
     *
     * @return array<int,array<string,mixed>>
     */
    public function index(): array
    {
        $this->ensureIndex();
        $out = [];
        foreach ($this->indexCache ?? [] as $entry) {
            $clean = $entry;
            unset($clean['_private']);
            $out[] = $clean;
        }
        return $out;
    }

    // ── MCP static entry points ──────────────────────────────────────

    /**
     * MCP tool entry — mirrors search() using the current working directory
     * as the project root.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function mcpSearch(string $q, int $k = 5): array
    {
        return self::mcpInstance()->search($q, $k);
    }

    /**
     * MCP tool entry — mirrors methodSpec() using the current working directory.
     */
    public static function mcpMethod(string $classFqn, string $name): ?array
    {
        return self::mcpInstance()->methodSpec($classFqn, $name);
    }

    // ── Drift check & sync ───────────────────────────────────────────

    /**
     * Scan fenced code blocks in a markdown file for method-call references
     * that don't exist anywhere in the live index. Returns `['drift' => [...]]`.
     *
     * @return array{drift: array<int,array<string,mixed>>}
     */
    public static function checkDocs(string $mdFilePath): array
    {
        if (!is_file($mdFilePath)) {
            return ['drift' => []];
        }
        $docs = self::mcpInstance();
        $known = $docs->knownMethodNames();
        $text = (string) file_get_contents($mdFilePath);
        $drift = [];
        // Find fenced code blocks and their starting lines
        $lines = preg_split("/\r?\n/", $text) ?: [];
        $inBlock = false;
        $blockLang = '';
        $blockStart = 0;
        $blockLines = [];
        $allBlocks = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                if ($inBlock) {
                    $allBlocks[] = ['lang' => $blockLang, 'start' => $blockStart, 'lines' => $blockLines];
                    $inBlock = false;
                    $blockLang = '';
                    $blockLines = [];
                } else {
                    $inBlock = true;
                    $blockLang = $m[1];
                    $blockStart = $i + 1; // 1-based line number of opening fence
                    $blockLines = [];
                }
                continue;
            }
            if ($inBlock) {
                $blockLines[] = ['line' => $i + 1, 'text' => $line];
            }
        }
        // Regex trifecta: $var->method(, Class::method(, Class.method(
        $patterns = [
            '/\$\w+->(\w+)\s*\(/',
            '/\b[A-Z]\w*::(\w+)\s*\(/',
            '/\b[A-Z]\w*\.(\w+)\s*\(/',
        ];
        $allowlist = array_flip(array_map('strtolower', self::STDLIB_ALLOWLIST));
        foreach ($allBlocks as $block) {
            foreach ($block['lines'] as $entry) {
                foreach ($patterns as $pat) {
                    if (preg_match_all($pat, $entry['text'], $m, PREG_SET_ORDER)) {
                        foreach ($m as $hit) {
                            $method = $hit[1];
                            $methodLower = strtolower($method);
                            if (isset($allowlist[$methodLower])) {
                                continue;
                            }
                            if (isset($known[$methodLower])) {
                                continue;
                            }
                            $drift[] = [
                                'method' => $method,
                                'line'   => $entry['line'],
                                'block'  => trim($entry['text']),
                            ];
                        }
                    }
                }
            }
        }
        return ['drift' => $drift];
    }

    /**
     * Overwrite the `<!-- BEGIN GENERATED API -->` / `<!-- END GENERATED API -->`
     * block in a markdown file with fresh index content. Prose outside the
     * markers is preserved. If markers are absent, a block is appended.
     */
    public static function syncDocs(string $mdFilePath): void
    {
        $docs = self::mcpInstance();
        $generated = $docs->renderGeneratedBlock();
        $begin = '<!-- BEGIN GENERATED API -->';
        $end   = '<!-- END GENERATED API -->';
        $existing = is_file($mdFilePath) ? (string) file_get_contents($mdFilePath) : '';
        if (str_contains($existing, $begin) && str_contains($existing, $end)) {
            $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';
            $replacement = $begin . "\n" . $generated . "\n" . $end;
            $new = preg_replace($pattern, $replacement, $existing, 1);
            if ($new !== null) {
                file_put_contents($mdFilePath, $new);
            }
            return;
        }
        $block = "\n\n" . $begin . "\n" . $generated . "\n" . $end . "\n";
        file_put_contents($mdFilePath, $existing . $block);
    }

    // ── Index build pipeline ─────────────────────────────────────────

    private function ensureIndex(): void
    {
        // Framework index is built once, only re-built if files change.
        $fwMtime = $this->maxMtime([$this->frameworkRoot]);
        if (empty($this->frameworkEntries) || $fwMtime !== $this->frameworkMtime) {
            $this->frameworkEntries = $this->buildFrameworkIndex();
            $this->frameworkMtime = $fwMtime;
            $this->indexCache = null;
        }
        // User index is rebuilt on any mtime change under user dirs.
        $userMtime = $this->maxMtime($this->userDirs);
        if ($this->indexCache === null || $userMtime !== $this->userMtime) {
            $this->userEntries = $this->buildUserIndex();
            $this->userMtime = $userMtime;
            $this->indexCache = $this->frameworkEntries + $this->userEntries;
        }
    }

    /** @return array<string,array> Keyed by class FQN + '::' + method-name (or class FQN alone). */
    private function buildFrameworkIndex(): array
    {
        $entries = [];
        foreach ($this->walkPhpFiles($this->frameworkRoot) as $absPath) {
            $this->indexPhpFile($absPath, 'framework', $entries);
        }
        // Also scan vendor/tina4stack/*
        $tina4VendorRoot = $this->projectRoot . '/vendor/tina4stack';
        if (is_dir($tina4VendorRoot)) {
            foreach ($this->walkPhpFiles($tina4VendorRoot) as $absPath) {
                $this->indexPhpFile($absPath, 'framework', $entries);
            }
        }
        return $entries;
    }

    /** @return array<string,array> User-source entries. */
    private function buildUserIndex(): array
    {
        $entries = [];
        foreach ($this->userDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->walkPhpFiles($dir) as $absPath) {
                $this->indexPhpFile($absPath, 'user', $entries);
            }
        }
        return $entries;
    }

    /**
     * Parse a PHP file via tokens and index every class / method.
     *
     * Token-based (no eager reflection) so user-code edits are picked up
     * immediately on the next query without the "class already declared"
     * reflection staleness problem. Reflection is used lazily by
     * classSpec() / methodSpec() when richer data is requested.
     */
    private function indexPhpFile(string $absPath, string $source, array &$entries): void
    {
        $text = @file_get_contents($absPath);
        if ($text === false) {
            return;
        }
        // Skip huge files (generated code, vendors)
        if (strlen($text) > 1024 * 1024) {
            return;
        }
        $parsed = $this->parseSource($text);
        if (empty($parsed)) {
            return;
        }
        $relFile = $this->relativePath($absPath);
        foreach ($parsed as $cls) {
            $fqn = $cls['fqn'];
            $classEntry = [
                'fqn'       => $fqn,
                'kind'      => 'class',
                'name'      => $cls['name'],
                'signature' => $cls['kind'] . ' ' . $cls['name'],
                'summary'   => $this->summariseDocblock($cls['doc']),
                'file'      => $relFile,
                'line'      => $cls['line'],
                'version'   => $this->version,
                'source'    => $source,
                'docblock'  => $this->docblockBody($cls['doc']),
            ];
            $entries[$fqn] = $classEntry;
            foreach ($cls['methods'] as $method) {
                $mName = $method['name'];
                $isPrivate = $method['visibility'] !== 'public' || str_starts_with($mName, '_');
                $mSummary = $this->summariseDocblock($method['doc']);
                $methodEntry = [
                    'fqn'       => $fqn . '::' . $mName,
                    'kind'      => 'method',
                    'name'      => $mName,
                    'class'     => $fqn,
                    'signature' => $method['signature'],
                    'summary'   => $mSummary !== '' ? $mSummary : $classEntry['summary'],
                    'file'      => $relFile,
                    'line'      => $method['line'],
                    'version'   => $this->version,
                    'source'    => $source,
                    'visibility'=> $method['visibility'],
                    'static'    => $method['static'],
                    'docblock'  => $this->docblockBody($method['doc']),
                    '_private'  => $isPrivate,
                ];
                $entries[$fqn . '::' . $mName] = $methodEntry;
            }
        }
    }

    /**
     * Parse a PHP source string into [[fqn, name, kind, line, doc, methods:[...]]].
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseSource(string $source): array
    {
        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return [];
        }
        $n = count($tokens);
        $namespace = '';
        $classes = [];
        $lastDoc = '';
        $braceDepth = 0;
        $interpDepth = 0; // Depth of T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES (they pair with }'s in strings)
        $currentClass = null; // null or ['start_depth' => int, 'entry' => array]
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                $id = $t[0];
                $val = $t[1];
                $line = $t[2] ?? 0;
                if ($id === T_DOC_COMMENT) {
                    $lastDoc = $val;
                    continue;
                }
                if ($id === T_WHITESPACE || $id === T_COMMENT) {
                    // Whitespace doesn't reset docblock — a docblock stays
                    // valid until a non-whitespace/non-comment token consumes it
                    continue;
                }
                if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    // String interpolation `{$foo}` / `${foo}` — paired with a literal `}`,
                    // but we must NOT treat that `}` as closing a class/function body.
                    $interpDepth++;
                    continue;
                }
                // Modifiers preceding a function/class don't consume the docblock
                if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    continue;
                }
                if ($id === T_NAMESPACE) {
                    $parts = [];
                    for ($j = $i + 1; $j < $n; $j++) {
                        $tt = $tokens[$j];
                        if (is_string($tt)) {
                            if ($tt === ';' || $tt === '{') break;
                            continue;
                        }
                        if (in_array($tt[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            $parts[] = $tt[1];
                        }
                    }
                    $namespace = trim(implode('', $parts), '\\');
                    $lastDoc = '';
                    continue;
                }
                if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    // Skip ::class
                    $prev = $i - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT], true)) {
                        $prev--;
                    }
                    if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                        continue;
                    }
                    // Class name is next T_STRING
                    $name = '';
                    $classLine = $line;
                    for ($j = $i + 1; $j < $n; $j++) {
                        $tt = $tokens[$j];
                        if (is_array($tt) && $tt[0] === T_STRING) {
                            $name = $tt[1];
                            break;
                        }
                    }
                    if ($name === '') {
                        continue;
                    }
                    $kind = match ($id) {
                        T_INTERFACE => 'interface',
                        T_TRAIT     => 'trait',
                        T_ENUM      => 'enum',
                        default     => 'class',
                    };
                    $fqn = $namespace !== '' ? $namespace . '\\' . $name : $name;
                    $currentClass = [
                        'start_depth' => $braceDepth,
                        'entry' => [
                            'fqn'     => $fqn,
                            'name'    => $name,
                            'kind'    => $kind,
                            'line'    => $classLine,
                            'doc'     => $lastDoc,
                            'methods' => [],
                        ],
                    ];
                    $lastDoc = '';
                    // Fast-forward to the opening brace
                    for ($k = $i + 1; $k < $n; $k++) {
                        $tk = $tokens[$k];
                        if (is_string($tk) && $tk === '{') {
                            $braceDepth++;
                            $i = $k;
                            break;
                        }
                    }
                    continue;
                }
                if ($id === T_FUNCTION && $currentClass !== null && $braceDepth === $currentClass['start_depth'] + 1) {
                    // Collect modifiers looking backward for public/private/protected/static
                    $visibility = 'public';
                    $static = false;
                    for ($k = $i - 1; $k >= 0; $k--) {
                        $tk = $tokens[$k];
                        if (is_string($tk)) break;
                        $tkId = $tk[0];
                        if ($tkId === T_WHITESPACE || $tkId === T_COMMENT) continue;
                        if ($tkId === T_PUBLIC) { $visibility = 'public'; continue; }
                        if ($tkId === T_PROTECTED) { $visibility = 'protected'; continue; }
                        if ($tkId === T_PRIVATE) { $visibility = 'private'; continue; }
                        if ($tkId === T_STATIC) { $static = true; continue; }
                        if ($tkId === T_ABSTRACT || $tkId === T_FINAL) continue;
                        if ($tkId === T_DOC_COMMENT) continue;
                        break;
                    }
                    // Function name
                    $mName = '';
                    $mLine = $line;
                    $sigEnd = $i;
                    for ($j = $i + 1; $j < $n; $j++) {
                        $tt = $tokens[$j];
                        if (is_array($tt) && $tt[0] === T_STRING) {
                            $mName = $tt[1];
                            $sigEnd = $j;
                            break;
                        }
                        if (is_string($tt) && $tt === '(') {
                            // Anonymous
                            break;
                        }
                    }
                    if ($mName === '') {
                        continue;
                    }
                    // Capture signature tokens from '(' through ')' plus optional ': type'
                    $signature = $this->captureMethodSignature($tokens, $sigEnd + 1, $n);
                    $currentClass['entry']['methods'][] = [
                        'name'       => $mName,
                        'line'       => $mLine,
                        'doc'        => $lastDoc,
                        'visibility' => $visibility,
                        'static'     => $static,
                        'signature'  => $mName . $signature,
                    ];
                    $lastDoc = '';
                    continue;
                }
                // Non-whitespace, non-doc-comment other tokens consume the docblock.
                $lastDoc = '';
                continue;
            }
            // Punctuation
            if ($t === '{') {
                $braceDepth++;
                $lastDoc = '';
                continue;
            }
            if ($t === '}') {
                if ($interpDepth > 0) {
                    $interpDepth--;
                } else {
                    $braceDepth--;
                    if ($currentClass !== null && $braceDepth <= $currentClass['start_depth']) {
                        $classes[] = $currentClass['entry'];
                        $currentClass = null;
                    }
                }
                $lastDoc = '';
                continue;
            }
            $lastDoc = '';
        }
        if ($currentClass !== null) {
            $classes[] = $currentClass['entry'];
        }
        return $classes;
    }

    /**
     * Capture method signature tokens starting AT the `(` up through
     * the optional return type (before the `{`). Returns `(params): ret`
     * as a flat string (preserving types as written).
     */
    private function captureMethodSignature(array $tokens, int $start, int $n): string
    {
        // Find the first '(' at/after $start
        $i = $start;
        while ($i < $n && !(is_string($tokens[$i]) && $tokens[$i] === '(')) {
            $i++;
        }
        if ($i >= $n) {
            return '()';
        }
        $out = '';
        $parenDepth = 0;
        // Consume through matching close paren
        for (; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                $out .= $t;
                if ($t === '(') $parenDepth++;
                elseif ($t === ')') {
                    $parenDepth--;
                    if ($parenDepth === 0) {
                        $i++;
                        break;
                    }
                }
            } else {
                $out .= $t[1];
            }
        }
        // Capture optional return type up to '{' or ';'
        $ret = '';
        for (; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($t === '{' || $t === ';') break;
                $ret .= $t;
            } else {
                if ($t[0] === T_WHITESPACE) { $ret .= ' '; continue; }
                $ret .= $t[1];
            }
        }
        // Normalise whitespace
        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $ret = trim(preg_replace('/\s+/', ' ', $ret) ?? '');
        return $out . ($ret !== '' ? $ret : '');
    }

    // ── Reflection helpers ───────────────────────────────────────────

    private function buildMethodSpec(ReflectionMethod $rm, string $source): array
    {
        $declaring = $rm->getDeclaringClass();
        $file = $declaring->getFileName() ?: '';
        $relFile = $file !== '' ? $this->relativePath($file) : '';
        $params = [];
        foreach ($rm->getParameters() as $rp) {
            $params[] = [
                'name'     => $rp->getName(),
                'type'     => $this->typeToString($rp->getType()),
                'default'  => $rp->isDefaultValueAvailable() ? $this->exportDefault($rp) : null,
                'optional' => $rp->isOptional(),
                'variadic' => $rp->isVariadic(),
            ];
        }
        $doc = $rm->getDocComment() ?: '';
        return [
            'name'       => $rm->getName(),
            'fqn'        => $declaring->getName() . '::' . $rm->getName(),
            'class'      => $declaring->getName(),
            'signature'  => $this->methodSignature($rm),
            'params'     => $params,
            'return'     => $this->typeToString($rm->getReturnType()),
            'summary'    => $this->summariseDocblock($doc),
            'docblock'   => $this->docblockBody($doc),
            'file'       => $relFile,
            'line'       => $rm->getStartLine() ?: 1,
            'visibility' => $rm->isPublic() ? 'public' : ($rm->isProtected() ? 'protected' : 'private'),
            'static'     => $rm->isStatic(),
            'source'     => $source,
            'version'    => $this->version,
        ];
    }

    private function methodSignature(ReflectionMethod $rm): string
    {
        $parts = [];
        foreach ($rm->getParameters() as $rp) {
            $type = $this->typeToString($rp->getType());
            $prefix = $type !== '' ? $type . ' ' : '';
            $variadic = $rp->isVariadic() ? '...' : '';
            $byRef = $rp->isPassedByReference() ? '&' : '';
            $suffix = '';
            if ($rp->isDefaultValueAvailable()) {
                $default = $this->exportDefault($rp);
                $suffix = ' = ' . $default;
            }
            $parts[] = $prefix . $byRef . $variadic . '$' . $rp->getName() . $suffix;
        }
        $return = $this->typeToString($rm->getReturnType());
        $returnSuffix = $return !== '' ? ': ' . $return : '';
        return $rm->getName() . '(' . implode(', ', $parts) . ')' . $returnSuffix;
    }

    private function exportDefault(ReflectionParameter $rp): string
    {
        try {
            $val = $rp->getDefaultValue();
        } catch (\Throwable) {
            return 'null';
        }
        return match (true) {
            $val === null       => 'null',
            $val === true       => 'true',
            $val === false      => 'false',
            is_string($val)     => "'" . addslashes($val) . "'",
            is_int($val), is_float($val) => (string) $val,
            is_array($val)      => '[]',
            default             => var_export($val, true),
        };
    }

    private function typeToString(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $nullable = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';
            return $nullable . $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $this->typeToString($t), $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => $this->typeToString($t), $type->getTypes()));
        }
        return (string) $type;
    }

    /** @return string[] Fully-qualified class names declared in the source. */
    private function extractClassNames(string $source): array
    {
        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return [];
        }
        $namespace = '';
        $classes = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            [$id] = [$t[0]];
            if ($id === T_NAMESPACE) {
                $parts = [];
                for ($j = $i + 1; $j < $n; $j++) {
                    $tt = $tokens[$j];
                    if (is_string($tt)) {
                        if ($tt === ';' || $tt === '{') break;
                        continue;
                    }
                    if (in_array($tt[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $parts[] = $tt[1];
                    }
                }
                $namespace = trim(implode('', $parts), '\\');
                continue;
            }
            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                // Skip `T_CLASS` used in ::class
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                    continue;
                }
                // Next T_STRING is the class name
                for ($j = $i + 1; $j < $n; $j++) {
                    $tt = $tokens[$j];
                    if (is_array($tt) && $tt[0] === T_STRING) {
                        $name = $tt[1];
                        $classes[] = $namespace !== '' ? $namespace . '\\' . $name : $name;
                        break;
                    }
                    if (is_string($tt) && ($tt === '{' || $tt === '(')) {
                        break;
                    }
                }
            }
        }
        return array_values(array_unique($classes));
    }

    private function summariseDocblock(string $doc): string
    {
        if ($doc === '') {
            return '';
        }
        foreach (preg_split("/\r?\n/", $doc) ?: [] as $line) {
            $clean = trim(preg_replace('#^\s*/?\*+/?\s?#', '', $line) ?? '');
            if ($clean === '' || str_starts_with($clean, '@')) {
                continue;
            }
            return substr($clean, 0, 240);
        }
        return '';
    }

    private function docblockBody(string $doc): string
    {
        if ($doc === '') {
            return '';
        }
        $lines = [];
        foreach (preg_split("/\r?\n/", $doc) ?: [] as $line) {
            $clean = trim(preg_replace('#^\s*/?\*+/?\s?#', '', $line) ?? '');
            if ($clean === '') {
                continue;
            }
            $lines[] = $clean;
        }
        return implode(' ', $lines);
    }

    // ── Ranking / tokenisation ───────────────────────────────────────

    /**
     * Lowercase + split on whitespace + camelCase / snake_case boundaries.
     *
     * @return string[]
     */
    private function tokenise(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        // First split camelCase → space separated
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $s) ?? $s;
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $s) ?? $s;
        $s = str_replace(['_', '-', '\\', '/', '.', '(', ')', ',', ':', ';'], ' ', $s);
        $s = strtolower($s);
        $parts = preg_split('/\s+/', $s) ?: [];
        return array_values(array_filter($parts, fn($p) => $p !== '' && strlen($p) > 0));
    }

    private function scoreEntry(array $entry, array $tokens, string $joined): float
    {
        $name = strtolower($entry['name'] ?? '');
        $nameStripped = ltrim($name, '_');
        $summary = strtolower((string)($entry['summary'] ?? ''));
        $doc = strtolower((string)($entry['docblock'] ?? ''));
        $score = 0.0;
        // Exact case-insensitive match on name (with leading underscore stripped)
        if ($name === $joined || $nameStripped === $joined) {
            $score += 5;
        }
        // Also split the entry's name into tokens
        $nameTokens = $this->tokenise($entry['name'] ?? '');
        foreach ($tokens as $tk) {
            // Prefix of name (strict spec rule)
            if ($tk !== '' && (str_starts_with($name, $tk) || str_starts_with($nameStripped, $tk))) {
                $score += 3;
                continue;
            }
            // Or match any name-token (handles camelCase well, e.g. "hash" vs hashPassword)
            foreach ($nameTokens as $nt) {
                if ($nt === $tk) {
                    $score += 3;
                    break;
                }
                if (str_starts_with($nt, $tk)) {
                    $score += 2;
                    break;
                }
            }
        }
        // Summary token matches
        foreach ($tokens as $tk) {
            if ($tk !== '' && str_contains($summary, $tk)) {
                $score += 2;
            }
        }
        // Docblock body token matches
        foreach ($tokens as $tk) {
            if ($tk !== '' && str_contains($doc, $tk)) {
                $score += 1;
            }
        }
        // Substring fallback — if the whole joined query appears in the name
        if ($joined !== '' && $score === 0.0 && str_contains($name, $joined)) {
            $score += 2;
        }
        return $score;
    }

    // ── Drift / sync helpers ─────────────────────────────────────────

    /** @return array<string,true> Lowercased method-name set for drift detection. */
    private function knownMethodNames(): array
    {
        $this->ensureIndex();
        $known = [];
        foreach ($this->indexCache ?? [] as $entry) {
            if (($entry['kind'] ?? '') === 'method') {
                $known[strtolower((string)$entry['name'])] = true;
            }
            if (($entry['kind'] ?? '') === 'class') {
                // Class names aren't methods — ignore.
            }
        }
        return $known;
    }

    private function renderGeneratedBlock(): string
    {
        $this->ensureIndex();
        $fwClasses = [];
        $userClasses = [];
        foreach ($this->indexCache ?? [] as $entry) {
            if (($entry['kind'] ?? '') !== 'class') {
                continue;
            }
            if (($entry['source'] ?? '') === 'framework') {
                $fwClasses[$entry['fqn']] = $entry;
            } elseif (($entry['source'] ?? '') === 'user') {
                $userClasses[$entry['fqn']] = $entry;
            }
        }
        ksort($fwClasses);
        ksort($userClasses);
        $methodCounts = [];
        foreach ($this->indexCache ?? [] as $entry) {
            if (($entry['kind'] ?? '') === 'method') {
                $cls = (string)($entry['class'] ?? '');
                $methodCounts[$cls] = ($methodCounts[$cls] ?? 0) + 1;
            }
        }
        $lines = [];
        $lines[] = '## Framework API';
        $lines[] = '';
        $lines[] = '| Class | Summary | Methods |';
        $lines[] = '|---|---|---|';
        foreach ($fwClasses as $fqn => $entry) {
            $summary = str_replace('|', '\\|', (string)($entry['summary'] ?? ''));
            $lines[] = sprintf('| %s | %s | %d |', $fqn, $summary, $methodCounts[$fqn] ?? 0);
        }
        if (!empty($userClasses)) {
            $lines[] = '';
            $lines[] = '## User Surface';
            $lines[] = '';
            $lines[] = '| Class | Summary | Methods |';
            $lines[] = '|---|---|---|';
            foreach ($userClasses as $fqn => $entry) {
                $summary = str_replace('|', '\\|', (string)($entry['summary'] ?? ''));
                $lines[] = sprintf('| %s | %s | %d |', $fqn, $summary, $methodCounts[$fqn] ?? 0);
            }
        }
        return implode("\n", $lines);
    }

    // ── Filesystem helpers ───────────────────────────────────────────

    /** @return string[] Absolute paths to .php files under the given root. */
    private function walkPhpFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
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
                    // Skip hidden and common noise dirs
                    if (str_starts_with($entry, '.')) continue;
                    if (in_array($entry, ['node_modules', 'tests', 'test'], true)) continue;
                    $stack[] = $full;
                    continue;
                }
                if (!str_ends_with($entry, '.php')) continue;
                $found[] = $full;
            }
            closedir($handle);
        }
        sort($found);
        return $found;
    }

    /** Relative path from project root (or framework root for framework files). */
    private function relativePath(string $absPath): string
    {
        $abs = str_replace('\\', '/', $absPath);
        $root = $this->projectRoot . '/';
        if (str_starts_with($abs, $root)) {
            return substr($abs, strlen($root));
        }
        // Framework root case — strip everything up to and including the parent of Tina4
        $fwParent = dirname($this->frameworkRoot) . '/';
        if (str_starts_with($abs, $fwParent)) {
            return substr($abs, strlen($fwParent));
        }
        return $abs;
    }

    private function maxMtime(array $dirs): int
    {
        $max = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->walkPhpFiles($dir) as $path) {
                $m = @filemtime($path);
                if ($m !== false && $m > $max) {
                    $max = $m;
                }
            }
        }
        return $max;
    }

    // ── Bootstrap helpers ────────────────────────────────────────────

    private function detectUserNamespace(): string
    {
        $composerPath = $this->projectRoot . '/composer.json';
        if (!is_file($composerPath)) {
            return '';
        }
        $data = json_decode((string) @file_get_contents($composerPath), true);
        if (!is_array($data)) {
            return '';
        }
        $psr4 = $data['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return '';
        }
        foreach ($psr4 as $ns => $path) {
            $ns = trim((string) $ns, '\\');
            if ($ns === '' || str_starts_with($ns, 'Tina4')) {
                continue;
            }
            return $ns;
        }
        return '';
    }

    private function detectVersion(): string
    {
        $composerPath = $this->projectRoot . '/composer.json';
        if (is_file($composerPath)) {
            $data = json_decode((string) @file_get_contents($composerPath), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string) $data['version'];
            }
        }
        // Framework composer
        $fwComposer = dirname($this->frameworkRoot) . '/composer.json';
        if (is_file($fwComposer)) {
            $data = json_decode((string) @file_get_contents($fwComposer), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string) $data['version'];
            }
        }
        return '0.0.0';
    }

    private function classKey(string $fqn): string
    {
        return ltrim($fqn, '\\');
    }

    private static function mcpInstance(): self
    {
        $root = getcwd() ?: __DIR__;
        if (!isset(self::$mcpInstances[$root])) {
            self::$mcpInstances[$root] = new self($root);
        }
        return self::$mcpInstances[$root];
    }
}
