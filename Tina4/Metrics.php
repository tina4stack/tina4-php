<?php

namespace Tina4;

/**
 * Thin dev-admin adapter for the native `tina4 metrics` engine (ADR-0054).
 */
class Metrics
{
    private static string $lastScanRoot = "";

    private const INSTALL_HINT = "update the native tina4 CLI: https://tina4.com/cli";
    private const SUMMARY_KEYS = ["files_analyzed", "total_functions", "avg_complexity", "avg_maintainability"];
    private const FILE_KEYS = ["path", "loc", "avg_complexity", "maintainability", "has_tests"];
    private const FUNCTION_KEYS = ["name", "file", "line", "complexity", "loc"];

    /** @return array{0: string, 1: string} */
    private static function resolveTarget(string $root = "src"): array
    {
        $resolved = realpath($root);
        $hasPhp = false;
        if ($resolved !== false && is_dir($resolved)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === "php") {
                    $hasPhp = true;
                    break;
                }
            }
        }
        if (!$hasPhp) {
            $resolved = __DIR__;
            $mode = "framework";
        } else {
            $mode = "project";
        }
        self::$lastScanRoot = $resolved;
        return [$resolved, $mode];
    }

    private static function enginePath(): ?string
    {
        $which = PHP_OS_FAMILY === "Windows" ? "where" : "command -v";
        $output = @shell_exec("$which tina4 2>/dev/null");
        $first = is_string($output) ? trim(strtok($output, "\n") ?: "") : "";
        return $first !== "" ? $first : null;
    }

    private static function runEngine(string $path): array
    {
        $binary = self::enginePath();
        if ($binary === null) {
            throw new MetricsEngineException("tina4 not found on PATH - " . self::INSTALL_HINT);
        }
        $command = escapeshellarg($binary) . " metrics --path " . escapeshellarg($path) . " --json";
        $process = @proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
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
        $payload = json_decode($stdout, true);
        if (!is_array($payload)) {
            throw new MetricsEngineException("tina4 metrics returned unreadable JSON: " . json_last_error_msg());
        }
        return $payload;
    }

    private static function requireArray(array $payload, string $key): array
    {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            throw new MetricsEngineException("engine payload has no usable '$key' - " . self::INSTALL_HINT);
        }
        return $payload[$key];
    }

    public static function fullAnalysis(string $root = "src"): array
    {
        [$resolved, $scanMode] = self::resolveTarget($root);
        $payload = self::runEngine($resolved);
        $summary = self::requireArray($payload, "summary");
        $fileMetrics = self::requireArray($payload, "file_metrics");
        $functions = self::requireArray($payload, "most_complex_functions");

        if (($missing = array_diff(self::SUMMARY_KEYS, array_keys($summary))) !== []) {
            throw new MetricsEngineException("engine summary is missing " . implode(", ", $missing));
        }
        if ($fileMetrics !== [] && ($missing = array_diff(self::FILE_KEYS, array_keys($fileMetrics[0]))) !== []) {
            throw new MetricsEngineException("engine file_metrics is missing " . implode(", ", $missing));
        }
        if ($functions !== [] && ($missing = array_diff(self::FUNCTION_KEYS, array_keys($functions[0]))) !== []) {
            throw new MetricsEngineException("engine function metrics are missing " . implode(", ", $missing));
        }

        $result = array_intersect_key($summary, array_flip(self::SUMMARY_KEYS));
        return $result + [
            "file_metrics" => $fileMetrics,
            "most_complex_functions" => array_slice($functions, 0, 15),
            "dependency_graph" => $payload["dependency_graph"] ?? [],
            "scan_mode" => $scanMode,
            "scan_root" => $resolved,
            "engine" => "tina4-cli",
        ];
    }

    public static function fileDetail(string $filePath): array
    {
        if ($filePath === "") {
            throw new MetricsEngineException("fileDetail needs a path");
        }
        $target = $filePath;
        if (!file_exists($target) && self::$lastScanRoot !== "") {
            $target = self::$lastScanRoot . DIRECTORY_SEPARATOR . $filePath;
        }
        if (!file_exists($target)) {
            throw new MetricsEngineException("no such file: $filePath");
        }
        if (is_dir($target)) {
            throw new MetricsEngineException("not a file: $filePath");
        }
        $payload = self::runEngine($target);
        $fileMetrics = self::requireArray($payload, "file_metrics");
        if ($fileMetrics === []) {
            throw new MetricsEngineException("engine reported no metrics for $filePath");
        }
        $functions = self::requireArray($payload, "most_complex_functions");
        return array_merge($fileMetrics[0], [
            "function_count" => $fileMetrics[0]["functions"] ?? 0,
            "functions" => $functions,
            "engine" => "tina4-cli",
        ]);
    }
}
