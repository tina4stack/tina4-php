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
    public const LEVEL_CRITICAL = 'CRITICAL';

    /** Default rotation size in bytes (10 MB). Override via TINA4_LOG_ROTATE_SIZE. */
    public const DEFAULT_ROTATE_SIZE = 10 * 1024 * 1024;

    /** Default number of rotated files to keep. Override via TINA4_LOG_ROTATE_KEEP. */
    public const DEFAULT_ROTATE_KEEP = 5;

    /** Maximum log file size before rotation in bytes. 0 disables rotation. */
    private static int $maxFileSize = self::DEFAULT_ROTATE_SIZE;

    /** Number of rotated log files to keep. */
    private static int $keepFiles = self::DEFAULT_ROTATE_KEEP;

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

    /** @var bool Whether to write to file */
    private static bool $fileOutput = true;

    /** @var bool Whether to format as human-readable (dev mode) */
    private static bool $humanReadable = false;

    /** @var bool Whether Log::critical() actually emits (TINA4_LOG_CRITICAL) */
    private static bool $criticalEnabled = false;

    /** @var string Minimum log level */
    private static string $minLevel = self::LEVEL_DEBUG;

    /** @var array<string, int> Level priorities */
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
        self::LEVEL_CRITICAL => 4,
    ];

    /**
     * Configure the logger.
     *
     * Reads (in addition to the explicit args):
     *   TINA4_LOG_DIR        — log directory (overrides $logDir)
     *   TINA4_LOG_FILE       — primary log file path; if absolute, sets dir + filename
     *   TINA4_LOG_FORMAT     — 'text' (human-readable) or 'json'
     *   TINA4_LOG_OUTPUT     — 'stdout', 'file', or 'both'
     *   TINA4_LOG_CRITICAL   — enable Log::critical()
     *   TINA4_LOG_ROTATE_SIZE — rotate threshold in bytes (0 disables rotation)
     *   TINA4_LOG_ROTATE_KEEP — number of rotated files to retain
     *
     * @param string $logDir Directory for log files (overridden by TINA4_LOG_DIR)
     * @param bool $development If true, enables human-readable format and stdout (overridden by TINA4_LOG_FORMAT/OUTPUT)
     * @param string $minLevel Minimum log level to record
     */
    public static function configure(
        string $logDir = 'logs',
        bool $development = false,
        string $minLevel = self::LEVEL_DEBUG,
    ): void {
        // Directory: env override > caller arg
        $envDir = DotEnv::getEnv('TINA4_LOG_DIR');
        self::$logDir = rtrim($envDir !== null && $envDir !== '' ? $envDir : $logDir, '/');

        // File: TINA4_LOG_FILE may be a relative filename (joined with dir) or
        // an absolute path (split into dir + filename). Empty/null => default.
        $envFile = DotEnv::getEnv('TINA4_LOG_FILE');
        if ($envFile !== null && $envFile !== '') {
            if (str_contains($envFile, DIRECTORY_SEPARATOR) || str_contains($envFile, '/')) {
                self::$logDir = rtrim(dirname($envFile), '/');
                self::$logFile = basename($envFile);
            } else {
                self::$logFile = $envFile;
            }
        } else {
            self::$logFile = 'tina4.log';
        }

        self::$minLevel = strtoupper($minLevel);

        // Format: env > development flag default
        $envFormat = strtolower((string) (DotEnv::getEnv('TINA4_LOG_FORMAT') ?? ''));
        if ($envFormat === 'text') {
            self::$humanReadable = true;
        } elseif ($envFormat === 'json') {
            self::$humanReadable = false;
        } else {
            self::$humanReadable = $development;
        }

        // Output: env > development flag default
        $envOutput = strtolower((string) (DotEnv::getEnv('TINA4_LOG_OUTPUT') ?? ''));
        switch ($envOutput) {
            case 'stdout':
                self::$stdout = true;
                self::$fileOutput = false;
                break;
            case 'file':
                self::$stdout = false;
                self::$fileOutput = true;
                break;
            case 'both':
                self::$stdout = true;
                self::$fileOutput = true;
                break;
            default:
                self::$stdout = $development;
                self::$fileOutput = true;
                break;
        }

        // Critical level enabled flag
        self::$criticalEnabled = DotEnv::isTruthy(DotEnv::getEnv('TINA4_LOG_CRITICAL', 'false'));

        // Rotation — bytes, 0 disables. Falls back to legacy TINA4_LOG_MAX_SIZE (MB)
        // and TINA4_LOG_KEEP for back-compat.
        $rotateSize = DotEnv::getEnv('TINA4_LOG_ROTATE_SIZE');
        if ($rotateSize !== null && $rotateSize !== '') {
            self::$maxFileSize = (int) $rotateSize;
        } else {
            $legacyMb = DotEnv::getEnv('TINA4_LOG_MAX_SIZE');
            if ($legacyMb !== null && $legacyMb !== '') {
                self::$maxFileSize = (int) $legacyMb * 1024 * 1024;
            } else {
                self::$maxFileSize = self::DEFAULT_ROTATE_SIZE;
            }
        }

        $rotateKeep = DotEnv::getEnv('TINA4_LOG_ROTATE_KEEP');
        if ($rotateKeep !== null && $rotateKeep !== '') {
            self::$keepFiles = (int) $rotateKeep;
        } else {
            $legacyKeep = DotEnv::getEnv('TINA4_LOG_KEEP');
            self::$keepFiles = $legacyKeep !== null && $legacyKeep !== ''
                ? (int) $legacyKeep
                : self::DEFAULT_ROTATE_KEEP;
        }
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
     * Log a critical message. No-op unless TINA4_LOG_CRITICAL is truthy.
     *
     * Critical messages always mirror to the error log (same as ERROR/WARNING)
     * regardless of the configured min level — but the entire level is
     * suppressed when TINA4_LOG_CRITICAL is unset/false.
     */
    public static function critical(string $message, array $context = []): void
    {
        if (!self::$criticalEnabled) {
            return;
        }
        self::log(self::LEVEL_CRITICAL, $message, $context);
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

        // File output is gated by TINA4_LOG_OUTPUT (default: enabled).
        if (self::$fileOutput) {
            // Always write ALL levels to the main file (raw log, no filtering), strip ANSI codes
            self::writeToFile(self::$logFile, self::stripAnsi($line));

            // Mirror WARNING and above into the dedicated error log so
            // developers can tail errors without the INFO/DEBUG noise.
            // Parity with tina4-python's debug/_error_writer.
            if ((self::LEVEL_PRIORITY[$level] ?? 0) >= self::LEVEL_PRIORITY[self::LEVEL_WARNING]) {
                self::writeToFile(self::$errorFile, self::stripAnsi($line));
            }
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

        // Rotate if file exceeds max size. TINA4_LOG_ROTATE_SIZE=0 disables.
        if (self::$maxFileSize > 0 && is_file($filePath) && filesize($filePath) >= self::$maxFileSize) {
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

        if ($keep <= 0) {
            // Truncate-only — no backups retained.
            @unlink($filePath);
            return;
        }

        // Delete any rotated files beyond the keep window
        // (covers shrinking _KEEP between runs).
        $extra = $keep + 1;
        while (is_file($filePath . '.' . $extra)) {
            @unlink($filePath . '.' . $extra);
            $extra++;
        }

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
        self::$fileOutput = true;
        self::$humanReadable = false;
        self::$criticalEnabled = false;
        self::$minLevel = self::LEVEL_DEBUG;
        self::$maxFileSize = self::DEFAULT_ROTATE_SIZE;
        self::$keepFiles = self::DEFAULT_ROTATE_KEEP;
    }

    /** Test helper — current rotation size in bytes (0 disables rotation). */
    public static function rotateSize(): int
    {
        return self::$maxFileSize;
    }

    /** Test helper — current rotation keep count. */
    public static function rotateKeep(): int
    {
        return self::$keepFiles;
    }

    /** Test helper — resolved log directory after configure(). */
    public static function logDir(): string
    {
        return self::$logDir;
    }

    /** Test helper — resolved primary log filename after configure(). */
    public static function logFile(): string
    {
        return self::$logFile;
    }

    /** Test helper — whether stdout output is enabled. */
    public static function stdoutEnabled(): bool
    {
        return self::$stdout;
    }

    /** Test helper — whether file output is enabled. */
    public static function fileOutputEnabled(): bool
    {
        return self::$fileOutput;
    }

    /** Test helper — whether human-readable (text) format is active. */
    public static function isHumanReadable(): bool
    {
        return self::$humanReadable;
    }

    /** Test helper — whether Log::critical() is currently active. */
    public static function criticalEnabled(): bool
    {
        return self::$criticalEnabled;
    }
}
