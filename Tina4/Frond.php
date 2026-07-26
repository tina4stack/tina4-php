<?php

namespace Tina4;

/**
 * Frond - Zero-dependency Twig-like template engine for Tina4.
 *
 * Supports: variables, filters, control structures (if/for/set/include/extends/block/macro/cache),
 * expression evaluation, template inheritance, auto-escaping, sandboxing, whitespace control, and comments.
 *
 * @method static void addFilter(string $name, callable $fn) Register a custom Twig filter (usable as {{ value | name }}).
 * @method static void addGlobal(string $name, mixed $value) Register a global value available in every template.
 * @method static void addTest(string $name, callable $fn) Register a custom test (usable as {% if x is name %}).
 */
class Frond
{
    private string $templateDir;
    private array $globals = [];
    private array $filters = [];
    private array $tests = [];
    private array $cache = [];
    private bool $sandboxed = false;

    /**
     * Class-level registry for filters registered before any instance exists.
     *
     * Mirrors Python's ``Frond._class_filters`` — when a module calls
     * ``Frond::addFilter("money", $fn)`` statically at app-startup, the filter
     * is stored here and drained into every subsequent ``new Frond()``. This
     * lets app.php register filters once, and have every later request's
     * Frond pick them up automatically without manual wiring.
     *
     * @var array<string, callable>
     */
    private static array $classFilters = [];

    /** @var array<string, mixed> Class-level registry for globals (see $classFilters). */
    private static array $classGlobals = [];

    /** @var array<string, callable> Class-level registry for tests (see $classFilters). */
    private static array $classTests = [];

    /** @var array<string, array> Live blocks: name -> parsed body AST (re-executed by renderLive). */
    private static array $liveFragments = [];
    /** @var array<string, callable> Live block name -> data provider (registered via liveSource()). */
    private static array $liveSources = [];
    /** @var array<string, string> Live block name -> declared ws path (data-ws). */
    private static array $liveWsPaths = [];
    /** Guards against nested {% live %} during parse. */
    private bool $parsingLive = false;
    private ?array $sandboxFilters = null;
    private ?array $sandboxTags = null;
    private ?array $sandboxVars = null;
    private array $macros = [];
    /** @var array<string, array{tokens: array, ast: array, mtime: float}> Token pre-compilation cache for file templates */
    private array $compiled = [];
    /** @var array<string, array{tokens: array, ast: array}> Token pre-compilation cache for string templates */
    private array $compiledStrings = [];

    /**
     * AOT-compiled render closures (ADR-0001 / ADR-0003), keyed exactly like the
     * token caches: template name in production, 'dev:'+md5(source) in dev (so an
     * edit recompiles), md5(source) for renderString(). The value is the compiled
     * `function ($engine, $data): string` closure, or null when the template used
     * a construct the compiler falls back on (cached so an unsupported template is
     * not re-analysed every render). Never populated in sandbox mode. Produced by
     * FrondCompiler::compile(); FrondCompiler never throws (returns null on any
     * unsupported construct or codegen error), so a render is never broken by it.
     *
     * @var array<string, \Closure|null>
     */
    private array $compiledFn = [];

    /**
     * Session ID used by formToken() for CSRF session binding.
     * Set this before rendering templates to bind tokens to the current session.
     */
    public static string $formTokenSessionId = '';

    /**
     * Set the session ID used for CSRF form token binding.
     * Parity with Python/Ruby/Node: Frond::setFormTokenSessionId($id)
     *
     * @param string $sessionId The session ID to bind form tokens to
     */
    public static function setFormTokenSessionId(string $sessionId): void
    {
        self::$formTokenSessionId = $sessionId;
    }

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
    private const RE_DICT_PAIR = '/^(?:(["\'])(.+?)\1|(\w+))\s*:\s*(.+)$/s';
    private const RE_SLUG_STRIP = '/[^a-z0-9]+/';

    /**
     * Hard cap on every per-expression memo cache in this engine (ADR-0004).
     *
     * Mirrors the Python master's `@lru_cache(maxsize=1024)` on
     * `_expr_descriptor` / `_split_dotted`. A template that builds expression
     * strings dynamically would otherwise grow a plain instance array without
     * limit for the lifetime of the engine, which is a memory footgun on a
     * long-lived worker.
     */
    private const MEMO_CACHE_MAX = 1024;

    /**
     * @var array<string, array> Structural descriptor per expression string --
     *   which top-level operator the expression splits on and where. Holds no
     *   context value; see exprScan().
     */
    private array $exprScanCache = [];

    /** @var array<string, string[]> Cache for simple dotted path splits (no brackets/parens) */
    private array $dottedSplitCache = [];

    public function __construct(string $templateDir = 'src/templates')
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->registerBuiltinFilters();
        $this->registerBuiltinTests();
        $this->registerBuiltinGlobals();

