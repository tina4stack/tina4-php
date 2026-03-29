<?php

namespace Tina4;

/**
 * Frond - Zero-dependency Twig-like template engine for Tina4.
 *
 * Supports: variables, filters, control structures (if/for/set/include/extends/block/macro/cache),
 * expression evaluation, template inheritance, auto-escaping, sandboxing, whitespace control, and comments.
 */
class Frond
{
    private string $templateDir;
    private array $globals = [];
    private array $filters = [];
    private array $tests = [];
    private array $cache = [];
    private bool $sandboxed = false;
    private ?array $sandboxFilters = null;
    private ?array $sandboxTags = null;
    private ?array $sandboxVars = null;
    private array $macros = [];
    /** @var array<string, array{tokens: array, ast: array, mtime: float}> Token pre-compilation cache for file templates */
    private array $compiled = [];
    /** @var array<string, array{tokens: array, ast: array}> Token pre-compilation cache for string templates */
    private array $compiledStrings = [];

    /**
     * Session ID used by formToken() for CSRF session binding.
     * Set this before rendering templates to bind tokens to the current session.
     */
    public static string $formTokenSessionId = '';

    // Sentinel for "raw" (no auto-escape)
    private const RAW_MARKER = "\x00FROND_RAW\x00";

    // Pre-compiled regex patterns
    private const RE_RAW_BLOCK = '/\{%-?\s*raw\s*-?%\}(.*?)\{%-?\s*endraw\s*-?%\}/s';
    private const RE_WHITESPACE_SPLIT = '/\s+/';
    private const RE_ELSEIF_PREFIX = '/^(elseif|elif)\s+/';
    private const RE_FOR_EXPR = '/^(.+?)\s+in\s+(.+)$/s';
    private const RE_SET_EXPR = '/^(\w+)\s*=\s*(.+)$/s';
    private const RE_IGNORE_MISSING = '/\s+ignore\s+missing\s*$/';
    private const RE_WITH_DATA = '/\s+with\s+(.+)$/s';
    private const RE_MACRO_SIG = '/^(\w+)\s*\(([^)]*)\)/';
    private const RE_FROM_IMPORT = '/^["\'](.+?)["\']\s+import\s+(.+)/';
    private const RE_SPACELESS = '/>\s+</';
    private const RE_NOT_PREFIX = '/^not\s+(.+)$/s';
    private const RE_IS_NOT_TEST = '/^(.+?)\s+is\s+not\s+(.+)$/s';
    private const RE_IS_TEST = '/^(.+?)\s+is\s+(.+)$/s';
    private const RE_RANGE = '/^(\d+)\.\.(\d+)$/';
    private const RE_FUNC_CALL = '/^([\w.]+)\s*\((.*)?\)$/s';
    private const RE_METHOD_CALL = '/^(\w+)\((.*)?\)$/s';
    private const RE_FILTER_WITH_ARGS = '/^(\w+)\s*\((.+)\)$/s';
    private const RE_FILTER_COMPARISON = '/^(\w+)\s*(!=|==|>=|<=|>|<)\s*(.+)$/';
    private const RE_DIVISIBLE_BY = '/^divisible\s*by\s*\(?\s*(.+?)\s*\)?$/';
    private const RE_DICT_PAIR = '/^(["\']?)(\w+)\1\s*:\s*(.+)$/s';
    private const RE_SLUG_STRIP = '/[^a-z0-9]+/';

    /** @var array<string, array> Cache for parsed filter chains keyed by expression string */
    private array $filterChainCache = [];

    /** @var array<string, string[]> Cache for simple dotted path splits (no brackets/parens) */
    private array $dottedSplitCache = [];

