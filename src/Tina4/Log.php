<?php

/**
 * Tina4 - This is not a 4ramework.
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

    /** Maximum log file size before rotation (10 MB) */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var string|null Current request ID for correlation */
    private static ?string $requestId = null;

    /** @var string Log directory path */
    private static string $logDir = 'logs';

    /** @var string Log file name */
    private static string $logFile = 'tina4.log';

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
    private static function log(string $level, string $message, array $context = []): void
    {
        // Check minimum level
        if (self::LEVEL_PRIORITY[$level] < self::LEVEL_PRIORITY[self::$minLevel] ?? 0) {
            return;
        }

        $entry = self::buildEntry($level, $message, $context);

        if (self::$humanReadable) {
            $formatted = self::formatHumanReadable($entry);
        } else {
            $formatted = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line = $formatted . PHP_EOL;

        // Write to stdout in development mode
        if (self::$stdout) {
            self::writeStdout($level, $line);
        }

        // Always write to file
        self::writeToFile($line);
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

        $stdout = defined('STDOUT') ? \STDOUT : fopen('php://stdout', 'w');
        fwrite($stdout, $color . $line . $reset);
    }

    /**
     * Write a log line to the log file, with rotation.
     */
    private static function writeToFile(string $line): void
    {
        $dir = self::$logDir;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . self::$logFile;

        // Rotate if file exceeds max size
        if (is_file($filePath) && filesize($filePath) >= self::MAX_FILE_SIZE) {
            self::rotateLog($filePath);
        }

        // Date-based rotation: if the file is from a previous day, rotate it
        if (is_file($filePath)) {
            $fileDate = date('Y-m-d', filemtime($filePath));
            $today = date('Y-m-d');
            if ($fileDate !== $today) {
                self::rotateLog($filePath, $fileDate);
            }
        }

        file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate a log file by renaming it with a date/time suffix.
     */
    private static function rotateLog(string $filePath, ?string $dateSuffix = null): void
    {
        $suffix = $dateSuffix ?? date('Y-m-d_His');
        $dir = dirname($filePath);
        $base = pathinfo($filePath, PATHINFO_FILENAME);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        $rotatedPath = $dir . DIRECTORY_SEPARATOR . $base . '.' . $suffix . '.' . $ext;

        // Avoid collision
        $counter = 0;
        while (file_exists($rotatedPath)) {
            $counter++;
            $rotatedPath = $dir . DIRECTORY_SEPARATOR . $base . '.' . $suffix . '.' . $counter . '.' . $ext;
        }

        rename($filePath, $rotatedPath);
    }

    /**
     * Reset logger state (useful for testing).
     */
    public static function reset(): void
    {
        self::$requestId = null;
        self::$logDir = 'logs';
        self::$logFile = 'tina4.log';
        self::$stdout = false;
        self::$humanReadable = false;
        self::$minLevel = self::LEVEL_DEBUG;
    }
}
