<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Structured logger with JSON lines output and log rotation.
 * Zero dependencies — uses only PHP built-in functions.
 */
class Log
{
    public const LEVEL_DEBUG = 'DEBUG';
    public const LEVEL_INFO = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR = 'ERROR';

    /** Maximum log file size before rotation in bytes (default 10 MB, configurable via TINA4_LOG_MAX_SIZE) */
    private static int $maxFileSize = 10 * 1024 * 1024;

    /** Number of rotated log files to keep (default 5, configurable via TINA4_LOG_KEEP) */
    private static int $keepFiles = 5;

    /** @var string|null Current request ID for correlation */
    private static ?string $requestId = null;

    /** @var string Log directory path */
    private static string $logDir = 'logs';

    /** @var string Log file name (all levels land here) */
    private static string $logFile = 'tina4.log';

    /**
     * @var string Error-only log file name.
     *
     * Any entry at WARNING or above is mirrored into this file so
     * developers can `tail -f logs/error.log` without wading through
     * INFO/DEBUG noise. The main `tina4.log` still carries everything.
     */
    private static string $errorFile = 'error.log';

    /** @var bool Whether to output to stdout */
    private static bool $stdout = false;

    /** @var bool Whether to format as human-readable (dev mode) */
    private static bool $humanReadable = false;

    /** @var string Minimum log level */
    private static string $minLevel = self::LEVEL_DEBUG;

    /** @var array<string, int> Level priorities */
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
    ];

    /**
     * Configure the logger.
     *
     * @param string $logDir Directory for log files
     * @param bool $development If true, enables human-readable format and stdout
     * @param string $minLevel Minimum log level to record
     */
    public static function configure(
        string $logDir = 'logs',
        bool $development = false,
        string $minLevel = self::LEVEL_DEBUG,
    ): void {
        self::$logDir = rtrim($logDir, '/');
        self::$stdout = $development;
        self::$humanReadable = $development;
        self::$minLevel = strtoupper($minLevel);

        // Read rotation config from env (with defaults)
        $maxSizeMb = (int) (DotEnv::getEnv('TINA4_LOG_MAX_SIZE') ?? '10');
        self::$maxFileSize = $maxSizeMb * 1024 * 1024;
        self::$keepFiles = (int) (DotEnv::getEnv('TINA4_LOG_KEEP') ?? '5');
    }

    /**
     * Set a request ID for log correlation.
     */
    public static function setRequestId(?string $requestId): void
    {
        self::$requestId = $requestId;
    }

    /**
     * Get the current request ID.
     */
    public static function getRequestId(): ?string
    {
        return self::$requestId;
    }

    /**
     * Log a debug message.
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an info message.
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message.
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Write a log entry.
     */
    /**
     * Strip ANSI escape codes from a string.
     */
    private static function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        $entry = self::buildEntry($level, $message, $context);

        if (self::$humanReadable) {
            $formatted = self::formatHumanReadable($entry);
        } else {
            $formatted = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line = $formatted . PHP_EOL;

        // Console output respects TINA4_LOG_LEVEL
        $shouldLog = (self::LEVEL_PRIORITY[$level] ?? 0) >= (self::LEVEL_PRIORITY[self::$minLevel] ?? 0);
        if (self::$stdout && $shouldLog) {
            self::writeStdout($level, $line);
        }

        // Always write ALL levels to the main file (raw log, no filtering), strip ANSI codes
        self::writeToFile(self::$logFile, self::stripAnsi($line));

        // Mirror WARNING and ERROR into the dedicated error log so
        // developers can tail errors without the INFO/DEBUG noise.
        // Parity with tina4-python's debug/_error_writer.
        if ((self::LEVEL_PRIORITY[$level] ?? 0) >= self::LEVEL_PRIORITY[self::LEVEL_WARNING]) {
            self::writeToFile(self::$errorFile, self::stripAnsi($line));
        }
    }

    /**
     * Build a structured log entry.
     *
     * @return array<string, mixed>
     */
    private static function buildEntry(string $level, string $message, array $context): array
    {
        $entry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z',
            'level' => $level,
            'message' => $message,
        ];

        if (self::$requestId !== null) {
            $entry['request_id'] = self::$requestId;
        }

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        return $entry;
    }

    /**
     * Format a log entry for human-readable output.
     */
    private static function formatHumanReadable(array $entry): string
    {
        $parts = [
            $entry['timestamp'],
            '[' . str_pad($entry['level'], 7) . ']',
        ];

        if (isset($entry['request_id'])) {
            $parts[] = '[' . $entry['request_id'] . ']';
        }

        $parts[] = $entry['message'];

        if (isset($entry['context']) && !empty($entry['context'])) {
            $parts[] = json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode(' ', $parts);
    }

    /**
     * Write to stdout with color coding for development mode.
     */
    private static function writeStdout(string $level, string $line): void
    {
        $colors = [
            self::LEVEL_DEBUG => "\033[36m",   // Cyan
            self::LEVEL_INFO => "\033[32m",    // Green
            self::LEVEL_WARNING => "\033[33m", // Yellow
            self::LEVEL_ERROR => "\033[31m",   // Red
        ];

        $reset = "\033[0m";
        $color = $colors[$level] ?? '';

        if (defined('STDOUT')) {
            $stdout = \STDOUT;
        } else {
            $stdout = @fopen('php://stdout', 'w');
        }

        if (is_resource($stdout)) {
            @fwrite($stdout, $color . $line . $reset);
        } else {
            // Fallback: use error_log when stdout isn't available
            error_log(strip_tags($line));
        }
    }

    /**
     * Write a log line to the named file under the log directory,
     * with numbered rotation.
     *
     * Rotation scheme: <file> → <file>.1 → <file>.2 → ... → <file>.{keep}
     * Called separately for the main log (tina4.log) and the error
     * mirror (error.log) so each rotates independently.
     */
    private static function writeToFile(string $fileName, string $line): void
    {
        $dir = self::$logDir;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        // Rotate if file exceeds max size
        if (is_file($filePath) && filesize($filePath) >= self::$maxFileSize) {
            self::rotateLog($filePath);
        }

        file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate using numbered scheme: tina4.log.{keep} is deleted, all others shift up by 1.
     */
    private static function rotateLog(string $filePath): void
    {
        $keep = self::$keepFiles;

        // Delete the oldest rotated file if it exists
        $oldest = $filePath . '.' . $keep;
        if (is_file($oldest)) {
            @unlink($oldest);
        }

        // Shift existing rotated files: .{n} → .{n+1}
        for ($n = $keep - 1; $n >= 1; $n--) {
            $src = $filePath . '.' . $n;
            $dst = $filePath . '.' . ($n + 1);
            if (is_file($src)) {
                @rename($src, $dst);
            }
        }

        // Rename current log to .1
        @rename($filePath, $filePath . '.1');
    }

    /**
     * Reset logger state (useful for testing).
     */
    public static function reset(): void
    {
        self::$requestId = null;
        self::$logDir = 'logs';
        self::$logFile = 'tina4.log';
        self::$errorFile = 'error.log';
        self::$stdout = false;
        self::$humanReadable = false;
        self::$minLevel = self::LEVEL_DEBUG;
    }
}
