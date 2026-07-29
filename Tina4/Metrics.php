<?php

namespace Tina4;

/**
 * Code Metrics — one engine, per ADR-0002.
 *
 * Two tiers, two different jobs:
 *
 *   1. Quick metrics (instant): a FILE CENSUS. Line, file and template counts,
 *      plus a cheap token count of classes and functions. No complexity is
 *      computed. It stays in-process because the dashboard calls it on every
 *      load and the native engine takes about a second on a 100-file tree.
 *   2. Full analysis, offenders and per-file detail: the NATIVE ENGINE
 *      (`tina4 metrics --json`). One tree-sitter implementation covering every
 *      language, so a number measured in PHP is comparable with the same number
 *      measured in Python, Ruby or Node.
 *
 * The hand-rolled token analyzer that used to live here is GONE (1442 lines). It
 * duplicated the engine and, because each framework had its own, the four
 * frameworks reported numbers that could not be compared - which silently
 * undermined every cross-framework comparison built on them.
 *
 * There is NO FALLBACK. A missing or broken CLI THROWS MetricsEngineException
 * naming the fix. Degrading to a second implementation is what produced
 * incomparable numbers in the first place; a loud failure is honest where a
 * quiet substitution is not.
 */
class Metrics
{
    /** Stores the resolved scan root so fileDetail() can locate framework files. */
    private static string $lastScanRoot = "";

    private const CACHE_TTL = 60;

    // ── Root Resolution ───────────────────────────────────────────

    /**
     * Pick the right directory to scan.
     *
     * If src/ has PHP files, scan the user's project code.
     * Otherwise, scan the framework itself — so the bubble chart is never empty.
     *
     * @param string $root Default root directory
     * @return string Resolved root path
     */
    private static function resolveRoot(string $root = "src"): string
    {
        $rootPath = realpath($root);
        if ($rootPath !== false && is_dir($rootPath) && count(self::globRecursive($rootPath, "*.php")) > 0) {
            self::$lastScanRoot = $rootPath;
            return $root;
        }
        // Fallback: scan the framework package itself
        self::$lastScanRoot = __DIR__;
        return __DIR__;
    }

    /**
     * Return [directory to scan, scan mode] for any metrics producer.
     *
     * The CLI engine is language-agnostic and cannot know which directory holds a
     * framework package, so root resolution and the "framework" label stay here.
     * Mirrors resolve_scan_target() in the Python master.
     *
     * @param string $root Root directory to scan
     * @return array{0: string, 1: string}
     */
    public static function resolveScanTarget(string $root = "src"): array
    {
        $resolved = self::resolveRoot($root);
        $frameworkDir = __DIR__;
        $resolvedReal = realpath($resolved) ?: $resolved;
        $scanningFramework = $resolvedReal === $frameworkDir || str_starts_with($resolvedReal, $frameworkDir);
        return [$resolved, $scanningFramework ? "framework" : "project"];
    }

    // ── Quick Metrics ──────────────────────────────────────────────