    public function __construct(string $templateDir = 'src/templates')
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->registerBuiltinFilters();
        $this->registerBuiltinTests();
        $this->registerBuiltinGlobals();
    }

    /* ───────────────────── public API ───────────────────── */

    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $file");
        }

        $debugMode = strtolower(getenv('TINA4_DEBUG') ?: '') === 'true';
        $cached = $this->compiled[$template] ?? null;

        if ($cached !== null) {
            if ($debugMode) {
                // Dev mode: check if file changed
                $mtime = filemtime($file);
                if ($cached['mtime'] === $mtime) {
                    $data = array_merge($this->globals, $data);
                    $ast = $this->resolveInheritance($cached['ast'], $data, $template);
                    return $this->execute($ast, $data);
                }
            } else {
                // Production: skip mtime check, cache is permanent
                $data = array_merge($this->globals, $data);
                $ast = $this->resolveInheritance($cached['ast'], $data, $template);
                return $this->execute($ast, $data);
            }
        }

        // Cache miss — load, tokenize, parse, cache
        $source = file_get_contents($file);
        $mtime = filemtime($file);
        $tokens = $this->tokenize($source);
        $ast = $this->parse($tokens);
        $this->compiled[$template] = ['tokens' => $tokens, 'ast' => $ast, 'mtime' => $mtime];

        $data = array_merge($this->globals, $data);
        $ast = $this->resolveInheritance($ast, $data, $template);
        return $this->execute($ast, $data);
    }

    public function renderString(string $source, array $data = [], ?string $templateName = null): string
    {
        $key = md5($source);
        $cached = $this->compiledStrings[$key] ?? null;

        if ($cached !== null) {
            $data = array_merge($this->globals, $data);
            $ast = $this->resolveInheritance($cached['ast'], $data, $templateName);
            return $this->execute($ast, $data);
        }

        $data = array_merge($this->globals, $data);
        $tokens = $this->tokenize($source);
        $ast = $this->parse($tokens);
        $this->compiledStrings[$key] = ['tokens' => $tokens, 'ast' => $ast];

        // Handle extends
        $ast = $this->resolveInheritance($ast, $data, $templateName);

        return $this->execute($ast, $data);
    }

    /**
     * Clear all compiled template caches.
     */
    public function clearCache(): void
    {
        $this->compiled = [];
        $this->compiledStrings = [];
        $this->filterChainCache = [];
        $this->dottedSplitCache = [];
    }

    public function addFilter(string $name, callable $fn): void
    {
        $this->filters[$name] = $fn;
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    /**
     * Get all registered filters (built-in + custom).
     *
     * @return array<string, callable>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Get all registered globals (built-in + custom).
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }

    public function addTest(string $name, callable $fn): void
    {
        $this->tests[$name] = $fn;
    }

    public function sandbox(?array $filters = null, ?array $tags = null, ?array $vars = null): self
    {
        $this->sandboxed = true;
        $this->sandboxFilters = $filters;
        $this->sandboxTags = $tags;
        $this->sandboxVars = $vars;
        return $this;
    }

    public function unsandbox(): self
    {
        $this->sandboxed = false;
        $this->sandboxFilters = null;
        $this->sandboxTags = null;
        $this->sandboxVars = null;
        return $this;
    }

    /* ───────────────────── tokenizer ───────────────────── */

    private function tokenize(string $source): array
    {
        // 1. Extract {% raw %}...{% endraw %} blocks before tokenizing
        $rawBlocks = [];
        $source = preg_replace_callback(
            self::RE_RAW_BLOCK,
            function ($m) use (&$rawBlocks) {
                $idx = count($rawBlocks);
                $rawBlocks[] = $m[1];
                return "\x00RAW_{$idx}\x00";
            },
            $source
        );

        $tokens = [];
        $pos = 0;
        $len = strlen($source);

        while ($pos < $len) {
            // Find the next opening tag
            $nextComment = strpos($source, '{#', $pos);
            $nextVar = strpos($source, '{{', $pos);
            $nextBlock = strpos($source, '{%', $pos);

            $candidates = [];
            if ($nextComment !== false) $candidates[$nextComment] = 'comment';
            if ($nextVar !== false) $candidates[$nextVar] = 'var';
            if ($nextBlock !== false) $candidates[$nextBlock] = 'block';

            if (empty($candidates)) {
                // Rest is text
                $tokens[] = ['type' => 'TEXT', 'value' => substr($source, $pos)];
                break;
            }

            ksort($candidates);
            $nextPos = array_key_first($candidates);
            $type = $candidates[$nextPos];

            // Text before tag
            if ($nextPos > $pos) {
                $tokens[] = ['type' => 'TEXT', 'value' => substr($source, $pos, $nextPos - $pos)];
            }

            if ($type === 'comment') {
                $end = strpos($source, '#}', $nextPos + 2);
                if ($end === false) {
                    $tokens[] = ['type' => 'TEXT', 'value' => substr($source, $nextPos)];
                    break;
                }
                // Check whitespace control
                $inner = substr($source, $nextPos + 2, $end - $nextPos - 2);
                $lstripComment = str_starts_with($inner, '-');
                $rstripComment = str_ends_with($inner, '-');
                $tokens[] = [
                    'type' => 'COMMENT',
                    'value' => $inner,
                    'lstrip' => $lstripComment,
                    'rstrip' => $rstripComment,
                ];
                $pos = $end + 2;
            } elseif ($type === 'var') {
                $end = strpos($source, '}}', $nextPos + 2);
                if ($end === false) {
                    $tokens[] = ['type' => 'TEXT', 'value' => substr($source, $nextPos)];
                    break;
                }
                $inner = substr($source, $nextPos + 2, $end - $nextPos - 2);
                $lstrip = str_starts_with($inner, '-');
                $rstrip = str_ends_with(rtrim($inner), '-');
                if ($lstrip) $inner = substr($inner, 1);
                if ($rstrip) $inner = substr(rtrim($inner), 0, -1);
                $tokens[] = [
                    'type' => 'VAR',
                    'value' => trim($inner),
                    'lstrip' => $lstrip,
                    'rstrip' => $rstrip,
                ];
                $pos = $end + 2;
            } elseif ($type === 'block') {
                $end = strpos($source, '%}', $nextPos + 2);
                if ($end === false) {
                    $tokens[] = ['type' => 'TEXT', 'value' => substr($source, $nextPos)];
                    break;
                }
                $inner = substr($source, $nextPos + 2, $end - $nextPos - 2);
                $lstrip = str_starts_with($inner, '-');
                $rstrip = str_ends_with(rtrim($inner), '-');
                if ($lstrip) $inner = substr($inner, 1);
                if ($rstrip) $inner = substr(rtrim($inner), 0, -1);
                $tokens[] = [
                    'type' => 'BLOCK',
                    'value' => trim($inner),
                    'lstrip' => $lstrip,
                    'rstrip' => $rstrip,
                ];
                $pos = $end + 2;
            }
        }

        // Restore raw block placeholders as literal TEXT
        if (!empty($rawBlocks)) {
            foreach ($tokens as &$token) {
                if ($token['type'] === 'TEXT' && str_contains($token['value'], "\x00RAW_")) {
                    foreach ($rawBlocks as $idx => $content) {
                        $token['value'] = str_replace("\x00RAW_{$idx}\x00", $content, $token['value']);
                    }
                }
            }
            unset($token);
        }

        // Apply whitespace control
        $this->applyWhitespaceControl($tokens);

        return $tokens;
    }

    private function applyWhitespaceControl(array &$tokens): void
    {
        for ($i = 0; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            if ($t['type'] === 'TEXT') continue;

            // lstrip: remove trailing whitespace from previous TEXT token
            if (!empty($t['lstrip']) && $i > 0 && $tokens[$i - 1]['type'] === 'TEXT') {
                $tokens[$i - 1]['value'] = rtrim($tokens[$i - 1]['value']);
            }
            // rstrip: remove leading whitespace from next TEXT token
            if (!empty($t['rstrip']) && $i + 1 < count($tokens) && $tokens[$i + 1]['type'] === 'TEXT') {
                $tokens[$i + 1]['value'] = ltrim($tokens[$i + 1]['value']);
            }
        }
    }

    /* ───────────────────── parser ───────────────────── */

    private function parse(array $tokens, int &$pos = 0, ?string $until = null, ?array $untilAny = null): array
    {
        $nodes = [];
        $count = count($tokens);

        while ($pos < $count) {
            $token = $tokens[$pos];

            if ($token['type'] === 'TEXT') {
                if ($token['value'] !== '') {
                    $nodes[] = ['type' => 'text', 'value' => $token['value']];
                }
                $pos++;
            } elseif ($token['type'] === 'COMMENT') {
                $pos++;
            } elseif ($token['type'] === 'VAR') {
                $nodes[] = ['type' => 'output', 'expr' => $token['value']];
                $pos++;
            } elseif ($token['type'] === 'BLOCK') {
                $tag = $token['value'];

                // Check if this is an end/else tag we're looking for
                if ($until !== null && $this->tagMatches($tag, $until)) {
                    return $nodes;
                }
                if ($untilAny !== null) {
                    foreach ($untilAny as $u) {
                        if ($this->tagMatches($tag, $u)) {
                            return $nodes;
                        }
                    }
                }

                $node = $this->parseBlock($tag, $tokens, $pos);
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    private function tagMatches(string $tag, string $expected): bool
    {
        $tag = trim($tag);
        if ($tag === $expected) return true;
        // Also match tag names that start with expected keyword
        return str_starts_with($tag, $expected . ' ') || str_starts_with($tag, $expected . '(');
    }

    private function parseBlock(string $tag, array &$tokens, int &$pos): ?array
    {
        $tagParts = preg_split(self::RE_WHITESPACE_SPLIT, trim($tag), 2);
        $keyword = $tagParts[0];
        $rest = $tagParts[1] ?? '';

        switch ($keyword) {
            case 'if':
                return $this->parseIf($rest, $tokens, $pos);
            case 'for':
                return $this->parseFor($rest, $tokens, $pos);
            case 'set':
                $pos++;
                return $this->parseSet($rest);
            case 'include':
                $pos++;
                return $this->parseInclude($rest);
            case 'extends':
                $pos++;
                return $this->parseExtends($rest);
            case 'block':
                return $this->parseBlockDef($rest, $tokens, $pos);
            case 'macro':
                return $this->parseMacro($rest, $tokens, $pos);
            case 'from':
                $pos++;
                return $this->parseFromImport($rest);
            case 'cache':
                return $this->parseCache($rest, $tokens, $pos);
            case 'spaceless':
                return $this->parseSpaceless($tokens, $pos);
            case 'autoescape':
                return $this->parseAutoescape($rest, $tokens, $pos);
            default:
                $pos++;
                return null;
        }
    }

    private function parseIf(string $condition, array &$tokens, int &$pos): array
    {
        $pos++; // skip {% if %}
        $branches = [];

        // Parse the if body until elseif/elif/else/endif
        $body = $this->parse($tokens, $pos, null, ['elseif', 'elif', 'else', 'endif']);

        $branches[] = ['condition' => $condition, 'body' => $body];

        while ($pos < count($tokens)) {
            $tag = trim($tokens[$pos]['value'] ?? '');
            if ($this->tagMatches($tag, 'endif')) {
                $pos++;
                break;
            } elseif ($this->tagMatches($tag, 'elseif') || $this->tagMatches($tag, 'elif')) {
                $cond = preg_replace(self::RE_ELSEIF_PREFIX, '', $tag);
                $pos++;
                $body = $this->parse($tokens, $pos, null, ['elseif', 'elif', 'else', 'endif']);
                $branches[] = ['condition' => $cond, 'body' => $body];
            } elseif ($this->tagMatches($tag, 'else')) {
                $pos++;
                $body = $this->parse($tokens, $pos, 'endif');
                $branches[] = ['condition' => 'true', 'body' => $body];
                $pos++; // skip endif
                break;
            } else {
                $pos++;
                break;
            }
        }

        return ['type' => 'if', 'branches' => $branches];
    }

    private function parseFor(string $expr, array &$tokens, int &$pos): array
    {
        $pos++; // skip {% for %}

        // Parse "key, value in iterable" or "value in iterable"
        if (!preg_match(self::RE_FOR_EXPR, $expr, $m)) {
            // fallback
            $body = $this->parse($tokens, $pos, 'endfor');
            $pos++;
            return ['type' => 'text', 'value' => ''];
        }

        $varPart = trim($m[1]);
        $iterExpr = trim($m[2]);
        $keyVar = null;
        $valueVar = $varPart;

        if (str_contains($varPart, ',')) {
            $parts = array_map('trim', explode(',', $varPart, 2));
            $keyVar = $parts[0];
            $valueVar = $parts[1];
        }

        // Parse body, looking for else or endfor
        $body = $this->parse($tokens, $pos, null, ['else', 'endfor']);
        $elseBody = null;

        if ($pos < count($tokens)) {
            $tag = trim($tokens[$pos]['value'] ?? '');
            if ($this->tagMatches($tag, 'else')) {
                $pos++;
                $elseBody = $this->parse($tokens, $pos, 'endfor');
            }
            // skip endfor
            $pos++;
        }

        return [
            'type' => 'for',
            'keyVar' => $keyVar,
            'valueVar' => $valueVar,
            'iterExpr' => $iterExpr,
            'body' => $body,
            'elseBody' => $elseBody,
        ];
    }

    private function parseSet(string $expr): array
    {
        // set name = expression
        if (preg_match(self::RE_SET_EXPR, $expr, $m)) {
            return ['type' => 'set', 'name' => $m[1], 'expr' => trim($m[2])];
        }
        return ['type' => 'text', 'value' => ''];
    }

    private function parseInclude(string $expr): array
    {
        // include "file.html" [with {data}] [ignore missing]
        $ignoreMissing = false;
        if (preg_match(self::RE_IGNORE_MISSING, $expr)) {
            $ignoreMissing = true;
            $expr = preg_replace(self::RE_IGNORE_MISSING, '', $expr);
        }

        $withData = null;
        if (preg_match(self::RE_WITH_DATA, $expr, $m)) {
            $withData = trim($m[1]);
            $expr = preg_replace(self::RE_WITH_DATA, '', $expr);
        }

        $file = trim($expr, " \t\n\r\"'");
        return [
            'type' => 'include',
            'file' => $file,
            'withData' => $withData,
            'ignoreMissing' => $ignoreMissing,
        ];
    }

    private function parseExtends(string $expr): array
    {
        $file = trim($expr, " \t\n\r\"'");
        return ['type' => 'extends', 'file' => $file];
    }

    private function parseBlockDef(string $name, array &$tokens, int &$pos): array
    {
        $name = trim($name);
        $pos++; // skip {% block name %}
        $body = $this->parse($tokens, $pos, 'endblock');
        $pos++; // skip endblock
        return ['type' => 'block', 'name' => $name, 'body' => $body];
    }

    private function parseMacro(string $sig, array &$tokens, int &$pos): array
    {
        $pos++; // skip {% macro %}
        // Parse "name(arg1, arg2)"
        if (!preg_match(self::RE_MACRO_SIG, $sig, $m)) {
            $body = $this->parse($tokens, $pos, 'endmacro');
            $pos++;
            return ['type' => 'text', 'value' => ''];
        }
        $name = $m[1];
        $args = array_map('trim', $m[2] !== '' ? explode(',', $m[2]) : []);
        $body = $this->parse($tokens, $pos, 'endmacro');
        $pos++;
        return ['type' => 'macro', 'name' => $name, 'args' => $args, 'body' => $body];
    }

    private function parseFromImport(string $rest): array
    {
        // Parse: "file" import name1, name2
        if (!preg_match(self::RE_FROM_IMPORT, $rest, $m)) {
            return ['type' => 'text', 'value' => ''];
        }
        $file = $m[1];
        $names = array_map('trim', explode(',', $m[2]));
        return ['type' => 'from_import', 'file' => $file, 'names' => $names];
    }

    private function parseCache(string $params, array &$tokens, int &$pos): array
    {
        $pos++;
        // Parse "key" ttl
        $parts = preg_split(self::RE_WHITESPACE_SPLIT, trim($params), 2);
        $key = trim($parts[0], "\"'");
        $ttl = isset($parts[1]) ? (int)$parts[1] : 0;
        $body = $this->parse($tokens, $pos, 'endcache');
        $pos++;
        return ['type' => 'cache', 'key' => $key, 'ttl' => $ttl, 'body' => $body];
    }

    private function parseSpaceless(array &$tokens, int &$pos): array
    {
        $pos++;
        $body = $this->parse($tokens, $pos, 'endspaceless');
        $pos++;
        return ['type' => 'spaceless', 'body' => $body];
    }

    private function parseAutoescape(string $params, array &$tokens, int &$pos): array
    {
        $pos++;
        $mode = trim($params) === 'false' ? false : true;
        $body = $this->parse($tokens, $pos, 'endautoescape');
        $pos++;
        return ['type' => 'autoescape', 'mode' => $mode, 'body' => $body];
    }

    /* ───────────────────── template inheritance ───────────────────── */

    private function resolveInheritance(array $ast, array &$data, ?string $templateName): array
    {
        // Find extends node
        $extendsNode = null;
        foreach ($ast as $node) {
            if ($node['type'] === 'extends') {
                $extendsNode = $node;
                break;
            }
        }

        if ($extendsNode === null) return $ast;

        // Collect child blocks
        $childBlocks = $this->collectBlocks($ast);

        // Load parent
        $parentFile = $this->templateDir . '/' . $extendsNode['file'];
        if (!is_file($parentFile)) {
            throw new \RuntimeException("Parent template not found: $parentFile");
        }
        $parentSource = file_get_contents($parentFile);
        $parentTokens = $this->tokenize($parentSource);
        $parentPos = 0;
        $parentAst = $this->parse($parentTokens, $parentPos);

        // Recursively resolve parent inheritance
        $parentAst = $this->resolveInheritance($parentAst, $data, $extendsNode['file']);

        // Replace parent blocks with child blocks
        return $this->replaceBlocks($parentAst, $childBlocks);
    }

    private function collectBlocks(array $ast): array
    {
        $blocks = [];
        foreach ($ast as $node) {
            if ($node['type'] === 'block') {
                $blocks[$node['name']] = $node['body'];
            }
        }
        return $blocks;
    }

    private function replaceBlocks(array $ast, array $childBlocks): array
    {
        $result = [];
        foreach ($ast as $node) {
            if ($node['type'] === 'block') {
                if (isset($childBlocks[$node['name']])) {
                    $node['body'] = $this->replaceBlocks($childBlocks[$node['name']], $childBlocks);
                } else {
                    $node['body'] = $this->replaceBlocks($node['body'], $childBlocks);
                }
            }
            // Also recurse into if/for branches
            if ($node['type'] === 'if') {
                foreach ($node['branches'] as &$branch) {
                    $branch['body'] = $this->replaceBlocks($branch['body'], $childBlocks);
                }
                unset($branch);
            }
            if ($node['type'] === 'for') {
                $node['body'] = $this->replaceBlocks($node['body'], $childBlocks);
                if (!empty($node['elseBody'])) {
                    $node['elseBody'] = $this->replaceBlocks($node['elseBody'], $childBlocks);
                }
            }
            $result[] = $node;
        }
        return $result;
    }

    /* ───────────────────── executor ───────────────────── */

    private function execute(array $ast, array &$data): string
    {
        $out = '';
        foreach ($ast as $node) {
            $out .= $this->executeNode($node, $data);
        }
        return $out;
    }

    private function executeNode(array $node, array &$data): string
    {
        switch ($node['type']) {
            case 'text':
                return $node['value'];

            case 'output':
                return $this->executeOutput($node['expr'], $data);

            case 'if':
                return $this->executeIf($node, $data);

            case 'for':
                return $this->executeFor($node, $data);

            case 'set':
                return $this->executeSet($node, $data);

            case 'include':
                return $this->executeInclude($node, $data);

            case 'block':
                return $this->execute($node['body'], $data);

            case 'macro':
                $this->macros[$node['name']] = $node;
                return '';

            case 'from_import':
                $this->executeFromImport($node, $data);
                return '';

            case 'cache':
                return $this->executeCache($node, $data);

            case 'spaceless':
                return $this->executeSpaceless($node, $data);

            case 'autoescape':
                return $this->executeAutoescape($node, $data);

            default:
                return '';
        }
    }

    private function executeOutput(string $expr, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('output', $this->sandboxTags) && !in_array('var', $this->sandboxTags)) {
            // output is always allowed unless tags explicitly exclude it
        }

        $value = $this->evaluateExpression($expr, $data);
        $isRaw = false;

        if (is_string($value) && str_contains($value, self::RAW_MARKER)) {
            $value = str_replace(self::RAW_MARKER, '', $value);
            $isRaw = true;
        }

        $str = $this->valueToString($value);

        // Auto-escape unless raw
        if (!$isRaw) {
            $str = htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $str;
    }

    private function valueToString(mixed $value): string
    {
        if ($value === null) return '';
        if ($value === true) return '1';
        if ($value === false) return '';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        return (string)$value;
    }

    private function executeIf(array $node, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('if', $this->sandboxTags)) {
            return '';
        }
        foreach ($node['branches'] as $branch) {
            $condValue = $this->evaluateExpression($branch['condition'], $data);
            if ($this->isTruthy($condValue)) {
                return $this->execute($branch['body'], $data);
            }
        }
        return '';
    }

    private function executeFor(array $node, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('for', $this->sandboxTags)) {
            return '';
        }

        $iterable = $this->evaluateExpression($node['iterExpr'], $data);

        if (!is_iterable($iterable) || (is_array($iterable) && empty($iterable))) {
            if ($node['elseBody'] !== null) {
                return $this->execute($node['elseBody'], $data);
            }
            return '';
        }

        // Convert to array for counting
        $items = is_array($iterable) ? $iterable : iterator_to_array($iterable);
        if (empty($items)) {
            if ($node['elseBody'] !== null) {
                return $this->execute($node['elseBody'], $data);
            }
            return '';
        }

        $out = '';
        $length = count($items);
        $idx = 0;

        // Save any keys we'll overwrite so we can restore after the loop
        $valueVar = $node['valueVar'];
        $keyVar = $node['keyVar'];
        $savedValue = array_key_exists($valueVar, $data) ? $data[$valueVar] : null;
        $hadValue = array_key_exists($valueVar, $data);
        $savedKey = ($keyVar !== null && array_key_exists($keyVar, $data)) ? $data[$keyVar] : null;
        $hadKey = $keyVar !== null && array_key_exists($keyVar, $data);
        $savedLoop = array_key_exists('loop', $data) ? $data['loop'] : null;
        $hadLoop = array_key_exists('loop', $data);

        foreach ($items as $key => $value) {
            // Overlay only the needed keys directly on $data instead of copying
            $data[$valueVar] = $value;
            if ($keyVar !== null) {
                $data[$keyVar] = $key;
            }
            $data['loop'] = [
                'index' => $idx + 1,
                'index0' => $idx,
                'first' => $idx === 0,
                'last' => $idx === $length - 1,
                'length' => $length,
                'revindex' => $length - $idx,
                'revindex0' => $length - $idx - 1,
                'even' => ($idx + 1) % 2 === 0,
                'odd' => ($idx + 1) % 2 === 1,
            ];
            $out .= $this->execute($node['body'], $data);
            $idx++;
        }

        // Restore original context — variables set in for don't leak out (standard Twig behavior)
        if ($hadValue) { $data[$valueVar] = $savedValue; } else { unset($data[$valueVar]); }
        if ($keyVar !== null) {
            if ($hadKey) { $data[$keyVar] = $savedKey; } else { unset($data[$keyVar]); }
        }
        if ($hadLoop) { $data['loop'] = $savedLoop; } else { unset($data['loop']); }

        return $out;
    }

    private function executeSet(array $node, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('set', $this->sandboxTags)) {
            return '';
        }
        $value = $this->evaluateExpression($node['expr'], $data);
        // Strip raw marker from set values
        if (is_string($value) && str_contains($value, self::RAW_MARKER)) {
            $value = str_replace(self::RAW_MARKER, '', $value);
        }
        $data[$node['name']] = $value;
        return '';
    }

    private function executeInclude(array $node, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('include', $this->sandboxTags)) {
            return '';
        }

        $file = $this->templateDir . '/' . $node['file'];
        if (!is_file($file)) {
            if ($node['ignoreMissing']) return '';
            throw new \RuntimeException("Include template not found: $file");
        }

        $includeData = $data;
        if ($node['withData'] !== null) {
            $extra = $this->evaluateExpression($node['withData'], $data);
            if (is_array($extra)) {
                $includeData = array_merge($includeData, $extra);
            }
        }

        $source = file_get_contents($file);
        $tokens = $this->tokenize($source);
        $tpos = 0;
        $ast = $this->parse($tokens, $tpos);
        return $this->execute($ast, $includeData);
    }

    private function executeCache(array $node, array &$data): string
    {
        if ($this->sandboxed && $this->sandboxTags !== null && !in_array('cache', $this->sandboxTags)) {
            return '';
        }

        $key = $node['key'];
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key];
            if ($entry['ttl'] === 0 || (time() - $entry['time']) < $entry['ttl']) {
                return $entry['content'];
            }
        }

        $content = $this->execute($node['body'], $data);
        $this->cache[$key] = [
            'content' => $content,
            'time' => time(),
            'ttl' => $node['ttl'],
        ];
        return $content;
    }

    private function executeSpaceless(array $node, array &$data): string
    {
        $rendered = $this->execute($node['body'], $data);
        return preg_replace(self::RE_SPACELESS, '><', $rendered);
    }

    private function executeAutoescape(array $node, array &$data): string
    {
        if (!$node['mode']) {
            // Temporarily wrap executeOutput to skip escaping
            $rendered = $this->executeNoEscape($node['body'], $data);
        } else {
            $rendered = $this->execute($node['body'], $data);
        }
        return $rendered;
    }

    private function executeNoEscape(array $ast, array &$data): string
    {
        $out = '';
        foreach ($ast as $node) {
            if ($node['type'] === 'output') {
                // Evaluate without escaping
                $value = $this->evaluateExpression($node['expr'], $data);
                if (is_string($value) && str_contains($value, self::RAW_MARKER)) {
                    $value = str_replace(self::RAW_MARKER, '', $value);
                }
                $out .= $this->valueToString($value);
            } else {
                $out .= $this->executeNode($node, $data);
            }
        }
        return $out;
    }

    /* ───────────────────── expression evaluator ───────────────────── */

    private function evaluateExpression(string $expr, array &$data): mixed
    {
        $expr = trim($expr);
        if ($expr === '') return '';
        if ($expr === 'true') return true;
        if ($expr === 'false') return false;
        if ($expr === 'none' || $expr === 'null') return null;

        // String literal early-return: if the whole expression is a single
        // quoted string with no matching quote inside, return the inner text
        // immediately.  This prevents operator checks (~, ??, comparisons)
        // from mismatching characters that appear inside the string.
        if (strlen($expr) >= 2) {
            $q = $expr[0];
            if (($q === '"' || $q === "'") && str_ends_with($expr, $q) && !str_contains(substr($expr, 1, -1), $q)) {
                $inner = substr($expr, 1, -1);
                $inner = $this->processEscapes($inner);
                return $inner;
            }
        }

        // Parenthesized sub-expression: (expr) — strip parens and evaluate inner
        if (strlen($expr) >= 2 && $expr[0] === '(' && str_ends_with($expr, ')')) {
            $depth = 0;
            $matched = true;
            for ($pi = 0; $pi < strlen($expr); $pi++) {
                if ($expr[$pi] === '(') $depth++;
                elseif ($expr[$pi] === ')') $depth--;
                if ($depth === 0 && $pi < strlen($expr) - 1) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $this->evaluateExpression(substr($expr, 1, -1), $data);
            }
        }

        // Ternary MUST be checked before filter pipes so that expressions
        // like ``products|length != 1 ? "s" : ""`` are parsed correctly.
        // The ``?`` belongs to the ternary, not to a filter.
        $ternaryPos = $this->findTernary($expr);
        if ($ternaryPos !== false) {
            $condition = trim(substr($expr, 0, $ternaryPos));
            $rest = substr($expr, $ternaryPos + 1);
            $colonPos = $this->findTernaryColon($rest);
            if ($colonPos !== false) {
                $trueVal = trim(substr($rest, 0, $colonPos));
                $falseVal = trim(substr($rest, $colonPos + 1));
                $condResult = $this->evaluateExpression($condition, $data);
                if ($this->isTruthy($condResult)) {
                    return $this->evaluateExpression($trueVal, $data);
                } else {
                    return $this->evaluateExpression($falseVal, $data);
                }
            }
        }

        // Jinja2-style inline if: value if condition else other_value — quote-aware
        $ifPos = $this->findOutsideQuotes($expr, ' if ');
        if ($ifPos !== false) {
            $elsePos = $this->findOutsideQuotes($expr, ' else ');
            if ($elsePos !== false && $elsePos > $ifPos) {
                $valuePart = trim(substr($expr, 0, $ifPos));
                $condPart = trim(substr($expr, $ifPos + 4, $elsePos - $ifPos - 4));
                $elsePart = trim(substr($expr, $elsePos + 6));
                $condResult = $this->evaluateExpression($condPart, $data);
                if ($this->isTruthy($condResult)) {
                    return $this->evaluateExpression($valuePart, $data);
                }
                return $this->evaluateExpression($elsePart, $data);
            }
        }

        // Null coalescing: value ?? default
        $coalPos = $this->findOutsideQuotes($expr, '??');
        if ($coalPos !== false) {
            $left = trim(substr($expr, 0, $coalPos));
            $right = trim(substr($expr, $coalPos + 2));
            $leftVal = $this->evaluateExpressionSafe($left, $data);
            if ($leftVal !== null) return $leftVal;
            return $this->evaluateExpression($right, $data);
        }

        // Logical: or
        $orPos = $this->findLogicalOp($expr, ' or ');
        if ($orPos !== false) {
            $left = trim(substr($expr, 0, $orPos));
            $right = trim(substr($expr, $orPos + 4));
            return $this->isTruthy($this->evaluateExpression($left, $data))
                || $this->isTruthy($this->evaluateExpression($right, $data));
        }

        // Logical: and
        $andPos = $this->findLogicalOp($expr, ' and ');
        if ($andPos !== false) {
            $left = trim(substr($expr, 0, $andPos));
            $right = trim(substr($expr, $andPos + 5));
            return $this->isTruthy($this->evaluateExpression($left, $data))
                && $this->isTruthy($this->evaluateExpression($right, $data));
        }

        // not prefix
        if (preg_match(self::RE_NOT_PREFIX, $expr, $m)) {
            return !$this->isTruthy($this->evaluateExpression($m[1], $data));
        }

        // Comparisons: ==, !=, <=, >=, <, >, in, not in
        // "not in" check
        $notInPos = $this->findLogicalOp($expr, ' not in ');
        if ($notInPos !== false) {
            $left = trim(substr($expr, 0, $notInPos));
            $right = trim(substr($expr, $notInPos + 8));
            $leftVal = $this->evaluateExpression($left, $data);
            $rightVal = $this->evaluateExpression($right, $data);
            return !$this->checkIn($leftVal, $rightVal);
        }

        // "in" check
        $inPos = $this->findLogicalOp($expr, ' in ');
        if ($inPos !== false) {
            $left = trim(substr($expr, 0, $inPos));
            $right = trim(substr($expr, $inPos + 4));
            $leftVal = $this->evaluateExpression($left, $data);
            $rightVal = $this->evaluateExpression($right, $data);
            return $this->checkIn($leftVal, $rightVal);
        }

        // "is not" tests
        if (preg_match(self::RE_IS_NOT_TEST, $expr, $m)) {
            return !$this->evaluateTest(trim($m[1]), trim($m[2]), $data);
        }

        // "is" tests
        if (preg_match(self::RE_IS_TEST, $expr, $m)) {
            $testName = trim($m[2]);
            // Make sure this isn't a variable that happens to contain "is"
            if ($this->isKnownTest($testName)) {
                return $this->evaluateTest(trim($m[1]), $testName, $data);
            }
        }

        // Comparison operators (guard: only check if op appears outside quotes)
        foreach (['!=', '==', '<=', '>=', '<', '>'] as $op) {
            if ($this->findOutsideQuotes($expr, $op) === false) continue;
            $opPos = $this->findComparisonOp($expr, $op);
            if ($opPos !== false) {
                $left = trim(substr($expr, 0, $opPos));
                $right = trim(substr($expr, $opPos + strlen($op)));
                $leftVal = $this->evaluateExpression($left, $data);
                $rightVal = $this->evaluateExpression($right, $data);
                return match($op) {
                    '==' => $leftVal == $rightVal,
                    '!=' => $leftVal != $rightVal,
                    '<' => $leftVal < $rightVal,
                    '>' => $leftVal > $rightVal,
                    '<=' => $leftVal <= $rightVal,
                    '>=' => $leftVal >= $rightVal,
                };
            }
        }

        // Check for filter pipe (respecting strings and parens) — cached
        // NOTE: Filter pipes are checked AFTER logical/comparison operators so
        // that expressions like ``items|length > 0 and name|upper == "ALICE"``
        // are split on ``and`` first, then each sub-expression processes its
        // own filter pipes via recursive evaluateExpression calls.
        $filterSplit = $this->filterChainCache[$expr] ?? null;
        if ($filterSplit === null) {
            $filterSplit = $this->splitFilters($expr);
            $this->filterChainCache[$expr] = $filterSplit;
        }
        if (count($filterSplit) > 1) {
            $value = $this->evaluateExpression($filterSplit[0], $data);
            for ($i = 1, $cnt = count($filterSplit); $i < $cnt; $i++) {
                $value = $this->applyFilter(trim($filterSplit[$i]), $value, $data);
            }
            return $value;
        }

        // String concatenation with ~
        $tildePos = $this->findOutsideQuotes($expr, '~');
        if ($tildePos !== false) {
            $parts = $this->splitOutsideQuotes($expr, '~');
            $result = '';
            foreach ($parts as $part) {
                $val = $this->evaluateExpression(trim($part), $data);
                $str = $this->valueToString($val);
                if (is_string($val) && str_contains($val, self::RAW_MARKER)) {
                    $str = str_replace(self::RAW_MARKER, '', $str);
                }
                $result .= $str;
            }
            return $result;
        }

        // Math: +, -, *, //, /, %
        foreach ([['+', '-'], ['*', '//', '/', '%']] as $ops) {
            foreach ($ops as $op) {
                $opPos = $this->findMathOp($expr, $op);
                if ($opPos !== false) {
                    $left = trim(substr($expr, 0, $opPos));
                    $right = trim(substr($expr, $opPos + strlen($op)));
                    $leftVal = $this->evaluateExpression($left, $data);
                    $rightVal = $this->evaluateExpression($right, $data);
                    if (is_numeric($leftVal) && is_numeric($rightVal)) {
                        return match($op) {
                            '+' => $leftVal + $rightVal,
                            '-' => $leftVal - $rightVal,
                            '*' => $leftVal * $rightVal,
                            '/' => $rightVal != 0 ? $leftVal / $rightVal : 0,
                            '//' => $rightVal != 0 ? intdiv((int)$leftVal, (int)$rightVal) : 0,
                            '%' => $rightVal != 0 ? $leftVal % $rightVal : 0,
                        };
                    }
                    // + can also concatenate arrays
                    if ($op === '+' && is_array($leftVal) && is_array($rightVal)) {
                        return array_merge($leftVal, $rightVal);
                    }
                    return match($op) {
                        '+' => ($leftVal ?? 0) + ($rightVal ?? 0),
                        '-' => ($leftVal ?? 0) - ($rightVal ?? 0),
                        '*' => ($leftVal ?? 0) * ($rightVal ?? 0),
                        '/' => ($rightVal ?? 0) != 0 ? ($leftVal ?? 0) / $rightVal : 0,
                        '//' => ($rightVal ?? 0) != 0 ? intdiv((int)($leftVal ?? 0), (int)$rightVal) : 0,
                        '%' => ($rightVal ?? 0) != 0 ? ($leftVal ?? 0) % $rightVal : 0,
                    };
                }
            }
        }

        // Parenthesized expression
        if (str_starts_with($expr, '(') && $this->findMatchingParen($expr, 0) === strlen($expr) - 1) {
            return $this->evaluateExpression(substr($expr, 1, -1), $data);
        }

        // Numeric literal
        if (is_numeric($expr)) {
            return str_contains($expr, '.') ? (float)$expr : (int)$expr;
        }

        // String literal
        if ((str_starts_with($expr, '"') && str_ends_with($expr, '"'))
            || (str_starts_with($expr, "'") && str_ends_with($expr, "'"))) {
            $inner = substr($expr, 1, -1);
            // Handle escape sequences
            $inner = str_replace(['\\n', '\\t', '\\\\', "\\'", '\\"'], ["\n", "\t", "\\", "'", '"'], $inner);
            return $inner;
        }

        // Array/dict literal: [a, b, c] or {key: value}
        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return $this->parseArrayLiteral(substr($expr, 1, -1), $data);
        }
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}')) {
            return $this->parseDictLiteral(substr($expr, 1, -1), $data);
        }

        // Range: start..end
        if (preg_match(self::RE_RANGE, $expr, $m)) {
            return range((int)$m[1], (int)$m[2]);
        }

        // Macro or function call: name(args) — supports dotted names like user.t("key")
        if (preg_match(self::RE_FUNC_CALL, $expr, $m)) {
            $funcName = $m[1];
            $argsStr = $m[2] ?? '';

            // Dotted function name: resolve object, then call method
            if (str_contains($funcName, '.')) {
                $lastDot = strrpos($funcName, '.');
                $objPath = substr($funcName, 0, $lastDot);
                $methodName = substr($funcName, $lastDot + 1);
                $obj = $this->resolveVariable($objPath, $data);
                $args = trim($argsStr) !== '' ? $this->parseFilterArgs($argsStr, $data) : [];
                if (is_array($obj) && isset($obj[$methodName]) && is_callable($obj[$methodName])) {
                    return ($obj[$methodName])(...$args);
                }
                if (is_object($obj) && method_exists($obj, $methodName)) {
                    return $obj->$methodName(...$args);
                }
                return '';
            }

            if (isset($this->macros[$funcName])) {
                return $this->callMacro($funcName, $argsStr, $data);
            }
            // Check globals for callable functions (e.g. formToken, form_token)
            if (isset($data[$funcName]) && is_callable($data[$funcName])) {
                $args = trim($argsStr) !== '' ? $this->parseFilterArgs($argsStr, $data) : [];
                return ($data[$funcName])(...$args);
            }
            // Could be range() or other built-in
            if ($funcName === 'range') {
                $args = $this->parseArgs($argsStr, $data);
                if (count($args) >= 2) {
                    $step = $args[2] ?? 1;
                    return range($args[0], $args[1], $step);
                }
            }
        }

        // Variable resolution
        return $this->resolveVariable($expr, $data);
    }

    private function evaluateExpressionSafe(string $expr, array &$data): mixed
    {
        try {
            $val = $this->evaluateExpression($expr, $data);
            // Check if the variable was actually defined
            $varName = trim($expr);
            if (!str_contains($varName, ' ') && !str_contains($varName, '"') && !str_contains($varName, "'")) {
                $parts = explode('.', $varName);
                $root = $parts[0];
                if (str_contains($root, '[')) {
                    $root = substr($root, 0, strpos($root, '['));
                }
                if (!array_key_exists($root, $data)) {
                    return null;
                }
            }
            return $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveVariable(string $expr, array &$data): mixed
    {
        $expr = trim($expr);

        // Sandbox check
        if ($this->sandboxed && $this->sandboxVars !== null) {
            $rootName = $expr;
            if (str_contains($rootName, '.')) $rootName = substr($rootName, 0, strpos($rootName, '.'));
            if (str_contains($rootName, '[')) $rootName = substr($rootName, 0, strpos($rootName, '['));
            if ($rootName !== 'loop' && !in_array($rootName, $this->sandboxVars)) {
                return '';
            }
        }

        // Handle dotted paths and bracket access
        // First tokenize the path: split on dots and brackets
        $segments = $this->parseVarPath($expr, $data);
        if (empty($segments)) return '';

        $current = $data;
        foreach ($segments as $seg) {
            // Check if segment is a method call like name(args)
            if (is_string($seg) && preg_match(self::RE_METHOD_CALL, $seg, $mc)) {
                $methodName = $mc[1];
                $argsStr = $mc[2] ?? '';
                $args = trim($argsStr) !== '' ? $this->parseFilterArgs($argsStr, $data) : [];

                if (is_array($current) && isset($current[$methodName]) && is_callable($current[$methodName])) {
                    $current = ($current[$methodName])(...$args);
                } elseif (is_object($current) && method_exists($current, $methodName)) {
                    $current = $current->$methodName(...$args);
                } else {
                    return '';
                }
            } elseif (is_array($current)) {
                if (array_key_exists($seg, $current)) {
                    $current = $current[$seg];
                } else {
                    return '';
                }
            } elseif (is_object($current)) {
                if (property_exists($current, $seg)) {
                    $current = $current->$seg;
                } elseif (method_exists($current, $seg)) {
                    $current = $current->$seg();
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        return $current;
    }

    private function parseVarPath(string $expr, array &$data = []): array
    {
        // Fast path: simple dotted paths (no brackets or parens) can be cached
        if (!str_contains($expr, '[') && !str_contains($expr, '(')) {
            if (isset($this->dottedSplitCache[$expr])) {
                return $this->dottedSplitCache[$expr];
            }
            $segments = explode('.', $expr);
            $this->dottedSplitCache[$expr] = $segments;
            return $segments;
        }

        $segments = [];
        $len = strlen($expr);
        $pos = 0;
        $current = '';

        while ($pos < $len) {
            $ch = $expr[$pos];

            if ($ch === '(') {
                // Consume everything up to and including the matching ')'
                // respecting quotes so that ')' or '.' inside strings are not
                // mistaken for structural characters.
                $depth = 1;
                $current .= $ch;
                $pos++;
                $inQuote = false;
                $quoteChar = '';
                while ($pos < $len && $depth > 0) {
                    $c = $expr[$pos];
                    if ($inQuote) {
                        if ($c === $quoteChar && ($pos === 0 || $expr[$pos - 1] !== '\\')) {
                            $inQuote = false;
                        }
                    } else {
                        if ($c === '"' || $c === "'") {
                            $inQuote = true;
                            $quoteChar = $c;
                        } elseif ($c === '(') {
                            $depth++;
                        } elseif ($c === ')') {
                            $depth--;
                        }
                    }
                    $current .= $c;
                    $pos++;
                }
            } elseif ($ch === '.') {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
                $pos++;
            } elseif ($ch === '[') {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
                $end = strpos($expr, ']', $pos + 1);
                if ($end === false) break;
                $idx = trim(substr($expr, $pos + 1, $end - $pos - 1));
                if ((str_starts_with($idx, '"') && str_ends_with($idx, '"')) ||
                    (str_starts_with($idx, "'") && str_ends_with($idx, "'"))) {
                    // Quoted string literal — strip quotes
                    $idx = substr($idx, 1, -1);
                } elseif (is_numeric($idx)) {
                    $idx = (int)$idx;
                } else {
                    // Resolve as a variable from context
                    $idx = $this->resolveVariable($idx, $data);
                }
                $segments[] = $idx;
                $pos = $end + 1;
            } else {
                $current .= $ch;
                $pos++;
            }
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /* ─── operator finders (respect string boundaries) ─── */

    private function findTernary(string $expr): int|false
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($ch === '?' && $depth === 0 && $i + 1 < $len && $expr[$i + 1] !== '?') {
                // Make sure it's not ??
                if ($i > 0 && $expr[$i - 1] === '?') continue;
                return $i;
            }
        }
        return false;
    }

    private function findTernaryColon(string $expr): int|false
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($ch === ':' && $depth === 0) return $i;
        }
        return false;
    }

    /**
     * Find the first occurrence of $needle that is not inside quotes or parentheses.
     * Returns the index, or false if not found outside quotes.
     */
    private function findOutsideQuotes(string $expr, string $needle): int|false
    {
        $inQ = null;
        $depth = 0;
        $len = strlen($expr);
        $nLen = strlen($needle);
        for ($i = 0; $i <= $len - $nLen; $i++) {
            $ch = $expr[$i];
            if (($ch === '"' || $ch === "'") && $depth === 0) {
                if ($inQ === null) {
                    $inQ = $ch;
                } elseif ($ch === $inQ) {
                    $inQ = null;
                }
                continue;
            }
            if ($inQ !== null) continue;
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth--; }
            if ($depth === 0 && substr($expr, $i, $nLen) === $needle) return $i;
        }
        return false;
    }

    /**
     * Split $expr on $sep only when $sep is outside quotes and parentheses.
     */
    private function splitOutsideQuotes(string $expr, string $sep): array
    {
        $parts = [];
        $currentStart = 0;
        $inQ = null;
        $depth = 0;
        $len = strlen($expr);
        $sepLen = strlen($sep);
        for ($i = 0; $i <= $len - $sepLen; $i++) {
            $ch = $expr[$i];
            if (($ch === '"' || $ch === "'") && $depth === 0) {
                if ($inQ === null) {
                    $inQ = $ch;
                } elseif ($ch === $inQ) {
                    $inQ = null;
                }
                continue;
            }
            if ($inQ !== null) continue;
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth--; }
            if ($depth === 0 && substr($expr, $i, $sepLen) === $sep) {
                $parts[] = substr($expr, $currentStart, $i - $currentStart);
                $i += $sepLen;
                $currentStart = $i;
                $i--; // compensate for the for-loop increment
                continue;
            }
        }
        $parts[] = substr($expr, $currentStart);
        return $parts;
    }

    private function findOperator(string $expr, string $op): int|false
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        $opLen = strlen($op);
        for ($i = 0; $i <= $len - $opLen; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($depth === 0 && substr($expr, $i, $opLen) === $op) return $i;
        }
        return false;
    }

    private function findLogicalOp(string $expr, string $op): int|false
    {
        // Find last occurrence for left-associativity
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        $opLen = strlen($op);
        $lastPos = false;
        for ($i = 0; $i <= $len - $opLen; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($depth === 0 && substr($expr, $i, $opLen) === $op) {
                $lastPos = $i;
            }
        }
        return $lastPos;
    }

    private function findComparisonOp(string $expr, string $op): int|false
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        $opLen = strlen($op);
        for ($i = 0; $i <= $len - $opLen; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($depth === 0 && substr($expr, $i, $opLen) === $op) {
                // For < and >, make sure we don't match <= or >= or != already handled
                if ($op === '<' && $i + 1 < $len && $expr[$i + 1] === '=') continue;
                if ($op === '>' && $i + 1 < $len && $expr[$i + 1] === '=') continue;
                if ($op === '!' && $i + 1 < $len && $expr[$i + 1] === '=') continue;
                if (($op === '<' || $op === '>') && $i > 0 && ($expr[$i - 1] === '!' || $expr[$i - 1] === '<' || $expr[$i - 1] === '>')) continue;
                return $i;
            }
        }
        return false;
    }

    private function findConcat(string $expr): int|false
    {
        // Find ~ not inside strings or parens
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($ch === '~' && $depth === 0) return $i;
        }
        return false;
    }

    private function findMathOp(string $expr, string $op): int|false
    {
        // Search from right for +/-, left for *//, to respect precedence
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);
        $opLen = strlen($op);

        if (in_array($op, ['+', '-'])) {
            // Right-to-left search for left-associativity (find last)
            $lastPos = false;
            for ($i = 0; $i <= $len - $opLen; $i++) {
                $ch = $expr[$i];
                if ($inStr) {
                    if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                    continue;
                }
                if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
                if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
                if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
                if ($depth === 0 && substr($expr, $i, $opLen) === $op) {
                    // Don't match unary minus at start or after operator
                    if ($op === '-' && $i === 0) continue;
                    if ($i > 0) {
                        $prev = trim(substr($expr, 0, $i));
                        if ($prev === '' || str_ends_with($prev, '(') || str_ends_with($prev, ',')) continue;
                    }
                    $lastPos = $i;
                }
            }
            return $lastPos;
        }

        // Left-to-right for *, /, //, %
        for ($i = 0; $i <= $len - $opLen; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; continue; }
            if ($depth === 0 && substr($expr, $i, $opLen) === $op) {
                // For /, make sure we don't match //
                if ($op === '/' && $opLen === 1) {
                    if ($i + 1 < $len && $expr[$i + 1] === '/') continue;
                    if ($i > 0 && $expr[$i - 1] === '/') continue;
                }
                return $i;
            }
        }
        return false;
    }

    private function findMatchingParen(string $expr, int $start): int|false
    {
        $depth = 0;
        $len = strlen($expr);
        $open = $expr[$start];
        $close = match($open) { '(' => ')', '[' => ']', '{' => '}', default => ')' };
        for ($i = $start; $i < $len; $i++) {
            if ($expr[$i] === $open) $depth++;
            if ($expr[$i] === $close) {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return false;
    }

    /* ─── filter splitting (respects strings, parens, brackets) ─── */

    private function splitFilters(string $expr): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];

            if ($inStr) {
                $current .= $ch;
                if ($ch === $strCh && ($i === 0 || $expr[$i-1] !== '\\')) $inStr = false;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inStr = true;
                $strCh = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; $current .= $ch; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; $current .= $ch; continue; }

            if ($ch === '|' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') $parts[] = $current;
        return $parts;
    }

    /* ─── filter application ─── */

    private function applyFilter(string $filterExpr, mixed $value, array &$data): mixed
    {
        // Parse filter name and args
        $filterExpr = trim($filterExpr);

        // Check for filter(args)
        $filterName = $filterExpr;
        $args = [];

        if (preg_match(self::RE_FILTER_WITH_ARGS, $filterExpr, $m)) {
            $filterName = $m[1];
            $args = $this->parseFilterArgs($m[2], $data);
        }

        // Sandbox check
        if ($this->sandboxed && $this->sandboxFilters !== null && !in_array($filterName, $this->sandboxFilters)) {
            return '';
        }

        // Inline common no-arg filters — avoid closure dispatch overhead
        if (empty($args)) {
            switch ($filterName) {
                case 'upper':      return strtoupper((string)$value);
                case 'lower':      return strtolower((string)$value);
                case 'length':     return is_array($value) ? count($value) : (is_string($value) ? mb_strlen($value) : 0);
                case 'trim':       return trim((string)$value);
                case 'ltrim':      return ltrim((string)$value);
                case 'rtrim':      return rtrim((string)$value);
                case 'capitalize': return ucfirst(strtolower((string)$value));
                case 'title':      return mb_convert_case((string)$value, MB_CASE_TITLE);
                case 'string':     return (string)$value;
                case 'int':        return (int)$value;
                case 'float':      return (float)$value;
                case 'abs':        return abs(is_numeric($value) ? $value : 0);
                case 'escape':
                case 'e':          return self::RAW_MARKER . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                case 'striptags':  return strip_tags((string)$value);
                case 'nl2br':      return self::RAW_MARKER . nl2br(htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                case 'keys':       return is_array($value) ? array_keys($value) : [];
                case 'values':     return is_array($value) ? array_values($value) : [];
                case 'raw':
                case 'safe':       return self::RAW_MARKER . (is_string($value) ? str_replace(self::RAW_MARKER, '', $value) : $this->valueToString($value));
                // Fall through to generic dispatch for other filters
            }
        }

        if (isset($this->filters[$filterName])) {
            $fn = $this->filters[$filterName];
            return $fn($value, ...$args);
        }

        // The filter name may include a trailing comparison operator,
        // e.g. "length != 1".  Extract the real filter name and the
        // comparison suffix, apply the filter, then evaluate the comparison.
        if (preg_match(self::RE_FILTER_COMPARISON, $filterName, $m2)) {
            $realFilter = $m2[1];
            $op = $m2[2];
            $rightExpr = trim($m2[3]);
            if (isset($this->filters[$realFilter])) {
                $value = ($this->filters[$realFilter])($value, ...$args);
            }
            $right = $this->evaluateExpression($rightExpr, $data);
            return match ($op) {
                '!=' => $value != $right,
                '==' => $value == $right,
                '>=' => $value >= $right,
                '<=' => $value <= $right,
                '>'  => $value > $right,
                '<'  => $value < $right,
                default => false,
            };
        }

        return $value;
    }

    private function parseFilterArgs(string $argsStr, array &$data): array
    {
        $args = [];
        $parts = $this->splitArgs($argsStr);
        foreach ($parts as $part) {
            $part = trim($part);
            $args[] = $this->evaluateExpression($part, $data);
        }
        return $args;
    }

    private function splitArgs(string $str): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inStr = false;
        $strCh = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $ch = $str[$i];
            if ($inStr) {
                $current .= $ch;
                if ($ch === $strCh && ($i === 0 || $str[$i-1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $strCh = $ch; $current .= $ch; continue; }
            if ($ch === '(' || $ch === '[' || $ch === '{') { $depth++; $current .= $ch; continue; }
            if ($ch === ')' || $ch === ']' || $ch === '}') { $depth--; $current .= $ch; continue; }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') $parts[] = $current;
        return $parts;
    }

    private function parseArgs(string $argsStr, array &$data): array
    {
        return $this->parseFilterArgs($argsStr, $data);
    }

    /**
     * Process backslash escape sequences in a single pass so that
     * \\' does not collapse to ' (it should become \').
     */
    private function processEscapes(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $next = $s[$i + 1];
                switch ($next) {
                    case 'n':  $out .= "\n"; $i++; break;
                    case 't':  $out .= "\t"; $i++; break;
                    case '\\': $out .= "\\"; $i++; break;
                    case "'":  $out .= "'";  $i++; break;
                    case '"':  $out .= '"';  $i++; break;
                    default:   $out .= '\\'; break;
                }
            } else {
                $out .= $s[$i];
            }
        }
        return $out;
    }

    /* ─── tests ─── */

    private function isKnownTest(string $name): bool
    {
        // Remove argument parens
        $baseName = $name;
        if (str_contains($baseName, '(')) {
            $baseName = trim(substr($baseName, 0, strpos($baseName, '(')));
        }
        // Also handle "divisible by(n)" as "divisible by"
        if (str_starts_with($baseName, 'divisible')) {
            $baseName = 'divisible by';
        }

        $builtins = ['defined', 'empty', 'even', 'odd', 'divisible by', 'null', 'none',
            'iterable', 'string', 'number', 'boolean'];
        if (in_array($baseName, $builtins)) return true;
        return isset($this->tests[$baseName]);
    }

    private function evaluateTest(string $valueExpr, string $testExpr, array &$data): bool
    {
        // Handle "divisible by(n)" or "divisible by (n)"
        if (preg_match(self::RE_DIVISIBLE_BY, $testExpr, $m)) {
            $value = $this->evaluateExpression($valueExpr, $data);
            $divisor = $this->evaluateExpression(trim($m[1]), $data);
            if ($divisor == 0) return false;
            return ((int)$value % (int)$divisor) === 0;
        }

        $value = $this->evaluateExpression($valueExpr, $data);

        switch ($testExpr) {
            case 'defined':
                $parts = $this->parseVarPath(trim($valueExpr));
                if (empty($parts)) return false;
                $current = $data;
                foreach ($parts as $seg) {
                    if (is_array($current) && array_key_exists($seg, $current)) {
                        $current = $current[$seg];
                    } else {
                        return false;
                    }
                }
                return true;
            case 'empty':
                return empty($value);
            case 'even':
                return ((int)$value) % 2 === 0;
            case 'odd':
                return ((int)$value) % 2 !== 0;
            case 'null':
            case 'none':
                return $value === null || $value === '';
            case 'iterable':
                return is_array($value) || is_iterable($value);
            case 'string':
                return is_string($value) && !str_contains($value, self::RAW_MARKER);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            default:
                if (isset($this->tests[$testExpr])) {
                    return (bool)($this->tests[$testExpr])($value);
                }
                return false;
        }
    }

    private function checkIn(mixed $needle, mixed $haystack): bool
    {
        if (is_array($haystack)) {
            return in_array($needle, $haystack);
        }
        if (is_string($haystack) && is_string($needle)) {
            return str_contains($haystack, $needle);
        }
        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === 0.0 || $value === '0') return false;
        if (is_array($value) && empty($value)) return false;
        return true;
    }

    /* ─── array / dict literals ─── */

    private function parseArrayLiteral(string $content, array &$data): array
    {
        $content = trim($content);
        if ($content === '') return [];
        $items = $this->splitArgs($content);
        $result = [];
        foreach ($items as $item) {
            $result[] = $this->evaluateExpression(trim($item), $data);
        }
        return $result;
    }

    private function parseDictLiteral(string $content, array &$data): array
    {
        $content = trim($content);
        if ($content === '') return [];
        $pairs = $this->splitArgs($content);
        $result = [];
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (preg_match(self::RE_DICT_PAIR, $pair, $m)) {
                $key = $m[2];
                $val = $this->evaluateExpression(trim($m[3]), $data);
                $result[$key] = $val;
            }
        }
        return $result;
    }

    /* ─── macro calls ─── */

    private function callMacro(string $name, string $argsStr, array &$data): string
    {
        $macro = $this->macros[$name];
        $argValues = $argsStr !== '' ? $this->parseFilterArgs($argsStr, $data) : [];
        $macroData = $data;
        foreach ($macro['args'] as $i => $argName) {
            // Handle default values
            $argName = trim($argName);
            $default = '';
            if (str_contains($argName, '=')) {
                $parts = explode('=', $argName, 2);
                $argName = trim($parts[0]);
                $default = trim($parts[1], " \"'");
            }
            $macroData[$argName] = $argValues[$i] ?? $default;
        }
        return $this->execute($macro['body'], $macroData);
    }

    /* ─── from import ─── */

    private function executeFromImport(array $node, array &$data): void
    {
        $file = $this->templateDir . '/' . $node['file'];
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $file");
        }
        $source = file_get_contents($file);
        $tokens = $this->tokenize($source);
        $pos = 0;
        $ast = $this->parse($tokens, $pos);

        $names = $node['names'];

        // Walk AST for macro definitions
        foreach ($ast as $astNode) {
            if ($astNode['type'] === 'macro' && in_array($astNode['name'], $names)) {
                $this->macros[$astNode['name']] = $astNode;
            }
        }
    }

    /* ───────────────────── built-in filters ───────────────────── */

    private function registerBuiltinFilters(): void
    {
        // Text filters
        $this->filters['upper'] = fn($v) => strtoupper((string)$v);
        $this->filters['lower'] = fn($v) => strtolower((string)$v);
        $this->filters['capitalize'] = fn($v) => ucfirst(strtolower((string)$v));
        $this->filters['title'] = fn($v) => mb_convert_case((string)$v, MB_CASE_TITLE);
        $this->filters['trim'] = fn($v) => trim((string)$v);
        $this->filters['ltrim'] = fn($v) => ltrim((string)$v);
        $this->filters['rtrim'] = fn($v) => rtrim((string)$v);
        $this->filters['replace'] = fn($v, $from, $to) => str_replace($from, $to, (string)$v);
        $this->filters['striptags'] = fn($v) => strip_tags((string)$v);

        // Encoding
        $this->filters['escape'] = fn($v) => self::RAW_MARKER . htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->filters['e'] = $this->filters['escape'];
        $this->filters['raw'] = fn($v) => self::RAW_MARKER . (is_string($v) ? str_replace(self::RAW_MARKER, '', $v) : $this->valueToString($v));
        $this->filters['safe'] = $this->filters['raw'];
        $this->filters['json_encode'] = fn($v) => self::RAW_MARKER . json_encode($v, JSON_UNESCAPED_UNICODE);
        $this->filters['json_decode'] = fn($v) => json_decode((string)$v, true);
        $this->filters['base64_encode'] = fn($v) => base64_encode(is_string($v) ? $v : (string)$v);
        $this->filters['base64encode'] = &$this->filters['base64_encode'];
        $this->filters['base64_decode'] = fn($v) => base64_decode((string)$v);
        $this->filters['base64decode'] = &$this->filters['base64_decode'];
        $this->filters['data_uri'] = fn($v) => is_array($v) ? self::RAW_MARKER . 'data:' . ($v['type'] ?? 'application/octet-stream') . ';base64,' . base64_encode($v['content'] ?? '') : (string)$v;
        $this->filters['url_encode'] = fn($v) => rawurlencode((string)$v);

        // Hashing
        $this->filters['md5'] = fn($v) => md5((string)$v);
        $this->filters['sha256'] = fn($v) => hash('sha256', (string)$v);

        // Numbers
        $this->filters['abs'] = fn($v) => abs(is_numeric($v) ? $v : 0);
        $this->filters['round'] = fn($v, $d = 0) => round((float)$v, (int)$d);
        $this->filters['int'] = fn($v) => (int)$v;
        $this->filters['float'] = fn($v) => (float)$v;
        $this->filters['number_format'] = fn($v, $d = 0) => number_format((float)$v, (int)$d);

        // Date
        $this->filters['date'] = function($v, $format = 'Y-m-d') {
            if ($v instanceof \DateTimeInterface) {
                return $v->format($format);
            }
            $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
            if ($ts === false) return (string)$v;
            return date($format, $ts);
        };

        // Arrays
        $this->filters['length'] = fn($v) => is_array($v) ? count($v) : (is_string($v) ? mb_strlen($v) : 0);
        $this->filters['first'] = function($v) {
            if (is_array($v)) return !empty($v) ? reset($v) : '';
            if (is_string($v)) return $v !== '' ? $v[0] : '';
            return '';
        };
        $this->filters['last'] = function($v) {
            if (is_array($v)) return !empty($v) ? end($v) : '';
            if (is_string($v)) return $v !== '' ? $v[strlen($v) - 1] : '';
            return '';
        };
        $this->filters['reverse'] = function($v) {
            if (is_array($v)) return array_reverse($v);
            if (is_string($v)) return strrev($v);
            return $v;
        };
        $this->filters['sort'] = function($v) {
            if (!is_array($v)) return $v;
            $sorted = array_values($v);
            sort($sorted);
            return $sorted;
        };
        $this->filters['shuffle'] = function($v) {
            if (!is_array($v)) return $v;
            $shuffled = $v;
            shuffle($shuffled);
            return $shuffled;
        };
        $this->filters['unique'] = fn($v) => is_array($v) ? array_values(array_unique($v)) : $v;
        $this->filters['join'] = fn($v, $sep = '') => is_array($v) ? implode($sep, $v) : (string)$v;
        $this->filters['split'] = fn($v, $sep = '') => $sep !== '' ? explode($sep, (string)$v) : str_split((string)$v);
        $this->filters['slice'] = function($v, $start = 0, $end = null) {
            if (is_array($v)) {
                $length = $end === null ? null : $end - $start;
                return array_slice($v, $start, $length);
            }
            if (is_string($v)) {
                $length = $end === null ? null : $end - $start;
                return substr($v, $start, $length ?? (strlen($v) - $start));
            }
            return $v;
        };
        $this->filters['batch'] = function($v, $size) {
            if (!is_array($v)) return $v;
            return array_chunk($v, max(1, (int)$size));
        };
        $this->filters['map'] = function($v, $key) {
            if (!is_array($v)) return $v;
            return array_map(fn($item) => is_array($item) && isset($item[$key]) ? $item[$key] : (is_object($item) && isset($item->$key) ? $item->$key : ''), $v);
        };
        $this->filters['filter'] = function($v) {
            if (!is_array($v)) return $v;
            return array_values(array_filter($v));
        };
        $this->filters['column'] = function($v, $key) {
            if (!is_array($v)) return $v;
            return array_column($v, $key);
        };

        // Dict
        $this->filters['keys'] = fn($v) => is_array($v) ? array_keys($v) : [];
        $this->filters['values'] = fn($v) => is_array($v) ? array_values($v) : [];
        $this->filters['merge'] = fn($v, $other) => is_array($v) && is_array($other) ? array_merge($v, $other) : $v;

        // Utility
        $this->filters['default'] = fn($v, $fallback = '') => ($v === null || $v === '' || $v === false) ? $fallback : $v;
        $this->filters['dump'] = function($v) {
            ob_start();
            var_dump($v);
            return self::RAW_MARKER . '<pre>' . htmlspecialchars(ob_get_clean(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        };
        $this->filters['string'] = fn($v) => (string)$v;
        $this->filters['truncate'] = function($v, $length = 255) {
            $s = (string)$v;
            if (mb_strlen($s) <= $length) return $s;
            return mb_substr($s, 0, $length) . '...';
        };
        $this->filters['wordwrap'] = fn($v, $width = 75) => wordwrap((string)$v, (int)$width, "\n", true);
        $this->filters['slug'] = function($v) {
            $s = strtolower((string)$v);
            $s = preg_replace(self::RE_SLUG_STRIP, '-', $s);
            return trim($s, '-');
        };
        $this->filters['nl2br'] = fn($v) => self::RAW_MARKER . nl2br(htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $this->filters['format'] = function($v, ...$args) {
            return vsprintf((string)$v, $args);
        };
    }

    /* ───────────────────── built-in tests ───────────────────── */

    private function registerBuiltinTests(): void
    {
        // Tests are handled inline in evaluateTest()
    }

    /* ───────────────────── built-in globals ───────────────────── */

    private function registerBuiltinGlobals(): void
    {
        $formTokenFn = static function (string $descriptor = ''): string {
            $payload = ['type' => 'form', 'nonce' => bin2hex(random_bytes(8))];
            if ($descriptor !== '') {
                if (str_contains($descriptor, '|')) {
                    $parts = explode('|', $descriptor, 2);
                    $payload['context'] = $parts[0];
                    $payload['ref'] = $parts[1];
                } else {
                    $payload['context'] = $descriptor;
                }
            }

            // Include session_id for CSRF session binding
            $sessionId = self::$formTokenSessionId;
            if ($sessionId === '' && session_status() === PHP_SESSION_ACTIVE) {
                $sessionId = session_id();
            }
            if ($sessionId !== '') {
                $payload['session_id'] = $sessionId;
            }

            $secret = DotEnv::getEnv('SECRET') ?? $_ENV['SECRET'] ?? 'tina4-default-secret';
            $ttlMinutes = (int)(DotEnv::getEnv('TINA4_TOKEN_LIMIT', '60') ?? '60');
            $expiresIn = $ttlMinutes * 60;
            $token = Auth::getToken($payload, $secret, $expiresIn);
            return self::RAW_MARKER . '<input type="hidden" name="formToken" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        };

        $this->globals['formToken'] = $formTokenFn;
        $this->globals['form_token'] = $formTokenFn;

        // Also register as filters so {{ "" | formToken }} and {{ "" | form_token }} work
        $this->filters['formToken'] = fn($v) => $formTokenFn((string)($v ?: ''));
        $this->filters['form_token'] = fn($v) => $formTokenFn((string)($v ?: ''));

        // HTML-safe JSON dump (escapes <, >, & as unicode escapes)
        $this->filters['to_json'] = fn($v) => self::RAW_MARKER . str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'], json_encode($v));
        $this->filters['tojson'] = &$this->filters['to_json'];

        // Escape for safe embedding in JavaScript strings (marked raw to bypass auto-escaping)
        $this->filters['js_escape'] = fn($v) => self::RAW_MARKER . str_replace(["\\", "'", '"', "\n", "\r"], ["\\\\", "\\'", '\\"', "\\n", "\\r"], (string)$v);
    }
}
