<?php

namespace Tina4;

/**
 * Code Metrics — token-based static analysis for the dev dashboard.
 *
 * Two-tier analysis:
 *   1. Quick metrics (instant): LOC, file counts, class/function counts
 *   2. Full analysis (on-demand, cached): cyclomatic complexity, maintainability
 *      index, coupling, Halstead metrics, violations
 *
 * Zero dependencies — uses PHP's built-in token_get_all().
 */
class Metrics
{
    /** @var array{hash: string, data: ?array, time: float} */
    private static array $fullCache = ["hash" => "", "data" => null, "time" => 0];

    private const CACHE_TTL = 60;

    // ── Quick Metrics ──────────────────────────────────────────────

    /**
     * Scan project files and return instant metrics.
     *
     * @param string $root Root directory to scan
     * @return array
     */
    public static function quickMetrics(string $root = "src"): array
    {
        $rootPath = realpath($root);
        if ($rootPath === false || !is_dir($rootPath)) {
            return ["error" => "Directory not found: {$root}"];
        }

        $phpFiles = self::globRecursive($rootPath, "*.php");
        $twigFiles = array_merge(
            self::globRecursive($rootPath, "*.twig"),
            self::globRecursive($rootPath, "*.html")
        );

        $migrationsDir = realpath("migrations");
        $sqlFiles = [];
        if ($migrationsDir !== false && is_dir($migrationsDir)) {
            $sqlFiles = array_merge(
                self::globRecursive($migrationsDir, "*.sql"),
                self::globRecursive($migrationsDir, "*.php")
            );
        }

        $scssFiles = array_merge(
            self::globRecursive($rootPath, "*.scss"),
            self::globRecursive($rootPath, "*.css")
        );

        $totalLoc = 0;
        $totalBlank = 0;
        $totalComment = 0;
        $totalClasses = 0;
        $totalFunctions = 0;
        $fileDetails = [];

        foreach ($phpFiles as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                continue;
            }

            $lines = explode("\n", $source);
            $loc = 0;
            $blank = 0;
            $comment = 0;
            $inBlockComment = false;

            foreach ($lines as $line) {
                $stripped = trim($line);

                if ($stripped === "") {
                    $blank++;
                    continue;
                }

                if ($inBlockComment) {
                    $comment++;
                    if (str_contains($stripped, "*/")) {
                        $inBlockComment = false;
                    }
                    continue;
                }

                if (str_starts_with($stripped, "/*")) {
                    $comment++;
                    if (!str_contains($stripped, "*/")) {
                        $inBlockComment = true;
                    }
                    continue;
                }

                if (str_starts_with($stripped, "//") || str_starts_with($stripped, "#")) {
                    $comment++;
                    continue;
                }

                $loc++;
            }

            // Count classes and functions via tokens
            $tokens = @token_get_all($source);
            $classes = 0;
            $functions = 0;
            if (is_array($tokens)) {
                for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                    if (!is_array($tokens[$i])) {
                        continue;
                    }
                    if ($tokens[$i][0] === T_CLASS) {
                        // Skip anonymous classes by checking if preceded by 'new'
                        $prev = self::findPrevMeaningfulToken($tokens, $i);
                        if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                            continue;
                        }
                        $classes++;
                    } elseif ($tokens[$i][0] === T_FUNCTION) {
                        $functions++;
                    }
                }
            }

            $totalLoc += $loc;
            $totalBlank += $blank;
            $totalComment += $comment;
            $totalClasses += $classes;
            $totalFunctions += $functions;

            $relPath = self::relativePath($file);
            $fileDetails[] = [
                "path" => $relPath,
                "loc" => $loc,
                "blank" => $blank,
                "comment" => $comment,
                "classes" => $classes,
                "functions" => $functions,
            ];
        }

        // Sort by LOC descending
        usort($fileDetails, fn($a, $b) => $b["loc"] - $a["loc"]);

        // Route and ORM counts
        $routeCount = 0;
        $ormCount = 0;
        try {
            if (class_exists("\\Tina4\\Router") && isset(\Tina4\Router::$routes)) {
                $routeCount = count(\Tina4\Router::$routes);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            if (class_exists("\\Tina4\\ORM")) {
                // Count classes that extend ORM
                foreach ($phpFiles as $file) {
                    $source = @file_get_contents($file);
                    if ($source !== false && preg_match('/extends\s+ORM\b/i', $source)) {
                        $ormCount++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $breakdown = [
            "php" => count($phpFiles),
            "templates" => count($twigFiles),
            "migrations" => count($sqlFiles),
            "stylesheets" => count($scssFiles),
        ];

        $fileCount = count($phpFiles);

        return [
            "file_count" => $fileCount,
            "total_loc" => $totalLoc,
            "total_blank" => $totalBlank,
            "total_comment" => $totalComment,
            "lloc" => $totalLoc,
            "classes" => $totalClasses,
            "functions" => $totalFunctions,
            "route_count" => $routeCount,
            "orm_count" => $ormCount,
            "template_count" => count($twigFiles),
            "migration_count" => count($sqlFiles),
            "avg_file_size" => $fileCount > 0 ? round($totalLoc / $fileCount, 1) : 0,
            "largest_files" => array_slice($fileDetails, 0, 10),
            "breakdown" => $breakdown,
        ];
    }

    // ── Full Analysis (token-based) ─────────────────────────────────

    /**
     * Deep token-based analysis. Cached for 60 seconds.
     *
     * @param string $root Root directory to scan
     * @return array
     */
    public static function fullAnalysis(string $root = "src"): array
    {
        $currentHash = self::filesHash($root);
        $now = microtime(true);

        if (
            self::$fullCache["hash"] === $currentHash &&
            self::$fullCache["data"] !== null &&
            ($now - self::$fullCache["time"]) < self::CACHE_TTL
        ) {
            return self::$fullCache["data"];
        }

        $rootPath = realpath($root);
        if ($rootPath === false || !is_dir($rootPath)) {
            return ["error" => "Directory not found: {$root}"];
        }

        $phpFiles = self::globRecursive($rootPath, "*.php");

        $allFunctions = [];
        $fileMetrics = [];
        $importGraph = [];
        $reverseGraph = [];

        foreach ($phpFiles as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                continue;
            }

            $tokens = @token_get_all($source);
            if (!is_array($tokens)) {
                continue;
            }

            $relPath = self::relativePath($file);
            $lines = explode("\n", $source);
            $loc = 0;
            foreach ($lines as $line) {
                $stripped = trim($line);
                if ($stripped !== "" && !str_starts_with($stripped, "//") && !str_starts_with($stripped, "#")) {
                    $loc++;
                }
            }

            // Extract imports for coupling analysis
            $imports = self::extractImports($tokens);
            $importGraph[$relPath] = $imports;

            foreach ($imports as $imp) {
                if (!isset($reverseGraph[$imp])) {
                    $reverseGraph[$imp] = [];
                }
                $reverseGraph[$imp][] = $relPath;
            }

            // Analyze functions/methods
            $fileComplexity = 0;
            $fileFunctions = [];
            $halstead = ["operators" => 0, "operands" => 0, "unique_operators" => [], "unique_operands" => []];

            $parsed = self::parseFunctions($tokens, $lines);

            foreach ($parsed as $func) {
                $cc = $func["complexity"];
                $funcInfo = [
                    "name" => $func["name"],
                    "file" => $relPath,
                    "line" => $func["line"],
                    "complexity" => $cc,
                    "loc" => $func["loc"],
                ];
                $allFunctions[] = $funcInfo;
                $fileFunctions[] = $funcInfo;
                $fileComplexity += $cc;
            }

            // Halstead metrics for the entire file
            self::countHalstead($tokens, $halstead);

            $n1 = count(array_unique($halstead["unique_operators"]));
            $n2 = count(array_unique($halstead["unique_operands"]));
            $N1 = $halstead["operators"];
            $N2 = $halstead["operands"];
            $vocabulary = $n1 + $n2;
            $length = $N1 + $N2;
            $volume = $vocabulary > 0 ? $length * log($vocabulary, 2) : 0;

            // Maintainability index
            $avgCc = count($fileFunctions) > 0 ? $fileComplexity / count($fileFunctions) : 0;
            $mi = self::maintainabilityIndex($volume, $avgCc, $loc);

            // Coupling
            $ce = count($imports); // efferent
            $ca = count($reverseGraph[$relPath] ?? []); // afferent
            $instability = ($ca + $ce) > 0 ? $ce / ($ca + $ce) : 0.0;

            $fileMetrics[] = [
                "path" => $relPath,
                "loc" => $loc,
                "complexity" => $fileComplexity,
                "avg_complexity" => round($avgCc, 2),
                "functions" => count($fileFunctions),
                "maintainability" => round($mi, 1),
                "halstead_volume" => round($volume, 1),
                "coupling_afferent" => $ca,
                "coupling_efferent" => $ce,
                "instability" => round($instability, 3),
                "has_tests" => self::hasMatchingTest($relPath),
                "dep_count" => $ce,
            ];
        }

        // Sort by complexity descending
        usort($allFunctions, fn($a, $b) => $b["complexity"] - $a["complexity"]);
        usort($fileMetrics, fn($a, $b) => $a["maintainability"] <=> $b["maintainability"]);

        // Violations
        $violations = self::detectViolations($allFunctions, $fileMetrics);

        // Overall averages
        $totalCc = array_sum(array_column($allFunctions, "complexity"));
        $avgCc = count($allFunctions) > 0 ? $totalCc / count($allFunctions) : 0;
        $totalMi = array_sum(array_column($fileMetrics, "maintainability"));
        $avgMi = count($fileMetrics) > 0 ? $totalMi / count($fileMetrics) : 0;

        $result = [
            "files_analyzed" => count($fileMetrics),
            "total_functions" => count($allFunctions),
            "avg_complexity" => round($avgCc, 2),
            "avg_maintainability" => round($avgMi, 1),
            "most_complex_functions" => array_slice($allFunctions, 0, 15),
            "file_metrics" => $fileMetrics,
            "violations" => $violations,
            "dependency_graph" => $importGraph,
        ];

        self::$fullCache = ["hash" => $currentHash, "data" => $result, "time" => $now];
        return $result;
    }

    // ── File Detail ─────────────────────────────────────────────────

    /**
     * Detailed metrics for a single file.
     *
     * @param string $filePath Path to the file
     * @return array
     */
    public static function fileDetail(string $filePath): array
    {
        // Normalize path separators — paths coming from the browser are always
        // forward-slash even on Windows, but the filesystem may need either.
        $filePath = str_replace('\\', '/', $filePath);
        if (!file_exists($filePath)) {
            // Also try with native directory separator as a fallback
            $native = str_replace('/', DIRECTORY_SEPARATOR, $filePath);
            if (!file_exists($native)) {
                return ["error" => "File not found: {$filePath}"];
            }
            $filePath = $native;
        }

        $source = @file_get_contents($filePath);
        if ($source === false) {
            return ["error" => "Cannot read file: {$filePath}"];
        }

        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return ["error" => "Cannot parse file: {$filePath}"];
        }

        $lines = explode("\n", $source);
        $loc = 0;
        foreach ($lines as $line) {
            $stripped = trim($line);
            if ($stripped !== "" && !str_starts_with($stripped, "//") && !str_starts_with($stripped, "#")) {
                $loc++;
            }
        }

        $parsed = self::parseFunctions($tokens, $lines);
        $functions = [];
        foreach ($parsed as $func) {
            $functions[] = [
                "name" => $func["name"],
                "line" => $func["line"],
                "complexity" => $func["complexity"],
                "loc" => $func["loc"],
                "args" => $func["args"],
            ];
        }

        // Sort by complexity descending
        usort($functions, fn($a, $b) => $b["complexity"] - $a["complexity"]);

        // Count classes and detect empty ones
        $classes = 0;
        $warnings = [];
        $tokenCount = count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) {
                continue;
            }
            $prev = self::findPrevMeaningfulToken($tokens, $i);
            if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                continue;
            }
            $classes++;
            // Find opening brace of class body
            $depth = 0;
            $bodyStart = null;
            $hasBody = false;
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $t = $tokens[$j];
                if ($t === '{') {
                    if ($depth === 0) {
                        $bodyStart = $j;
                    }
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        // Scan between bodyStart and $j for non-whitespace tokens
                        for ($k = $bodyStart + 1; $k < $j; $k++) {
                            if (is_array($tokens[$k]) && $tokens[$k][0] !== T_WHITESPACE && $tokens[$k][0] !== T_COMMENT && $tokens[$k][0] !== T_DOC_COMMENT) {
                                $hasBody = true;
                                break;
                            }
                        }
                        if (!$hasBody) {
                            $nameToken = self::findNextMeaningfulToken($tokens, $i);
                            $className = is_array($nameToken) ? $nameToken[1] : '(anonymous)';
                            $line = is_array($tokens[$i]) ? $tokens[$i][2] : 0;
                            $warnings[] = ["type" => "empty_class", "message" => "Class '{$className}' has no body", "line" => $line];
                        }
                        break;
                    }
                }
            }
        }

        // Warn on empty methods (loc === 0 or only braces)
        foreach ($functions as $fn) {
            if ($fn["loc"] <= 1) {
                $warnings[] = ["type" => "empty_method", "message" => "Method '{$fn['name']}' appears to be empty", "line" => $fn["line"]];
            }
        }

        // Extract imports
        $imports = self::extractImports($tokens);

        return [
            "path" => $filePath,
            "loc" => $loc,
            "total_lines" => count($lines),
            "classes" => $classes,
            "functions" => $functions,
            "imports" => $imports,
            "warnings" => $warnings,
        ];
    }

    // ── Token Helpers ────────────────────────────────────────────────

    /**
     * Parse all functions/methods from tokens and compute their complexity and LOC.
     *
     * @param array $tokens Token array from token_get_all()
     * @param array $lines  Source lines
     * @return array List of function info arrays
     */
    private static function parseFunctions(array $tokens, array $lines): array
    {
        $results = [];
        $tokenCount = count($tokens);
        $classStack = []; // track current class name

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            // Track class names for method qualification
            if ($token[0] === T_CLASS) {
                $prev = self::findPrevMeaningfulToken($tokens, $i);
                if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                    continue;
                }
                $nameToken = self::findNextMeaningfulToken($tokens, $i);
                if ($nameToken !== null && is_array($nameToken) && $nameToken[0] === T_STRING) {
                    // Find the opening brace to track class scope
                    $classStack[] = [
                        "name" => $nameToken[1],
                        "brace_depth" => null, // set when we find the opening brace
                    ];
                }
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }

            // Get function name
            $nameToken = self::findNextMeaningfulToken($tokens, $i);
            if ($nameToken === null) {
                continue;
            }

            // Anonymous function (closure) — skip
            if (!is_array($nameToken) || $nameToken[0] !== T_STRING) {
                continue;
            }

            $funcName = $nameToken[1];
            $funcLine = $token[2];

            // Qualify with class name if inside a class
            $className = self::getCurrentClassName($tokens, $i);
            if ($className !== null) {
                $funcName = "{$className}.{$funcName}";
            }

            // Find function body boundaries (opening and closing braces)
            $bodyStart = null;
            $bodyEnd = null;
            $braceDepth = 0;
            $foundOpen = false;

            // Collect argument names
            $args = [];
            $inArgs = false;
            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $t = $tokens[$j];
                if (!is_array($t) && $t === "(") {
                    $inArgs = true;
                    continue;
                }
                if (!is_array($t) && $t === ")" && $inArgs) {
                    $inArgs = false;
                    continue;
                }
                if ($inArgs && is_array($t) && $t[0] === T_VARIABLE) {
                    $argName = ltrim($t[1], '$');
                    if ($argName !== "this") {
                        $args[] = $argName;
                    }
                }
                if (!is_array($t) && $t === "{") {
                    $bodyStart = $j;
                    $braceDepth = 1;
                    $foundOpen = true;
                    break;
                }
            }

            if (!$foundOpen) {
                continue; // abstract or interface method
            }

            // Find closing brace
            for ($j = $bodyStart + 1; $j < $tokenCount; $j++) {
                $t = $tokens[$j];
                if (!is_array($t)) {
                    if ($t === "{") {
                        $braceDepth++;
                    } elseif ($t === "}") {
                        $braceDepth--;
                        if ($braceDepth === 0) {
                            $bodyEnd = $j;
                            break;
                        }
                    }
                }
            }

            if ($bodyEnd === null) {
                continue;
            }

            // Calculate LOC for the function
            $startLine = $funcLine;
            $endLine = $funcLine;
            // Find the line number of the closing brace
            for ($j = $bodyEnd; $j >= $bodyStart; $j--) {
                if (is_array($tokens[$j]) && isset($tokens[$j][2])) {
                    $endLine = $tokens[$j][2];
                    break;
                }
            }
            $funcLoc = max(1, $endLine - $startLine + 1);

            // Calculate cyclomatic complexity for this function's tokens
            $funcTokens = array_slice($tokens, $bodyStart, $bodyEnd - $bodyStart + 1);
            $cc = self::cyclomaticComplexity($funcTokens);

            $results[] = [
                "name" => $funcName,
                "line" => $funcLine,
                "complexity" => $cc,
                "loc" => $funcLoc,
                "args" => $args,
            ];
        }

        return $results;
    }

    /**
     * Calculate cyclomatic complexity from a slice of tokens.
     *
     * CC = 1 + count of: if, elseif, case, for, foreach, while, catch, &&, ||, and, or, ??, ternary ?
     *
     * @param array $tokens Token slice
     * @return int
     */
    private static function cyclomaticComplexity(array $tokens): int
    {
        $cc = 1;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                switch ($token[0]) {
                    case T_IF:
                    case T_ELSEIF:
                    case T_CASE:
                    case T_FOR:
                    case T_FOREACH:
                    case T_WHILE:
                    case T_CATCH:
                        $cc++;
                        break;
                    case T_BOOLEAN_AND: // &&
                    case T_BOOLEAN_OR:  // ||
                    case T_LOGICAL_AND: // and
                    case T_LOGICAL_OR:  // or
                        $cc++;
                        break;
                    case T_COALESCE: // ??
                        $cc++;
                        break;
                }
            } else {
                // Ternary operator ?
                if ($token === "?") {
                    // Make sure it's not the null coalesce ?? (already handled)
                    // or nullable type hint ?Type
                    if ($i + 1 < $count) {
                        $next = $tokens[$i + 1];
                        if (!is_array($next) && $next === "?") {
                            continue; // part of ?? which is handled by T_COALESCE
                        }
                    }
                    // Check it's not a nullable type (preceded by : or ( in parameter context)
                    $prev = null;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                            continue;
                        }
                        $prev = $tokens[$j];
                        break;
                    }
                    // If previous token is a colon, it might be a return type; skip
                    if ($prev !== null && !is_array($prev) && $prev === ":") {
                        continue;
                    }
                    $cc++;
                }
            }
        }

        return $cc;
    }

    /**
     * Count Halstead operators and operands from tokens.
     *
     * @param array $tokens All tokens
     * @param array &$stats Stats accumulator
     */
    private static function countHalstead(array $tokens, array &$stats): void
    {
        $operatorTokens = [
            T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
            T_CONCAT_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
            T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
            T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
            T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL,
            T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP,
            T_SL, T_SR, T_POW, T_COALESCE, T_INC, T_DEC, T_DOUBLE_ARROW,
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
        ];

        $operandTokens = [
            T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE, T_STRING,
        ];

        $charOperators = ["+", "-", "*", "/", "%", ".", "=", "<", ">", "!", "~", "^", "&", "|"];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $operatorTokens, true)) {
                    $stats["operators"]++;
                    $stats["unique_operators"][] = token_name($token[0]);
                } elseif (in_array($token[0], $operandTokens, true)) {
                    $stats["operands"]++;
                    $value = substr($token[1], 0, 50);
                    $stats["unique_operands"][] = $value;
                }
            } else {
                if (in_array($token, $charOperators, true)) {
                    $stats["operators"]++;
                    $stats["unique_operators"][] = $token;
                }
            }
        }
    }

    /**
     * Calculate Maintainability Index (0-100 scale).
     *
     * MI = max(0, (171 - 5.2 * ln(V) - 0.23 * CC - 16.2 * ln(LOC)) * 100 / 171)
     *
     * @param float $halsteadVolume Halstead volume
     * @param float $avgCc          Average cyclomatic complexity
     * @param int   $loc            Lines of code
     * @return float
     */
    private static function maintainabilityIndex(float $halsteadVolume, float $avgCc, int $loc): float
    {
        if ($loc <= 0) {
            return 100.0;
        }
        $v = max($halsteadVolume, 1);
        $mi = 171 - 5.2 * log($v) - 0.23 * $avgCc - 16.2 * log($loc);
        return max(0.0, min(100.0, $mi * 100 / 171));
    }

    /**
     * Detect code quality violations.
     *
     * @param array $functions  All function metrics
     * @param array $fileMetrics All file metrics
     * @return array
     */
    private static function detectViolations(array $functions, array $fileMetrics): array
    {
        $violations = [];

        foreach ($functions as $f) {
            if ($f["complexity"] > 20) {
                $violations[] = [
                    "type" => "error",
                    "rule" => "high_complexity",
                    "message" => "{$f['name']} has cyclomatic complexity {$f['complexity']} (max 20)",
                    "file" => $f["file"],
                    "line" => $f["line"],
                ];
            } elseif ($f["complexity"] > 10) {
                $violations[] = [
                    "type" => "warning",
                    "rule" => "moderate_complexity",
                    "message" => "{$f['name']} has cyclomatic complexity {$f['complexity']} (recommended max 10)",
                    "file" => $f["file"],
                    "line" => $f["line"],
                ];
            }
        }

        foreach ($fileMetrics as $fm) {
            if ($fm["loc"] > 500) {
                $violations[] = [
                    "type" => "warning",
                    "rule" => "large_file",
                    "message" => "{$fm['path']} has {$fm['loc']} LOC (recommended max 500)",
                    "file" => $fm["path"],
                    "line" => 1,
                ];
            }
            if ($fm["functions"] > 20) {
                $violations[] = [
                    "type" => "warning",
                    "rule" => "too_many_functions",
                    "message" => "{$fm['path']} has {$fm['functions']} functions (recommended max 20)",
                    "file" => $fm["path"],
                    "line" => 1,
                ];
            }
            if ($fm["maintainability"] < 20) {
                $violations[] = [
                    "type" => "error",
                    "rule" => "low_maintainability",
                    "message" => "{$fm['path']} has maintainability index {$fm['maintainability']} (min 20)",
                    "file" => $fm["path"],
                    "line" => 1,
                ];
            } elseif ($fm["maintainability"] < 40) {
                $violations[] = [
                    "type" => "warning",
                    "rule" => "moderate_maintainability",
                    "message" => "{$fm['path']} has maintainability index {$fm['maintainability']} (recommended min 40)",
                    "file" => $fm["path"],
                    "line" => 1,
                ];
            }
        }

        // Sort: errors first, then by file
        usort($violations, function ($a, $b) {
            $typeOrder = ($a["type"] === "error" ? 0 : 1) - ($b["type"] === "error" ? 0 : 1);
            if ($typeOrder !== 0) {
                return $typeOrder;
            }
            return strcmp($a["file"], $b["file"]);
        });

        return $violations;
    }

    /**
     * Extract import targets from tokens (use statements, require/include).
     *
     * @param array $tokens Token array
     * @return array List of imported names/paths
     */
    private static function extractImports(array $tokens): array
    {
        $imports = [];
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            // use statements
            if ($token[0] === T_USE) {
                $name = "";
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $t = $tokens[$j];
                    if (!is_array($t)) {
                        if ($t === ";" || $t === "{") {
                            break;
                        }
                        if ($t === ",") {
                            if (trim($name) !== "") {
                                $imports[] = trim($name);
                            }
                            $name = "";
                            continue;
                        }
                    }
                    if (is_array($t)) {
                        if ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED || $t[0] === T_NS_SEPARATOR) {
                            $name .= $t[1];
                        } elseif ($t[0] === T_AS) {
                            // Stop collecting name at 'as'
                            break;
                        }
                    }
                }
                $name = trim($name);
                if ($name !== "") {
                    $imports[] = $name;
                }
            }

            // require, include, require_once, include_once
            if (in_array($token[0], [T_REQUIRE, T_INCLUDE, T_REQUIRE_ONCE, T_INCLUDE_ONCE], true)) {
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $imports[] = trim($t[1], "\"'");
                        break;
                    }
                    if (!is_array($t) && $t === ";") {
                        break;
                    }
                }
            }

            // new ClassName references for coupling analysis
            if ($token[0] === T_NEW) {
                $nameToken = self::findNextMeaningfulToken($tokens, $i);
                if ($nameToken !== null && is_array($nameToken)) {
                    if (in_array($nameToken[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $className = $nameToken[1];
                        if ($className !== "self" && $className !== "static" && $className !== "parent") {
                            $imports[] = $className;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * Get the current class name at a given token position by walking backwards.
     *
     * @param array $tokens All tokens
     * @param int   $pos    Current position
     * @return string|null
     */
    private static function getCurrentClassName(array $tokens, int $pos): ?string
    {
        $braceDepth = 0;

        for ($i = $pos - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                if ($t === "}") {
                    $braceDepth++;
                } elseif ($t === "{") {
                    $braceDepth--;
                    if ($braceDepth < 0) {
                        // We've exited a scope — look for 'class' keyword before this brace
                        for ($j = $i - 1; $j >= 0; $j--) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                                continue;
                            }
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                // Check if preceded by T_CLASS
                                for ($k = $j - 1; $k >= 0; $k--) {
                                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                                        continue;
                                    }
                                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_CLASS) {
                                        return $tokens[$j][1];
                                    }
                                    break;
                                }
                                // Could be after implements/extends list — keep searching
                                // Look for class keyword before the name
                                for ($k = $j - 1; $k >= 0; $k--) {
                                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_CLASS) {
                                        // Find the class name (next T_STRING after T_CLASS)
                                        for ($m = $k + 1; $m < $i; $m++) {
                                            if (is_array($tokens[$m]) && $tokens[$m][0] === T_STRING) {
                                                return $tokens[$m][1];
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        }
                        return null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find the previous meaningful (non-whitespace) token before position.
     *
     * @param array $tokens All tokens
     * @param int   $pos    Current position
     * @return mixed|null
     */
    private static function findPrevMeaningfulToken(array $tokens, int $pos): mixed
    {
        for ($i = $pos - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_COMMENT) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
                continue;
            }
            return $tokens[$i];
        }
        return null;
    }

    /**
     * Find the next meaningful (non-whitespace) token after position.
     *
     * @param array $tokens All tokens
     * @param int   $pos    Current position
     * @return mixed|null
     */
    private static function findNextMeaningfulToken(array $tokens, int $pos): mixed
    {
        $count = count($tokens);
        for ($i = $pos + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_COMMENT) {
                continue;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
                continue;
            }
            return $tokens[$i];
        }
        return null;
    }

    // ── Filesystem Helpers ──────────────────────────────────────────

    /**
     * Recursively glob for files matching a pattern.
     *
     * @param string $dir     Base directory
     * @param string $pattern Glob pattern (e.g. "*.php")
     * @return array List of absolute file paths
     */
    private static function globRecursive(string $dir, string $pattern): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * Hash of all file mtimes for cache invalidation.
     *
     * @param string $root Root directory
     * @return string MD5 hash
     */
    private static function filesHash(string $root): string
    {
        $rootPath = realpath($root);
        if ($rootPath === false || !is_dir($rootPath)) {
            return md5("");
        }

        $parts = [];
        $files = self::globRecursive($rootPath, "*.php");
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false) {
                $parts[] = "{$file}:{$mtime}";
            }
        }

        return md5(implode("|", $parts));
    }

    /**
     * Get a path relative to the current working directory.
     *
     * @param string $absolutePath Absolute file path
     * @return string Relative path
     */
    private static function relativePath(string $absolutePath): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($absolutePath, $cwd)) {
            $rel = substr($absolutePath, strlen($cwd));
            $rel = ltrim($rel, '/\\');
        } else {
            $rel = $absolutePath;
        }
        // Always use forward slashes so paths are consistent across platforms
        return str_replace('\\', '/', $rel);
    }

    /**
     * Check whether a matching test file exists for a given source file.
     *
     * @param string $relPath Relative path (e.g. "Tina4/Auth.php")
     * @return bool
     */
    private static function hasMatchingTest(string $relPath): bool
    {
        $name = pathinfo($relPath, PATHINFO_FILENAME);
        $patterns = [
            "tests/{$name}Test.php",
            "tests/{$name}V3Test.php",
            "tests/test_{$name}.php",
        ];
        foreach ($patterns as $p) {
            if (file_exists($p)) {
                return true;
            }
        }
        return false;
    }
}