    /**
     * Scan project files and return instant metrics.
     *
     * @param string $root Root directory to scan
     * @return array
     */
    public static function quickMetrics(string $root = "src"): array
    {
        $root = self::resolveRoot($root);
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

            $relPath = self::relativePath($file, $rootPath);
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
     * Get a path relative to the given root directory.
     *
     * @param string $absolutePath Absolute file path
     * @param string $root         Scan root (absolute or relative)
     * @return string Relative path
     */
    private static function relativePath(string $absolutePath, string $root = ""): string
    {
        $base = $root !== "" ? (realpath($root) ?: $root) : (getcwd() ?: "");
        if ($base !== "" && str_starts_with($absolutePath, $base)) {
            $rel = substr($absolutePath, strlen($base));
            $rel = ltrim($rel, '/\\');
        } else {
            $rel = $absolutePath;
        }
        // Always use forward slashes so paths are consistent across platforms
        return str_replace('\\', '/', $rel);
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

    // ── The native engine (ADR-0002) ────────────────────────────────

    private const TIMEOUT_SECONDS = 60;

    private const INSTALL_HINT = "the tina4 CLI provides the metrics engine (ADR-0002). "
        . "Install it with\n  curl -fsSL https://tina4.com/install.sh | sh\nor see https://tina4.com/cli";

    /**
     * Fields the dashboard renders. Checking for the DATA is honest where checking
     * a version string is not: a user may run any CLI build, and the payload is
     * what tells us what that build can actually do.
     */
    private const SUMMARY_KEYS = ["files_analyzed", "total_functions", "avg_complexity", "avg_maintainability"];
    private const FILE_KEYS = ["path", "loc", "avg_complexity", "maintainability", "has_tests"];
    private const FUNCTION_KEYS = ["name", "file", "line", "complexity", "loc"];

    /**
     * Absolute path to the tina4 CLI binary, or null when it is not installed.
     *
     * @return string|null
     */
    public static function enginePath(): ?string
    {
        $which = PHP_OS_FAMILY === "Windows" ? "where" : "command -v";
        $out = @shell_exec("$which tina4 2>/dev/null");
        if (!is_string($out)) {
            return null;
        }
        $first = trim(strtok($out, "\n") ?: "");
        return $first !== "" ? $first : null;
    }

    /**
     * Run `tina4 metrics --json` over a path and return the decoded payload.
     *
     * @param string $path Directory OR single file to scan
     * @return array
     * @throws MetricsEngineException When the binary is absent, the run fails, or the output is unreadable
     */
    private static function runEngine(string $path): array
    {
        $binary = self::enginePath();
        if ($binary === null) {
            throw new MetricsEngineException("tina4 not found on PATH - " . self::INSTALL_HINT);
        }

        $cmd = escapeshellarg($binary) . " metrics --path " . escapeshellarg($path) . " --json";
        $descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new MetricsEngineException("could not run $binary");
        }

        $stdout = stream_get_contents($pipes[1]) ?: "";
        $stderr = stream_get_contents($pipes[2]) ?: "";
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim($stderr !== "" ? $stderr : $stdout);
            $first = $detail !== "" ? strtok($detail, "\n") : "exit code $exitCode";
            throw new MetricsEngineException("tina4 metrics failed on $path: $first");
        }
        if (trim($stdout) === "") {
            throw new MetricsEngineException("tina4 metrics produced no output for $path");
        }

        $payload = json_decode($stdout, true);
        if (!is_array($payload)) {
            throw new MetricsEngineException("tina4 metrics returned unreadable JSON: " . json_last_error_msg());
        }
        return $payload;
    }