        // Drain class-level registries into this instance. Mirrors Python's
        // ``self._globals.update(Frond._class_globals)`` etc. — filters/globals/
        // tests registered statically via Frond::addFilter() before this
        // instance was constructed will now be available on $this.
        $this->filters = array_merge($this->filters, self::$classFilters);
        $this->globals = array_merge($this->globals, self::$classGlobals);
        $this->tests = array_merge($this->tests, self::$classTests);
    }

    /* ───────────────────── public API ───────────────────── */

    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $file");
        }

        $debugMode = strtolower(getenv('TINA4_DEBUG') ?: '') === 'true';

        // TINA4_TEMPLATE_CACHE_TTL: 0 (default) means cache forever in
        // production; >0 means re-read after N seconds. Lets operators tune
        // freshness without redeploying. Ignored in debug mode (always fresh).
        $cacheTtl = (int) (DotEnv::getEnv('TINA4_TEMPLATE_CACHE_TTL', '0') ?? '0');

        if (!$debugMode) {
            // Production: use permanent cache (no filesystem checks)
            $cached = $this->compiled[$template] ?? null;
            $stale = false;
            if ($cached !== null && $cacheTtl > 0) {
                $cachedAt = $cached['cachedAt'] ?? 0;
                if ((time() - $cachedAt) >= $cacheTtl) {
                    $stale = true;
                }
            }
            if ($cached !== null && !$stale) {
                $data = array_merge($this->globals, $data);
                return $this->renderAst($template, $cached['ast'], $data, $template);
            }
        }
        // Dev mode: skip cache entirely — always re-read and re-tokenize
        // so edits to partials and extended base templates are detected

        // Cache miss — load, tokenize, parse, cache
        $source = file_get_contents($file);
        $mtime = filemtime($file);
        $tokens = $this->tokenize($source);
        $ast = $this->parse($tokens);
        $this->compiled[$template] = [
            'tokens' => $tokens,
            'ast' => $ast,
            'mtime' => $mtime,
            'cachedAt' => time(),
        ];

        $data = array_merge($this->globals, $data);
        // Prod keys the compiled fn by template name (files are stable under a
        // running prod worker); dev keys it by a hash of THIS source so an edit
        // produces a new key and recompiles, keeping hot-reload visibility while
        // still exercising the compiled path.
        $compileKey = $debugMode ? ('dev:' . md5($source)) : $template;
        return $this->renderAst($compileKey, $ast, $data, $template);
    }

    public function renderString(string $source, array $data = [], ?string $templateName = null): string
    {
        $key = md5($source);
        $cached = $this->compiledStrings[$key] ?? null;

        if ($cached !== null) {
            $data = array_merge($this->globals, $data);
            return $this->renderAst($key, $cached['ast'], $data, $templateName);
        }

        $data = array_merge($this->globals, $data);
        $tokens = $this->tokenize($source);
        $ast = $this->parse($tokens);
        $this->compiledStrings[$key] = ['tokens' => $tokens, 'ast' => $ast];

        return $this->renderAst($key, $ast, $data, $templateName);
    }

    /**
     * Render a parsed AST via the AOT-compiled closure when available, else the
     * interpreter. The compiled fast path is used only for a non-sandboxed engine
     * with a cache key and a template the compiler accepts (it rejects extends/
     * block/macro/include/cache/live/etc.); everything else - including every
     * template that uses inheritance - falls through to the interpreter, which
     * runs `resolveInheritance()` (a no-op for non-extends ASTs) then `execute()`.
     *
     * @param string|null $compileKey Cache key for the compiled closure (null skips compilation).
     * @param array<int, array<string, mixed>> $ast Parsed AST to render.
     * @param array<string, mixed> $data Render context (by ref, so interpreter set()s persist).
     * @param string|null $templateName Template name for inheritance resolution.
     * @return string The rendered output.
     */
    private function renderAst(?string $compileKey, array $ast, array &$data, ?string $templateName): string
    {
        if (!$this->sandboxed && $compileKey !== null) {
            $compiled = $this->getCompiled($compileKey, $ast);
            if ($compiled !== null) {
                return $compiled($this, $data);
            }
        }
        $ast = $this->resolveInheritance($ast, $data, $templateName);
        return $this->execute($ast, $data);
    }

    /**
     * Return the AOT-compiled render closure for a cache key, compiling once and
     * memoising the result (including a null "unsupported, use the interpreter"
     * outcome, so a template is not re-analysed every render).
     *
     * @param string $compileKey Cache key (template name / source hash).
     * @param array<int, array<string, mixed>> $ast Parsed AST to compile.
     * @return \Closure|null The compiled closure, or null to use the interpreter.
     */
    private function getCompiled(string $compileKey, array $ast): ?\Closure
    {
        if (array_key_exists($compileKey, $this->compiledFn)) {
            return $this->compiledFn[$compileKey];
        }
        $fn = FrondCompiler::compile($ast);
        $this->compiledFn[$compileKey] = $fn;
        return $fn;
    }

    /**
     * Clear all compiled template caches.
     */
    public function clearCache(): void
    {
        $this->compiled = [];
        $this->compiledStrings = [];
        $this->compiledFn = [];
        $this->exprScanCache = [];
        $this->dottedSplitCache = [];
    }

    /**
     * Keep a per-expression memo cache bounded to MEMO_CACHE_MAX entries
     * (ADR-0004). Call immediately before inserting a new entry.
     *
     * Eviction is insertion-ordered (oldest first), not true LRU: PHP arrays
     * preserve insertion order, so dropping from the front is O(1) per key,
     * whereas refreshing recency on every cache HIT would add two hash writes
     * to the hottest path in a render and cost more than it saves. Half the
     * cache is dropped at once so the unset sweep amortises to O(1) per insert.
     *
     * @param array<string, mixed> $cache Memo cache to bound, by reference.
     */
    private function capMemoCache(array &$cache): void
    {
        if (count($cache) < self::MEMO_CACHE_MAX) {
            return;
        }
        $drop = intdiv(self::MEMO_CACHE_MAX, 2);
        foreach ($cache as $key => $_) {
            unset($cache[$key]);
            if (--$drop <= 0) {
                break;
            }
        }
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

    /**
     * Clear the class-level filter/global/test registries.
     *
     * Useful in test fixtures to prevent leaking state between tests. Does
     * NOT affect built-in filters/globals/tests on existing or future
     * instances — only the user-registered class-level entries are cleared.
     * Mirrors Python's ``Frond.clear_registry()``.
     */
    public static function clearRegistry(): void
    {
        self::$classFilters = [];
        self::$classGlobals = [];
        self::$classTests = [];
        self::$liveFragments = [];
        self::$liveSources = [];
        self::$liveWsPaths = [];
    }

    /**
     * Instance dispatch for ``addFilter`` / ``addGlobal`` / ``addTest``.
     *
     * PHP cannot declare a static and an instance method with the same name,
     * so we use ``__call`` (instance side) + ``__callStatic`` (class side)
     * to implement Python's ``_ClassOrInstanceMethod`` pattern:
     *
     *   ``Frond::addFilter("money", $fn)``  → ``__callStatic`` → class registry only
     *   ``$frond->addFilter("money", $fn)`` → ``__call``       → class registry AND
     *                                                            instance's local map
     *
     * Future ``new Frond()`` instances drain the class registry in their
     * constructor, so filters/globals/tests registered statically at
     * app-startup propagate to every later instance automatically.
     */
    public function __call(string $method, array $args): mixed
    {
        switch ($method) {
            case 'addFilter':
                [$name, $fn] = $args;
                self::$classFilters[$name] = $fn;
                $this->filters[$name] = $fn;
                return null;
            case 'addGlobal':
                [$name, $value] = $args;
                self::$classGlobals[$name] = $value;
                $this->globals[$name] = $value;
                return null;
            case 'addTest':
                [$name, $fn] = $args;
                self::$classTests[$name] = $fn;
                $this->tests[$name] = $fn;
                return null;
        }
        throw new \BadMethodCallException(sprintf('Frond::%s does not exist', $method));
    }

    /**
     * Static dispatch for ``Frond::addFilter`` / ``addGlobal`` / ``addTest``.
     *
     * See ``__call`` for the dual static/instance call semantics. Static
     * calls only touch the class registry — the next ``new Frond()``
     * constructor drains it into the instance.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        switch ($method) {
            case 'addFilter':
                [$name, $fn] = $args;
                self::$classFilters[$name] = $fn;
                return null;
            case 'addGlobal':
                [$name, $value] = $args;
                self::$classGlobals[$name] = $value;
                return null;
            case 'addTest':
                [$name, $fn] = $args;
                self::$classTests[$name] = $fn;
                return null;
        }
        throw new \BadMethodCallException(sprintf('Frond::%s does not exist', $method));
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
                return $this->handleCache($rest, $tokens, $pos);
            case 'live':
                return $this->handleLive($rest, $tokens, $pos);
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

    private function handleCache(string $params, array &$tokens, int &$pos): array
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

    /**
     * Parse {% live "name" poll N | sse | ws "path" [src "url"] %}...{% endlive %}.
     * Mirrors Python's _handle_live (parse half). Guards: unknown transport,
     * poll-without-seconds, cross-origin src, nested live.
     */
    private function handleLive(string $params, array &$tokens, int &$pos): array
    {
        if ($this->parsingLive) {
            throw new \RuntimeException('live: nested live blocks are not supported');
        }
        if (!preg_match('/^["\']([^"\']+)["\']\s*(.*)$/s', trim($params), $m)) {
            throw new \RuntimeException('live: expected {% live "name" poll N | sse | ws "path" %}');
        }
        $name = $m[1];
        $rest = trim($m[2]);

        $src = null;
        if (preg_match('/\bsrc\s+["\']([^"\']+)["\']/', $rest, $sm)) {
            $src = $sm[1];
        }
        if ($src !== null && preg_match('#^(?:https?:)?//#', $src)) {
            throw new \RuntimeException('live: src must be a same-origin path, not an absolute URL');
        }

        $parts = preg_split(self::RE_WHITESPACE_SPLIT, $rest);
        $mode = $parts[0] ?? '';
        $interval = null;
        $wsPath = null;
        if ($mode === 'poll') {
            if (!isset($parts[1]) || !ctype_digit($parts[1])) {
                throw new \RuntimeException('live: poll requires seconds, e.g. {% live "x" poll 5 %}');
            }
            $interval = (int)$parts[1];
        } elseif ($mode === 'sse') {
            // no extra params
        } elseif ($mode === 'ws') {
            if (!preg_match('/\bws\s+["\']([^"\']+)["\']/', $rest, $wm)) {
                throw new \RuntimeException('live: ws requires a path, e.g. {% live "x" ws "/ws/x" %}');
            }
            $wsPath = $wm[1];
        } else {
            throw new \RuntimeException('live: unknown transport "' . $mode . '" (use poll N, sse, or ws "path")');
        }

        $pos++;
        $this->parsingLive = true;
        try {
            $body = $this->parse($tokens, $pos, 'endlive');
        } finally {
            $this->parsingLive = false;
        }
        $pos++;

        return [
            'type' => 'live', 'name' => $name, 'mode' => $mode,
            'interval' => $interval, 'wsPath' => $wsPath, 'src' => $src, 'body' => $body,
        ];
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
                    // Wrap the current body as a block node that preserves the
                    // parentBody chain so parent()/super() resolves at every
                    // level in multi-level inheritance (A extends B extends C).
                    $parentBlock = [
                        'type' => 'block',
                        'name' => $node['name'],
                        'body' => $node['body'],
                    ];
                    if (!empty($node['parentBody'])) {
                        $parentBlock['parentBody'] = $node['parentBody'];
                    }
                    $node['parentBody'] = [$parentBlock];
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
                if (!empty($node['parentBody'])) {
                    // Inject parent()/super() callables for block inheritance
                    $parentBody = $node['parentBody'];
                    $engine = $this;
                    $renderedParent = null;
                    $getParent = function () use ($engine, $parentBody, &$data, &$renderedParent) {
                        if ($renderedParent === null) {
                            $renderedParent = self::RAW_MARKER . $engine->execute($parentBody, $data);
                        }
                        return $renderedParent;
                    };
                    $blockData = $data;
                    $blockData['parent'] = $getParent;
                    $blockData['super'] = $getParent;
                    return $this->execute($node['body'], $blockData);
                }
                return $this->execute($node['body'], $data);

            case 'macro':
                $this->macros[$node['name']] = $node;
                return '';

            case 'from_import':
                $this->executeFromImport($node, $data);
                return '';

            case 'cache':
                return $this->renderCache($node, $data);

            case 'live':
                return $this->renderLiveNode($node, $data);

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

        // SafeString instances bypass auto-escape (parity with Python frond.SafeString)
        if ($value instanceof SafeString) {
            return (string)$value;
        }

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

    private function renderCache(array $node, array &$data): string
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

    /**
     * Render a {% live %} node: register its body for re-render, render the
     * first paint, wrap in the data-frond-live marker frond.js wires up.
     * Mirrors Python's _handle_live (render half).
     */
    private function renderLiveNode(array $node, array &$data): string
    {
        $name = $node['name'];
        self::$liveFragments[$name] = $node['body'];
        if ($node['mode'] === 'ws' && $node['wsPath'] !== null) {
            self::$liveWsPaths[$name] = $node['wsPath'];
        }
        $endpoint = $node['src'] ?? ('/__frond/live/' . $name);
        $attrs = [
            'data-frond-live="' . $this->liveAttr($name) . '"',
            'id="live-' . $this->liveAttr($name) . '"',
        ];
        if ($node['mode'] === 'poll') {
            $attrs[] = 'data-mode="poll"';
            $attrs[] = 'data-interval="' . (int)$node['interval'] . '"';
            $attrs[] = 'data-src="' . $this->liveAttr($endpoint) . '"';
        } elseif ($node['mode'] === 'sse') {
            $attrs[] = 'data-mode="sse"';
            $attrs[] = 'data-src="' . $this->liveAttr($endpoint) . '"';
        } else {
            $attrs[] = 'data-mode="ws"';
            $attrs[] = 'data-ws="' . $this->liveAttr($node['wsPath']) . '"';
        }
        $firstPaint = $this->execute($node['body'], $data);
        return '<div ' . implode(' ', $attrs) . '>' . $firstPaint . '</div>';
    }

    private function liveAttr($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Re-render a registered {% live %} fragment by name with fresh data.
     * Returns the HTML fragment, or null if no fragment is registered under
     * that name yet (its page has not rendered). The /__frond/live/{name}
     * endpoint calls this after resolving the provider. Mirrors Frond.render_live.
     */
    public static function renderLive(string $name, array $data = []): ?string
    {
        if (!isset(self::$liveFragments[$name])) {
            return null;
        }
        $frond = new self();
        $body = self::$liveFragments[$name];
        return $frond->execute($body, $data);
    }

    /** Register a data provider for a {% live %} block. Mirrors Python's @live_source. */
    public static function liveSource(string $name, callable $fn): void
    {
        self::$liveSources[$name] = $fn;
    }

    /**
     * Handle GET /__frond/live/{name}: resolve the provider, run it with the
     * live request (auth re-applies), re-render the fragment, return via the
     * response callable. 404 for unknown name / unrendered fragment. Mirrors
     * Python's live_endpoint. ($request/$response are the Tina4 objects.)
     */
    public static function respondLive($request, $response, string $name)
    {
        $provider = self::$liveSources[$name] ?? null;
        if (!isset(self::$liveFragments[$name]) && $provider === null) {
            return $response('live block not found: ' . $name, 404);
        }
        $context = [];
        if ($provider !== null) {
            $result = $provider($request);
            $context = is_array($result) ? $result : [];
        }
        $html = self::renderLive($name, $context);
        if ($html === null) {
            return $response('live fragment not registered yet: ' . $name, 404);
        }
        return $response($html);
    }

    /** @return callable|null The provider registered for a live block, or null. */
    public static function getLiveSource(string $name): ?callable
    {
        return self::$liveSources[$name] ?? null;
    }

    /** @return bool Whether a live fragment has been registered (its page rendered). */
    public static function hasLiveFragment(string $name): bool
    {
        return isset(self::$liveFragments[$name]);
    }

    /** @return string|null The ws path a live block declared (data-ws), or null. */
    public static function getLiveWsPath(string $name): ?string
    {
        return self::$liveWsPaths[$name] ?? null;
    }

    /**
     * Re-render the '<name>' live fragment and push it to connected clients.
     * Broadcasts a {type,name,html} envelope over WebSocket to the block's
     * declared data-ws path (else a room named <name>). Returns the rendered
     * HTML, or null if the fragment is not registered. Mirrors Python push_live.
     */
    public static function pushLive(string $name, array $data = []): ?string
    {
        $html = self::renderLive($name, $data);
        if ($html === null) {
            return null;
        }
        $envelope = json_encode(['type' => 'live', 'name' => $name, 'html' => $html]);
        if (class_exists('\\Tina4\\Server')) {
            $server = \Tina4\Server::getInstance();
            if ($server !== null) {
                try {
                    $wsPath = self::getLiveWsPath($name);
                    if ($wsPath !== null) {
                        $server->broadcastWebSocket($envelope, $wsPath);
                    } else {
                        $server->broadcastToRoom($name, $envelope);
                    }
                } catch (\Throwable $e) {
                    if (class_exists('\\Tina4\\Log')) {
                        \Tina4\Log::error('pushLive(' . $name . ') broadcast failed: ' . $e->getMessage());
                    }
                }
            }
        }
        return $html;
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

    /** Sentinel returned by helpers when the expression doesn't match. */
    private const NOT_MATCHED = '__NOT_MATCHED__';

    private function evaluateExpression(string $expr, array &$data): mixed
    {
        $expr = trim($expr);
        if ($expr === '') return '';

        // Which top-level operator this expression splits on, and where, is a
        // pure function of the expression STRING -- it cannot change between
        // renders -- so it is derived once and memoised. Only the value
        // resolution below runs on every call. The branch order here is
        // IDENTICAL to the linear chain it replaced: same precedence, same
        // behaviour, same rendered bytes. See exprScan().
        $scan = $this->exprScan($expr);

        // 1. Literals (strings, numbers, booleans, null)
        if (isset($scan['lit'])) return $scan['lit'][0];

        // 2. Parenthesized sub-expression
        if (isset($scan['paren'])) return $this->evaluateExpression($scan['paren'], $data);

        // 3. Ternary (? :) and inline-if
        $v = $this->evaluateTernary($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 4. Null coalescing (??)
        $v = $this->evaluateNullCoalesce($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 5. Logical operators (or, and, not)
        $v = $this->evaluateLogical($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 6. Comparisons (==, !=, <, >, <=, >=, in, not in, is, is not)
        $v = $this->evaluateComparison($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 7. String concatenation (~) — evaluated BEFORE the filter pipe because
        //    `~` binds looser than `|` in Twig (issue #171). Splitting on the
        //    lower-precedence `~` at the outer level makes
        //    `amount|number_format(2) ~ ' EUR'` group as
        //    `(amount|number_format(2)) ~ ' EUR'`; each side is then evaluated
        //    recursively, so the filter still resolves at its (tighter) depth.
        $v = $this->evaluateConcat($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 8. Filter pipes
        $v = $this->evaluateFilterPipe($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 9. Arithmetic (+, -, *, /, //, %, **)
        $v = $this->evaluateArithmetic($data, $scan);
        if ($v !== self::NOT_MATCHED) return $v;

        // 10. Collection literals ([...], {...}) and ranges
        $v = $this->evaluateCollectionLiteral($expr, $data);
        if ($v !== self::NOT_MATCHED) return $v;

        // 11. Function / macro calls
        $v = $this->evaluateFunctionCall($expr, $data);
        if ($v !== self::NOT_MATCHED) return $v;

        // 12. Variable resolution (fallback)
        return $this->resolveVariable($expr, $data);
    }

    /* ── helper: literal evaluation ── */

    private function evaluateLiteral(string $expr): mixed
    {
        if ($expr === 'true') return true;
        if ($expr === 'false') return false;
        if ($expr === 'none' || $expr === 'null') return null;

        // Simple quoted string (no embedded same-quote)
        if (strlen($expr) >= 2) {
            $q = $expr[0];
            if (($q === '"' || $q === "'") && str_ends_with($expr, $q)
                && !str_contains(substr($expr, 1, -1), $q)) {
                return $this->processEscapes(substr($expr, 1, -1));
            }
        }

        // Numeric literal
        if (is_numeric($expr)) {
            return str_contains($expr, '.') ? (float)$expr : (int)$expr;
        }

        // Quoted string with escaped embedded quotes (fallback)
        if ((str_starts_with($expr, '"') && str_ends_with($expr, '"'))
            || (str_starts_with($expr, "'") && str_ends_with($expr, "'"))) {
            $q = $expr[0];
            $inner = substr($expr, 1, -1);
            // Only treat as a string literal if the inner part has no
            // unescaped same-quote characters.  An unescaped quote means
            // this is actually an expression (e.g. "Hello" ~ " " ~ "World").
            if (!preg_match('/(?<!\\\\)' . preg_quote($q, '/') . '/', $inner)) {
                return $this->processEscapes($inner);
            }
        }

        return self::NOT_MATCHED;
    }

    /* ── expression structure cache ── */

    /**
     * Structural descriptor for an expression, memoised per expression string.
     *
     * Operator detection and the operand split that follows it depend ONLY on
     * the expression string, never on the render data -- yet the interpreter was
     * re-deriving them on every render. For a single 20-row loop template that
     * is roughly 1,230 full PHP character scans per render (findTernary +
     * findLogicalOp x4 + findMathOp x7 per evaluateExpression call, none of
     * which have the str_contains fast path findOutsideQuotes has), every one of
     * them recomputing an answer that cannot have changed. This caches the
     * answer, so a repeat render -- every loop iteration, every request -- is an
     * array read instead of a rescan.
     *
     * The descriptor holds NO context value. That render-independence is the
     * correctness invariant: value lookups and filter application still run on
     * every call, and only the string scanning collapses to a lookup. This is
     * the PHP twin of the Python master's `_expr_descriptor`.
     *
     * @return array<string, mixed> Descriptor keyed by branch tag; see computeExprScan().
     */
    private function exprScan(string $expr): array
    {
        if (isset($this->exprScanCache[$expr])) {
            return $this->exprScanCache[$expr];
        }
        $this->capMemoCache($this->exprScanCache);
        return $this->exprScanCache[$expr] = $this->computeExprScan($expr);
    }

    /**
     * Derive the structural descriptor for an expression.
     *
     * Mirrors the branch-detection order of evaluateExpression() EXACTLY and
     * stops at the first branch that matches, so a descriptor records only what
     * the interpreter can actually reach -- same precedence, same operand
     * boundaries, same rendered bytes.
     *
     * Two things are deliberately NOT baked in, because they are engine state or
     * render context rather than syntax:
     *  - an `is` test: whether the test NAME is registered can change at runtime
     *    (addTest), so the pure regex match is recorded but derivation CONTINUES
     *    past it -- evaluateComparison() still consults isKnownTest() live and
     *    falls through to the comparison operators when the test is unknown.
     *  - collection literals, function/macro calls and variable resolution
     *    (steps 10-12): these resolve against registered macros, globals and the
     *    render context, so they stay entirely live.
     *
     * @return array<string, mixed>
     */
    private function computeExprScan(string $expr): array
    {
        $scan = [];

        // 1. Literals (strings, numbers, booleans, null). Wrapped in an array so
        //    a literal `null`/`false` is still detectable with isset().
        $literal = $this->evaluateLiteral($expr);
        if ($literal !== self::NOT_MATCHED) {
            return ['lit' => [$literal]];
        }

        // 2. Parenthesized sub-expression
        if (strlen($expr) >= 2 && $expr[0] === '(' && str_ends_with($expr, ')')
            && $this->matchedParens($expr)) {
            return ['paren' => substr($expr, 1, -1)];
        }

        // 3a. C-style ternary: condition ? trueVal : falseVal
        $ternaryPos = $this->findTernary($expr);
        if ($ternaryPos !== false) {
            $rest = substr($expr, $ternaryPos + 1);
            $colonPos = $this->findTernaryColon($rest);
            if ($colonPos !== false) {
                return ['ternary' => [
                    trim(substr($expr, 0, $ternaryPos)),
                    trim(substr($rest, 0, $colonPos)),
                    trim(substr($rest, $colonPos + 1)),
                ]];
            }
        }

        // 3b. Jinja2-style inline if: value if condition else other_value.
        //     Reached even when a `?` was found but had no matching `:`.
        $ifPos = $this->findOutsideQuotes($expr, ' if ');
        if ($ifPos !== false) {
            $elsePos = $this->findOutsideQuotes($expr, ' else ');
            if ($elsePos !== false && $elsePos > $ifPos) {
                return ['inlineIf' => [
                    trim(substr($expr, 0, $ifPos)),
                    trim(substr($expr, $ifPos + 4, $elsePos - $ifPos - 4)),
                    trim(substr($expr, $elsePos + 6)),
                ]];
            }
        }

        // 4. Null coalescing (??)
        $coalescePos = $this->findOutsideQuotes($expr, '??');
        if ($coalescePos !== false) {
            return ['coalesce' => [
                trim(substr($expr, 0, $coalescePos)),
                trim(substr($expr, $coalescePos + 2)),
            ]];
        }

        // 5. Logical operators (or, and, not)
        $orPos = $this->findLogicalOp($expr, ' or ');
        if ($orPos !== false) {
            return ['or' => [trim(substr($expr, 0, $orPos)), trim(substr($expr, $orPos + 4))]];
        }
        $andPos = $this->findLogicalOp($expr, ' and ');
        if ($andPos !== false) {
            return ['and' => [trim(substr($expr, 0, $andPos)), trim(substr($expr, $andPos + 5))]];
        }
        if (preg_match(self::RE_NOT_PREFIX, $expr, $matches)) {
            return ['not' => $matches[1]];
        }

        // 6. Comparisons -- "not in" / "in" / "is not" / "is" / == != <= >= < >
        $notInPos = $this->findLogicalOp($expr, ' not in ');
        if ($notInPos !== false) {
            return ['notIn' => [trim(substr($expr, 0, $notInPos)), trim(substr($expr, $notInPos + 8))]];
        }
        $inPos = $this->findLogicalOp($expr, ' in ');
        if ($inPos !== false) {
            return ['in' => [trim(substr($expr, 0, $inPos)), trim(substr($expr, $inPos + 4))]];
        }
        if (preg_match(self::RE_IS_NOT_TEST, $expr, $matches)) {
            return ['isNotTest' => [trim($matches[1]), trim($matches[2])]];
        }
        // Recorded but NOT returned on: isKnownTest() is live engine state, so
        // the fall-through branches below must be derived too.
        if (preg_match(self::RE_IS_TEST, $expr, $matches)) {
            $scan['isTest'] = [trim($matches[1]), trim($matches[2])];
        }
        foreach (['!=', '==', '<=', '>=', '<', '>'] as $comparisonOperator) {
            if ($this->findOutsideQuotes($expr, $comparisonOperator) === false) continue;
            $operatorPos = $this->findComparisonOp($expr, $comparisonOperator);
            if ($operatorPos !== false) {
                $scan['compare'] = [
                    $comparisonOperator,
                    trim(substr($expr, 0, $operatorPos)),
                    trim(substr($expr, $operatorPos + strlen($comparisonOperator))),
                ];
                return $scan;
            }
        }

        // 7. String concatenation (~) -- before the filter pipe, because `~`
        //    binds looser than `|` in Twig (issue #171).
        if ($this->findOutsideQuotes($expr, '~') !== false) {
            $scan['concat'] = array_map('trim', $this->splitOutsideQuotes($expr, '~'));
            return $scan;
        }

        // 8. Filter pipes. Each segment past the first is pre-split into its
        //    filter call and optional property-access suffix (`first.groupSummary`,
        //    `format(2).toUpperCase`) -- that split is structural too.
        $filterSplit = $this->splitFilters($expr);
        if (count($filterSplit) > 1) {
            $segments = [];
            for ($i = 1, $count = count($filterSplit); $i < $count; $i++) {
                $part = trim($filterSplit[$i]);
                $dotPos = $this->findDotOutsideParens($part);
                $segments[] = $dotPos !== false
                    ? [trim(substr($part, 0, $dotPos)), substr($part, $dotPos + 1)]
                    : [$part, null];
            }
            $scan['pipe'] = [$filterSplit[0], $segments];
            return $scan;
        }

        // 9. Arithmetic (+, -, *, /, //, %, **) -- lowest precedence group first
        foreach ([['+', '-'], ['*', '//', '/', '%', '**']] as $operatorGroup) {
            foreach ($operatorGroup as $mathOperator) {
                $operatorPos = $this->findMathOp($expr, $mathOperator);
                if ($operatorPos === false) continue;
                $scan['arithmetic'] = [
                    $mathOperator,
                    trim(substr($expr, 0, $operatorPos)),
                    trim(substr($expr, $operatorPos + strlen($mathOperator))),
                ];
                return $scan;
            }
        }
        // Parenthesized fallback, checked only after the operators (see
        // evaluateArithmetic's original tail).
        if (str_starts_with($expr, '(')
            && $this->findMatchingParen($expr, 0) === strlen($expr) - 1) {
            $scan['arithmeticParen'] = substr($expr, 1, -1);
        }

        return $scan;
    }

    /**
     * Check whether the outer parentheses of $expr are a matched pair
     * that wraps the entire expression.
     */
    private function matchedParens(string $expr): bool
    {
        $depth = 0;
        for ($i = 0, $len = strlen($expr); $i < $len; $i++) {
            if ($expr[$i] === '(') $depth++;
            elseif ($expr[$i] === ')') $depth--;
            if ($depth === 0 && $i < $len - 1) return false;
        }
        return true;
    }

    /* ── helper: ternary and inline-if ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateTernary(array &$data, array $scan): mixed
    {
        // C-style ternary: condition ? trueVal : falseVal
        if (isset($scan['ternary'])) {
            [$condition, $trueVal, $falseVal] = $scan['ternary'];
            return $this->isTruthy($this->evaluateExpression($condition, $data))
                ? $this->evaluateExpression($trueVal, $data)
                : $this->evaluateExpression($falseVal, $data);
        }

        // Jinja2-style inline if: value if condition else other_value
        if (isset($scan['inlineIf'])) {
            [$valuePart, $condPart, $elsePart] = $scan['inlineIf'];
            return $this->isTruthy($this->evaluateExpression($condPart, $data))
                ? $this->evaluateExpression($valuePart, $data)
                : $this->evaluateExpression($elsePart, $data);
        }

        return self::NOT_MATCHED;
    }

    /* ── helper: null coalescing ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateNullCoalesce(array &$data, array $scan): mixed
    {
        if (!isset($scan['coalesce'])) return self::NOT_MATCHED;

        [$left, $right] = $scan['coalesce'];
        $leftVal = $this->evaluateExpressionSafe($left, $data);
        return $leftVal !== null ? $leftVal : $this->evaluateExpression($right, $data);
    }

    /* ── helper: logical operators (or, and, not) ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateLogical(array &$data, array $scan): mixed
    {
        // or
        if (isset($scan['or'])) {
            [$left, $right] = $scan['or'];
            return $this->isTruthy($this->evaluateExpression($left, $data))
                || $this->isTruthy($this->evaluateExpression($right, $data));
        }

        // and
        if (isset($scan['and'])) {
            [$left, $right] = $scan['and'];
            return $this->isTruthy($this->evaluateExpression($left, $data))
                && $this->isTruthy($this->evaluateExpression($right, $data));
        }

        // not prefix
        if (isset($scan['not'])) {
            return !$this->isTruthy($this->evaluateExpression($scan['not'], $data));
        }

        return self::NOT_MATCHED;
    }

    /* ── helper: comparisons ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateComparison(array &$data, array $scan): mixed
    {
        // "not in"
        if (isset($scan['notIn'])) {
            [$left, $right] = $scan['notIn'];
            return !$this->checkIn(
                $this->evaluateExpression($left, $data),
                $this->evaluateExpression($right, $data)
            );
        }

        // "in"
        if (isset($scan['in'])) {
            [$left, $right] = $scan['in'];
            return $this->checkIn(
                $this->evaluateExpression($left, $data),
                $this->evaluateExpression($right, $data)
            );
        }

        // "is not" tests
        if (isset($scan['isNotTest'])) {
            [$valueExpr, $testName] = $scan['isNotTest'];
            return !$this->evaluateTest($valueExpr, $testName, $data);
        }

        // "is" tests. Whether the test NAME is registered is live engine state,
        // so it is checked here rather than baked into the descriptor; an unknown
        // test falls through to the comparison operators exactly as before.
        if (isset($scan['isTest'])) {
            [$valueExpr, $testName] = $scan['isTest'];
            if ($this->isKnownTest($testName)) {
                return $this->evaluateTest($valueExpr, $testName, $data);
            }
        }

        // Comparison operators: !=, ==, <=, >=, <, >
        if (isset($scan['compare'])) {
            [$op, $left, $right] = $scan['compare'];
            $leftVal = $this->evaluateExpression($left, $data);
            $rightVal = $this->evaluateExpression($right, $data);
            return match($op) {
                '==' => $leftVal == $rightVal,
                '!=' => $leftVal != $rightVal,
                '<'  => $leftVal < $rightVal,
                '>'  => $leftVal > $rightVal,
                '<=' => $leftVal <= $rightVal,
                '>=' => $leftVal >= $rightVal,
            };
        }

        return self::NOT_MATCHED;
    }

    /* ── helper: filter pipes ── */

    /**
     * Apply a pre-split filter chain to its base value.
     *
     * The chain split itself -- where the top-level `|` boundaries are, and
     * whether a segment carries a property-access suffix -- is structural and
     * lives in the expression descriptor (see computeExprScan step 8). Only the
     * value resolution and filter application happen here, on every render.
     *
     * A filter segment can carry a property-access suffix:
     *   details|first.groupSummary    -> apply `first`, then `.groupSummary`
     *   invoice|format(2).toUpperCase -> apply `format(2)`, then `.toUpperCase`
     * The descriptor splits at the first `.` outside quotes + parens, so
     * `date("Y.m.d")` and `number_format(1.5)` are not split. Without that
     * split, applyFilter() was handed "first.groupSummary" as the whole filter
     * name, found no match, and silently returned the input unchanged.
     *
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateFilterPipe(array &$data, array $scan): mixed
    {
        if (!isset($scan['pipe'])) return self::NOT_MATCHED;

        [$baseExpr, $segments] = $scan['pipe'];
        $value = $this->evaluateExpression($baseExpr, $data);
        foreach ($segments as [$filterCall, $propPath]) {
            $value = $this->applyFilter($filterCall, $value, $data);
            if ($propPath === null) continue;

            // Traverse the property path via resolveVariable by staging the
            // filter result under a synthetic key. Reuses existing dotted +
            // bracketed path resolution rather than writing a second traversal
            // loop we'd have to keep in sync with resolveVariable's bugfixes.
            $tmpKey  = '__frond_filter_chain_result';
            $tmpData = [$tmpKey => $value];
            $value   = $this->resolveVariable($tmpKey . '.' . $propPath, $tmpData);
        }
        return $value;
    }

    /**
     * Find the first `.` position that sits outside any quote / paren /
     * bracket / brace. Used to cleanly split a filter segment like
     * `first.groupSummary` into the filter call and the property path.
     *
     * Quote handling mirrors splitFilters — backslash-escaped same-quote
     * doesn't close the string. Depth tracking covers (), [], {} so
     * `format(1.5)`, `slice(0, 1.2)`, and `date("Y.m.d")` don't trip.
     *
     * Returns false when there's no structural `.` — caller should treat
     * the whole segment as a plain filter.
     */
    private function findDotOutsideParens(string $expr): int|false
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len   = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($inStr) {
                if ($ch === $strCh && ($i === 0 || $expr[$i - 1] !== '\\')) {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = true;
                $strCh = $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                continue;
            }
            if ($ch === '.' && $depth === 0) {
                return $i;
            }
        }
        return false;
    }

    /* ── helper: string concatenation (~) ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateConcat(array &$data, array $scan): mixed
    {
        if (!isset($scan['concat'])) {
            return self::NOT_MATCHED;
        }
        $result = '';
        foreach ($scan['concat'] as $part) {
            $val = $this->evaluateExpression($part, $data);
            $str = $this->valueToString($val);
            if (is_string($val) && str_contains($val, self::RAW_MARKER)) {
                $str = str_replace(self::RAW_MARKER, '', $str);
            }
            $result .= $str;
        }
        return $result;
    }

    /* ── helper: arithmetic ── */

    /**
     * @param array<string, mixed> $scan Structural descriptor from exprScan().
     */
    private function evaluateArithmetic(array &$data, array $scan): mixed
    {
        if (isset($scan['arithmetic'])) {
            [$op, $left, $right] = $scan['arithmetic'];
            $leftVal = $this->evaluateExpression($left, $data);
            $rightVal = $this->evaluateExpression($right, $data);

            // Array merge for +
            if ($op === '+' && is_array($leftVal) && is_array($rightVal)) {
                return array_merge($leftVal, $rightVal);
            }

            return $this->applyMathOp(
                $op,
                is_numeric($leftVal) ? $leftVal : ($leftVal ?? 0),
                is_numeric($rightVal) ? $rightVal : ($rightVal ?? 0)
            );
        }

        // Fallback parenthesized expression (after operators)
        if (isset($scan['arithmeticParen'])) {
            return $this->evaluateExpression($scan['arithmeticParen'], $data);
        }

        return self::NOT_MATCHED;
    }

    /** Apply a single math operator to two numeric operands. */
    private function applyMathOp(string $op, mixed $l, mixed $r): int|float
    {
        return match($op) {
            '+'  => $l + $r,
            '-'  => $l - $r,
            '*'  => $l * $r,
            '/'  => $r != 0 ? $l / $r : 0,
            '//' => $r != 0 ? intdiv((int)$l, (int)$r) : 0,
            '%'  => $r != 0 ? $l % $r : 0,
            '**' => $l ** $r,
        };
    }

    /* ── helper: collection literals ── */

    private function evaluateCollectionLiteral(string $expr, array &$data): mixed
    {
        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return $this->parseArrayLiteral(substr($expr, 1, -1), $data);
        }
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}')) {
            return $this->parseDictLiteral(substr($expr, 1, -1), $data);
        }
        if (preg_match(self::RE_RANGE, $expr, $m)) {
            return range((int)$m[1], (int)$m[2]);
        }
        return self::NOT_MATCHED;
    }

    /* ── helper: function / macro calls ── */

    private function evaluateFunctionCall(string $expr, array &$data): mixed
    {
        if (!preg_match(self::RE_FUNC_CALL, $expr, $m)) {
            return self::NOT_MATCHED;
        }

        $funcName = $m[1];
        $argsStr = $m[2] ?? '';

        // Dotted function name: resolve object, then call method
        if (str_contains($funcName, '.')) {
            return $this->evaluateDottedCall($funcName, $argsStr, $data);
        }

        if (isset($this->macros[$funcName])) {
            return $this->callMacro($funcName, $argsStr, $data);
        }

        if (isset($data[$funcName]) && is_callable($data[$funcName])) {
            $args = trim($argsStr) !== '' ? $this->parseFilterArgs($argsStr, $data) : [];
            return ($data[$funcName])(...$args);
        }

        if ($funcName === 'range') {
            $args = $this->parseArgs($argsStr, $data);
            if (count($args) >= 2) {
                return range($args[0], $args[1], $args[2] ?? 1);
            }
        }

        return self::NOT_MATCHED;
    }

    /** Resolve a dotted method call like user.t("key"). */
    private function evaluateDottedCall(string $funcName, string $argsStr, array &$data): mixed
    {
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
            // Slice marker from parseVarPath: ['__slice__', start, end]
            if (is_array($seg) && ($seg[0] ?? null) === '__slice__') {
                if (is_array($current)) {
                    $current = array_slice($current, $seg[1] ?? 0, $seg[2] !== null ? ($seg[2] - ($seg[1] ?? 0)) : null);
                } elseif (is_string($current)) {
                    $current = $seg[2] !== null
                        ? substr($current, $seg[1] ?? 0, $seg[2] - ($seg[1] ?? 0))
                        : substr($current, $seg[1] ?? 0);
                } else {
                    return '';
                }
                continue;
            }
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
            $this->capMemoCache($this->dottedSplitCache);
            return $this->dottedSplitCache[$expr] = explode('.', $expr);
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
                // Slice syntax: value[1:5], value[:10], value[start:end]
                $isQuoted = (str_starts_with($idx, '"') && str_ends_with($idx, '"')) ||
                            (str_starts_with($idx, "'") && str_ends_with($idx, "'"));
                if (str_contains($idx, ':') && !$isQuoted) {
                    $sliceParts = explode(':', $idx, 2);
                    $sStart = trim($sliceParts[0]) !== '' ? (int)$this->evaluateExpression(trim($sliceParts[0]), $data) : null;
                    $sEnd = trim($sliceParts[1]) !== '' ? (int)$this->evaluateExpression(trim($sliceParts[1]), $data) : null;
                    // Use a special marker so resolveVariable can apply slicing
                    $segments[] = ['__slice__', $sStart, $sEnd];
                    $pos = $end + 1;
                } elseif ($isQuoted) {
                    // Quoted string literal — strip quotes
                    $idx = substr($idx, 1, -1);
                    $segments[] = $idx;
                    $pos = $end + 1;
                } elseif (is_numeric($idx)) {
                    $idx = (int)$idx;
                    $segments[] = $idx;
                    $pos = $end + 1;
                } else {
                    // Evaluate as an expression (supports e.g. arr[i % 2])
                    $idx = $this->evaluateExpression($idx, $data);
                    $segments[] = $idx;
                    $pos = $end + 1;
                }
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
        // Fast path. This is the hottest function in a render -- profiling the
        // Python twin showed 415,200 calls and 53% of render time for a single
        // 20-row loop template, and the overwhelming majority return "not found"
        // because the needle simply is not in the expression. str_contains is a
        // C-level scan, so bailing here skips the whole PHP character loop.
        // Exact, not a heuristic: a needle absent from the string cannot be
        // present outside quotes either.
        if (!str_contains($expr, $needle)) {
            return false;
        }

        $inQ = null;
        $depth = 0;
        $bracketDepth = 0;
        $len = strlen($expr);
        $nLen = strlen($needle);
        for ($i = 0; $i <= $len - $nLen; $i++) {
            $ch = $expr[$i];
            if (($ch === '"' || $ch === "'") && $depth === 0 && $bracketDepth === 0) {
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
            if ($ch === '[') { $bracketDepth++; }
            elseif ($ch === ']') { $bracketDepth--; }
            if ($depth === 0 && $bracketDepth === 0 && substr($expr, $i, $nLen) === $needle) return $i;
        }
        return false;
    }

    /**
     * Split $expr on $sep only when $sep is outside quotes and parentheses.
     */
    private function splitOutsideQuotes(string $expr, string $sep): array
    {
        $parts = [];
        // Fast path, same reasoning as findOutsideQuotes: no separator anywhere
        // means no split, and str_contains is a C-level scan.
        if (!str_contains($expr, $sep)) {
            return [$expr];
        }

        $currentStart = 0;
        $inQ = null;
        $depth = 0;
        $bracketDepth = 0;
        $len = strlen($expr);
        $sepLen = strlen($sep);
        for ($i = 0; $i <= $len - $sepLen; $i++) {
            $ch = $expr[$i];
            if (($ch === '"' || $ch === "'") && $depth === 0 && $bracketDepth === 0) {
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
            if ($ch === '[') { $bracketDepth++; }
            elseif ($ch === ']') { $bracketDepth--; }
            if ($depth === 0 && $bracketDepth === 0 && substr($expr, $i, $sepLen) === $sep) {
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
                // For *, make sure we don't match **
                if ($op === '*' && $opLen === 1) {
                    if ($i + 1 < $len && $expr[$i + 1] === '*') continue;
                    if ($i > 0 && $expr[$i - 1] === '*') continue;
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

        // Sandbox check — a blocked filter is silently SKIPPED: the value passes
        // through unchanged (parity with the Python master + Ruby + Node). It used
        // to return '' (fail-closed); that diverged from the other three. Both are
        // secure (the blocked filter's code never runs); we converge on the master.
        if ($this->sandboxed && $this->sandboxFilters !== null && !in_array($filterName, $this->sandboxFilters)) {
            return $value;
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
                $key = ($m[1] !== '') ? $m[2] : $m[3];
                $val = $this->evaluateExpression(trim($m[4]), $data);
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
        // Macro output is already-rendered HTML — mark as raw so auto-escape
        // doesn't double-encode it when the call is used in an expression.
        return self::RAW_MARKER . $this->execute($macro['body'], $macroData);
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
        $this->filters['replace'] = function($v, $from, $to = null) {
            // Twig-style: replace({"old": "new"}) — dict as first arg
            if (is_array($from)) {
                return str_replace(array_keys($from), array_values($from), (string)$v);
            }
            // Positional: replace("old", "new")
            return str_replace($from, $to ?? '', (string)$v);
        };
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
        // Twig signature: number_format(decimals=0, decimalPoint='.', thousandsSep=',')
        // Defaults keep the current output ('1,234.50'); passing the 3rd/4th args
        // enables localized formats, e.g. number_format(2, ',', '.') -> '1.234,50'.
        $this->filters['number_format'] = fn($v, $d = 0, $decimalPoint = '.', $thousandsSep = ',')
            => number_format((float)$v, (int)$d, (string)$decimalPoint, (string)$thousandsSep);

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
        // dump filter — gated on TINA4_DEBUG=true. Delegates to the shared
        // renderDump() helper so the |dump filter and the dump() global
        // function produce identical output and obey the same gating.
        $this->filters['dump'] = fn($v) => self::renderDump($v);
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

    /**
     * Render a value as a pre-formatted var_dump wrapped in <pre> tags.
     *
     * Gated on TINA4_DEBUG=true. In production (TINA4_DEBUG unset or false)
     * dump output is suppressed entirely to avoid leaking internal state,
     * object shapes, or sensitive values into rendered HTML.
     *
     * Shared by the {{ value|dump }} filter and the {{ dump(value) }} global
     * function so both produce identical output and obey the same gating.
     *
     * Returned string is prefixed with self::RAW_MARKER so the template
     * engine's auto-escape pass skips the HTML entities we produce.
     *
     * @param mixed $v Value to dump
     * @return string  <pre>...</pre> in debug mode, empty string in production
     */
    public static function renderDump(mixed $v): string
    {
        $debugMode = strtolower(getenv('TINA4_DEBUG') ?: '') === 'true';
        if (!$debugMode) {
            return '';
        }
        ob_start();
        var_dump($v);
        return self::RAW_MARKER . '<pre>' . htmlspecialchars((string)ob_get_clean(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    private function registerBuiltinGlobals(): void
    {
        // Shared JWT generation logic used by both formToken and formTokenValue
        $generateFormJwt = static function (string $descriptor = ''): string {
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

            if (!isset($_ENV['TINA4_SECRET']) && !getenv('TINA4_SECRET')) {
                $_ENV['TINA4_SECRET'] = DotEnv::getEnv('TINA4_SECRET') ?? 'tina4-default-secret';
            }
            $ttlMinutes = (int)(DotEnv::getEnv('TINA4_TOKEN_LIMIT', '60') ?? '60');
            $expiresIn = $ttlMinutes * 60;
            return Auth::getToken($payload, $expiresIn);
        };

        // formToken / form_token — returns full <input> element
        $formTokenFn = static function (string $descriptor = '') use ($generateFormJwt): string {
            $token = $generateFormJwt($descriptor);
            return self::RAW_MARKER . '<input type="hidden" name="formToken" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        };

        // formTokenValue / form_token_value — returns just the raw JWT string
        $formTokenValueFn = static function (string $descriptor = '') use ($generateFormJwt): string {
            return $generateFormJwt($descriptor);
        };

        $this->globals['formToken'] = $formTokenFn;
        $this->globals['form_token'] = $formTokenFn;
        $this->globals['formTokenValue'] = $formTokenValueFn;
        $this->globals['form_token_value'] = $formTokenValueFn;

        // Debug helper: {{ dump(x) }} — gated on TINA4_DEBUG=true.
        // Both this global and the |dump filter call self::renderDump()
        // which returns an empty string in production.
        $this->globals['dump'] = static fn($v = null) => self::renderDump($v);

        // Also register as filters so {{ "" | formToken }} and {{ "" | form_token }} work
        $this->filters['formToken'] = fn($v) => $formTokenFn((string)($v ?: ''));
        $this->filters['form_token'] = fn($v) => $formTokenFn((string)($v ?: ''));
        $this->filters['formTokenValue'] = fn($v) => $formTokenValueFn((string)($v ?: ''));
        $this->filters['form_token_value'] = fn($v) => $formTokenValueFn((string)($v ?: ''));

        // HTML-safe JSON dump (escapes <, >, & as unicode escapes)
        $this->filters['to_json'] = fn($v) => self::RAW_MARKER . str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'], json_encode($v));
        $this->filters['tojson'] = &$this->filters['to_json'];

        // Escape for safe embedding in JavaScript strings (marked raw to bypass auto-escaping)
        $this->filters['js_escape'] = fn($v) => self::RAW_MARKER . str_replace(["\\", "'", '"', "\n", "\r"], ["\\\\", "\\'", '\\"', "\\n", "\\r"], (string)$v);
    }
}