    /**
     * Pull a key out of the payload or throw naming what the engine is missing.
     *
     * @param array  $payload Decoded engine payload
     * @param string $key     Key the dashboard needs
     * @return array
     * @throws MetricsEngineException When the key is absent or not an array
     */
    private static function requireKey(array $payload, string $key): array
    {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            throw new MetricsEngineException(
                "engine payload has no usable '$key' - the installed tina4 CLI predates a field the "
                . "dashboard renders. Update it: " . self::INSTALL_HINT
            );
        }
        return $payload[$key];
    }

    /**
     * Full code analysis from the native engine, shaped for the dashboard.
     *
     * @param string $root Root directory to scan
     * @return array
     * @throws MetricsEngineException When the engine cannot supply a complete payload
     */
    public static function fullAnalysis(string $root = "src"): array
    {
        [$resolved, $scanMode] = self::resolveScanTarget($root);
        $payload = self::runEngine($resolved);

        $summary = self::requireKey($payload, "summary");
        $fileMetrics = self::requireKey($payload, "file_metrics");
        $functions = self::requireKey($payload, "most_complex_functions");

        $missing = array_diff(self::SUMMARY_KEYS, array_keys($summary));
        if ($missing !== []) {
            throw new MetricsEngineException(
                "engine summary is missing " . implode(", ", $missing) . " - update the CLI: " . self::INSTALL_HINT
            );
        }
        if ($fileMetrics !== [] && ($absent = array_diff(self::FILE_KEYS, array_keys($fileMetrics[0]))) !== []) {
            throw new MetricsEngineException("engine file_metrics is missing " . implode(", ", $absent));
        }
        if ($functions !== [] && ($absent = array_diff(self::FUNCTION_KEYS, array_keys($functions[0]))) !== []) {
            throw new MetricsEngineException("engine function metrics are missing " . implode(", ", $absent));
        }

        $result = [];
        foreach (self::SUMMARY_KEYS as $key) {
            $result[$key] = $summary[$key];
        }
        $result["file_metrics"] = $fileMetrics;
        // Display cap only. offenders() reads the engine's own uncapped list, so a
        // 16th over-threshold function is never hidden from the gate.
        $result["most_complex_functions"] = array_slice($functions, 0, 15);
        $result["dependency_graph"] = $payload["dependency_graph"] ?? [];
        // The framework owns these two: the engine always reports "project"
        // because it cannot know which directory is a framework package.
        $result["scan_mode"] = $scanMode;
        $result["scan_root"] = realpath($resolved) ?: $resolved;
        $result["engine"] = "tina4-cli";
        return $result;
    }

    /**
     * Top code-health offenders from the native engine.
     *
     * The engine ranks and severity-tags them, and its own --fail-on gate reads
     * the same list, so the CLI and the dashboard can never disagree about what
     * counts as an offender.
     *
     * @param string $root Root directory to scan
     * @param int    $top  Maximum offenders to return
     * @return array{offenders: array, summary: array}
     * @throws MetricsEngineException When the engine cannot supply a payload
     */
    public static function offenders(string $root = "src", int $top = 20): array
    {
        [$resolved, $scanMode] = self::resolveScanTarget($root);
        $payload = self::runEngine($resolved);

        $found = self::requireKey($payload, "offenders");
        $summary = self::requireKey($payload, "summary");
        $summary["scan_mode"] = $scanMode;
        $summary["scan_root"] = realpath($resolved) ?: $resolved;
        $summary["engine"] = "tina4-cli";
        $summary["total_offenders"] ??= count($found);

        return ["offenders" => array_slice($found, 0, $top), "summary" => $summary];
    }

    /**
     * Per-file metrics from the native engine.
     *
     * The engine accepts a single file for --path, so one code path serves both
     * the whole-tree scan and one file.
     *
     * @param string $filePath Path to a single source file
     * @return array
     * @throws MetricsEngineException When the path is missing, is a directory, or the engine fails
     */
    public static function fileDetail(string $filePath): array
    {
        if ($filePath === "") {
            throw new MetricsEngineException("fileDetail needs a path");
        }

        $target = $filePath;
        if (!file_exists($target) && self::$lastScanRoot !== "") {
            // Try it relative to whatever quickMetrics last resolved, so the
            // dashboard can pass a path taken straight out of file_metrics.
            $candidate = self::$lastScanRoot . DIRECTORY_SEPARATOR . $filePath;
            if (file_exists($candidate)) {
                $target = $candidate;
            }
        }
        if (!file_exists($target)) {
            throw new MetricsEngineException("no such file: $filePath");
        }
        if (is_dir($target)) {
            throw new MetricsEngineException("not a file: $filePath");
        }

        $payload = self::runEngine($target);
        $fileMetrics = self::requireKey($payload, "file_metrics");
        if ($fileMetrics === []) {
            throw new MetricsEngineException("engine reported no metrics for $filePath");
        }

        $detail = $fileMetrics[0];
        $detail["engine"] = "tina4-cli";
        return $detail;
    }
}
