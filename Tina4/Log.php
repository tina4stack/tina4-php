<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Structured logger with rotation. Zero dependencies — PHP built-ins only.
 *
 * TEXT is the output format by default, in all four Tina4 frameworks. Only
 * TINA4_LOG_FORMAT=json selects JSON. An object/array passed as the message is
 * still JSON-encoded INLINE inside the text line — that is the one implicit
 * JSON left, and it is the useful one (see {@see coerceMessage}).
 *
 * Configuration is resolved from TINA4_LOG_* on FIRST USE if nothing calls
 * {@see configure()}; configure() remains the explicit override and wins.
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

    /** @var bool Append to the log file (default) or overwrite it at startup. */
    private static bool $append = true;

    /** @var bool Whether to output to stdout */
    private static bool $stdout = false;

    /** @var bool Whether to write to file */
    private static bool $fileOutput = true;

    /**
     * @var bool Whether the line format is human-readable TEXT (the default)
     *   rather than JSON. Set ONLY by TINA4_LOG_FORMAT — never by an
     *   environment guess. See {@see configure()}.
     */
    private static bool $humanReadable = true;

    /**
     * @var bool Development mode — CONSOLE PRESENTATION only (ANSI colour).
     *
     * It does NOT select the format: colour around a JSON line makes it
     * unparseable, and "am I in production" is not a formatting decision the
     * logger is allowed to make for you. Mirrors the Python master's
     * `_is_production` flag (inverted).
     */
    private static bool $development = false;

    /**
     * @var bool TINA4_LOG_STRICT — when truthy a log-write failure RAISES
     *   instead of being swallowed. Documented on all four env-var pages and,
     *   until 2026-08-01, implemented only in Ruby.
     */
    private static bool $strict = false;

    /**
     * @var bool Whether the TINA4_LOG_* configuration has been resolved yet.
     *
     * Set by {@see configure()} — explicitly by the server, or lazily by
     * {@see ensureConfigured()} on the first log call in a process that never
     * boots one (a worker, a CLI tool, a cron script, a test).
     */
    private static bool $configured = false;

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
     *   TINA4_LOG_FORMAT     — 'json' selects JSON; anything else is TEXT
     *   TINA4_LOG_OUTPUT     — 'stdout', 'file', or 'both'
     *   TINA4_LOG_LEVEL      — minimum console level (overrides $minLevel)
     *   TINA4_LOG_APPEND     — append (default) or truncate at startup
     *   TINA4_LOG_ROTATE_SIZE — rotate threshold in bytes (0 disables rotation)
     *   TINA4_LOG_ROTATE_KEEP — number of rotated files to retain
     *   TINA4_LOG_STRICT     — raise on a log-write failure instead of swallowing it
     *
     * Calling this is OPTIONAL: the same resolution runs on the first log call
     * ({@see ensureConfigured()}). configure() is the explicit override and
     * always wins.
     *
     * @param string|null $logDir Directory (or file path) for log files, overridden by TINA4_LOG_DIR
     * @param bool $development Development mode — CONSOLE COLOUR only; it does NOT choose the format
     * @param string $minLevel Minimum console log level (overridden by TINA4_LOG_LEVEL)
     */
    public static function configure(
        ?string $logDir = 'logs',
        bool $development = false,
        string $minLevel = self::LEVEL_INFO,
    ): void {
        // Directory: env override > caller arg. A null argument means "use the
        // default", not "use an empty path" -- configure(null, true) used to
        // resolve to an empty directory and silently write NO log files at all.
        $envDir = DotEnv::getEnv('TINA4_LOG_DIR');
        $chosen = $envDir !== null && $envDir !== '' ? $envDir : ($logDir ?? 'logs');
        if ($chosen === '') {
            $chosen = 'logs';
        }

        // The target accepts a DIRECTORY or a FILE PATH. Identical rule in all
        // four: an existing directory is a directory; otherwise a basename with
        // an extension is a file (feature 2 of the feature audit).
        $targetFile = null;
        if (self::targetIsFile($chosen)) {
            $targetFile = basename($chosen);
            $chosen = dirname($chosen);
        }
        self::$logDir = rtrim($chosen, '/');

        // File: TINA4_LOG_FILE may be a relative filename (joined with dir) or
        // an absolute path (split into dir + filename). Empty/null => default.
        // An explicit path is a hard opt-in to file output (explicit always
        // wins — even in production), so track it for the default-output branch.
        $envFile = DotEnv::getEnv('TINA4_LOG_FILE');
        $explicitLogFile = ($envFile !== null && $envFile !== '') || $targetFile !== null;
        if ($envFile !== null && $envFile !== '') {
            if (str_contains($envFile, DIRECTORY_SEPARATOR) || str_contains($envFile, '/')) {
                self::$logDir = rtrim(dirname($envFile), '/');
                self::$logFile = basename($envFile);
            } else {
                self::$logFile = $envFile;
            }
        } elseif ($targetFile !== null) {
            // A file path passed straight to configure().
            self::$logFile = $targetFile;
        } else {
            self::$logFile = 'tina4.log';
        }

        // TINA4_LOG_APPEND — append (default) or overwrite on startup.
        //
        // APPEND IS THE DEFAULT: a log you can lose by restarting the process is
        // not a log. Set it false for one file per run (a short CLI, a test
        // fixture, a container shipping logs elsewhere); the file is truncated
        // once here at configure time, never per line.
        $appendEnv = DotEnv::getEnv('TINA4_LOG_APPEND');
        self::$append = $appendEnv === null
            || in_array(strtolower(trim((string) $appendEnv)), ['1', 'true', 'yes', 'on', 'y', 't'], true);
        if (!self::$append) {
            foreach ([self::$logFile, self::$errorFile] as $name) {
                $path = self::$logDir . DIRECTORY_SEPARATOR . $name;
                if (file_exists($path)) {
                    @file_put_contents($path, '');
                }
            }
        }

        self::$minLevel = strtoupper($minLevel);
        // v3.13.14: TINA4_LOG_LEVEL env overrides the caller arg (parity with
        // Python/Ruby/Node, which all read the env). Default is now INFO (was
        // effectively DEBUG) so deployed apps surface request/startup/warn/error
        // without debug noise.
        $envLevel = strtoupper((string) (DotEnv::getEnv('TINA4_LOG_LEVEL') ?? ''));
        if ($envLevel !== '' && isset(self::LEVEL_PRIORITY[$envLevel])) {
            self::$minLevel = $envLevel;
        }

        // Format — TEXT BY DEFAULT, EVERYWHERE (owner decision 2026-08-01).
        // ONLY TINA4_LOG_FORMAT=json selects JSON.
        //
        // The implicit production->JSON switch is deliberately GONE. It meant
        // four different things across the four frameworks — Node keyed off
        // TINA4_DEBUG being unset, Ruby off TINA4_ENV/RACK_ENV/RUBY_ENV, Python
        // off configure(production=True), and PHP had no switch at all and
        // always shipped JSON — so one machine with one .env produced four
        // different log formats and your format was chosen by a variable you
        // never connected to logging. An object/array passed as the message is
        // still JSON-encoded INLINE in the text line (see coerceMessage); that
        // is the only implicit JSON left, and it is the useful one.
        $envFormat = strtolower(trim((string) (DotEnv::getEnv('TINA4_LOG_FORMAT') ?? '')));
        self::$humanReadable = $envFormat !== 'json';

        // Development affects the CONSOLE PRESENTATION only (ANSI colour) —
        // see writeStdout(). It never selects the format.
        self::$development = $development;

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
                // v3.13.14: stdout is ON by default (was: only in dev). Containers
                // read PID 1 stdout (docker logs / k8s); the old dev-only default
                // meant deployed apps logged to a file inside the container that
                // nobody could see. TINA4_LOG_OUTPUT=file still opts out.
                //
                // v3.13.39: the log FILE (tina4.log + error.log) is now written by
                // default ONLY in development (TINA4_DEBUG truthy). In production /
                // containers the logger is stdout-only — a log file inside a
                // container just bloats the writable layer and disk, and 12-factor
                // wants logs on stdout for the platform to capture. An explicit
                // TINA4_LOG_OUTPUT=file/both (handled above) or an explicit
                // TINA4_LOG_FILE path still forces a file — explicit always wins.
                self::$stdout = true;
                self::$fileOutput = $explicitLogFile
                    || DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
                break;
        }

        // Rotation — TINA4_LOG_ROTATE_SIZE is in BYTES (0 disables rotation),
        // TINA4_LOG_ROTATE_KEEP is a file count.
        //
        // Breaking (2026-08-01): the legacy aliases TINA4_LOG_MAX_SIZE (in
        // MEGABYTES) and TINA4_LOG_KEEP were DELETED. They were documented for
        // all four frameworks and implemented in only two, and the size alias
        // took a different UNIT from the name it aliased — so the same .env
        // rotated at 10 MB here and nowhere else. One canonical name per
        // setting; rename the primary rather than keep an alias.
        // Migration: TINA4_LOG_MAX_SIZE=10 -> TINA4_LOG_ROTATE_SIZE=10485760,
        //            TINA4_LOG_KEEP=n      -> TINA4_LOG_ROTATE_KEEP=n.
        $rotateSize = DotEnv::getEnv('TINA4_LOG_ROTATE_SIZE');
        self::$maxFileSize = $rotateSize !== null && $rotateSize !== ''
            ? (int) $rotateSize
            : self::DEFAULT_ROTATE_SIZE;

        $rotateKeep = DotEnv::getEnv('TINA4_LOG_ROTATE_KEEP');
        self::$keepFiles = $rotateKeep !== null && $rotateKeep !== ''
            ? (int) $rotateKeep
            : self::DEFAULT_ROTATE_KEEP;

        // TINA4_LOG_STRICT — a log-write failure RAISES instead of being
        // swallowed. Documented on all four env-var pages for a long time and
        // implemented only in Ruby: a documented no-op in three frameworks.
        self::$strict = DotEnv::isTruthy(DotEnv::getEnv('TINA4_LOG_STRICT', 'false'));

        // Explicit configuration has landed — the lazy resolver stands down.
        self::$configured = true;
    }

    /**
     * Resolve TINA4_LOG_* on FIRST USE when nothing called {@see configure()}.
     *
     * MEASURED 2026-08-01: Ruby and Node resolved lazily; Python and PHP read
     * TINA4_LOG_* only inside configure(), and only the SERVER calls it. So any
     * script, worker, CLI tool or test that logged without booting a server
     * silently got the class defaults and ignored the operator's .env — and the
     * defaults were OPPOSITE across frameworks (python: stdout + text + no
     * file; php: NO stdout + files in ./logs + json). One .env, four
     * behaviours.
     *
     * configure() remains the explicit override: it sets $configured, which
     * turns this into a no-op.
     */
    private static function ensureConfigured(): void
    {
        if (self::$configured) {
            return;
        }
        self::configure();
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
    public static function debug(mixed $message, array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an info message.
     */
    public static function info(mixed $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public static function warning(mixed $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message.
     */
    public static function error(mixed $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log a critical message — the highest severity (above ERROR).
     *
     * Always emitted (like every other level), and mirrored into the error
     * log (CRITICAL 4 >= WARNING 2). Use it for unrecoverable, alert-worthy
     * failures.
     */
    public static function critical(mixed $message, array $context = []): void
    {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Whether a message at the given level would pass the configured minimum
     * CONSOLE level — the same threshold that gates stdout in {@see log()}.
     *
     * This is the single source of truth for the level comparison: both the
     * console write in log() and the public {@see isEnabled()} predicate call
     * it, so the predicate can never disagree with what the logger prints.
     * Level input is case-insensitive (mapped to the upper-case priorities).
     *
     * @param string $level Log level (e.g. "INFO", "info", "DEBUG").
     */
    private static function shouldLog(string $level): bool
    {
        // The threshold is only meaningful once TINA4_LOG_LEVEL has been read,
        // and isEnabled() can be the very first logger call in a process.
        self::ensureConfigured();
        $key = strtoupper($level);
        return (self::LEVEL_PRIORITY[$key] ?? 0) >= (self::LEVEL_PRIORITY[self::$minLevel] ?? 0);
    }

    /**
     * Return true if a message at $level would pass the configured minimum
     * CONSOLE level — i.e. whether the logger would actually print it to
     * stdout. This reflects CONSOLE (stdout) visibility only: the log FILE
     * always records every level regardless of this threshold.
     *
     * Use it to skip building an expensive log payload that would not be shown:
     *
     *     if (Log::isEnabled('debug')) {
     *         Log::debug('state', ['snapshot' => expensiveDump()]);
     *     }
     *
     * $level is case-insensitive (debug / info / warning / error / critical).
     * "critical" is the highest severity and flows through the same ordinary
     * threshold as every other level — there is no special-casing.
     *
     * Delegates to the same {@see shouldLog()} gate the console write uses, so
     * it never re-implements the level comparison and can never drift from the
     * real output decision.
     *
     * @param string $level Log level to test (e.g. "debug", "INFO", "critical").
     */
    public static function isEnabled(string $level): bool
    {
        return self::shouldLog($level);
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

    private static function log(string $level, mixed $message, array $context = []): void
    {
        // Resolve TINA4_LOG_* here, at the top of the real write path, if
        // nothing called configure(). A worker or CLI tool must honour the same
        // .env the server does.
        self::ensureConfigured();

        // Coerce FIRST. Anything can arrive as a message: an array from a
        // handler, a binary payload off a socket, a 10MB string. See
        // coerceMessage.
        $entry = self::buildEntry($level, self::coerceMessage($message), $context);

        if (self::$humanReadable) {
            $formatted = self::formatHumanReadable($entry);
        } else {
            $formatted = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line = $formatted . PHP_EOL;

        // Console output respects TINA4_LOG_LEVEL
        // Truncate on the CONSOLE only. The file keeps the full line so a
        // consumer parsing it loses nothing; a terminal does not need 10MB.
        if (self::$stdout && self::shouldLog($level)) {
            self::writeStdout($level, self::truncateForStdout($formatted) . PHP_EOL);
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

        // Inject caller function name when TINA4_LOG_FUNC is enabled.
        // Default off — zero overhead unless opted in. Parity with
        // tina4-python's Log._caller_name (feature #41).
        $caller = self::callerName();
        if ($caller !== null) {
            $entry['function'] = $caller;
        }

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        return $entry;
    }

    /**
     * Frame method/function names that belong to Log itself.
     *
     * The frame walk skips past these so the caller name lands on the
     * first non-Log frame. Kept here so tests can introspect and so
     * future internal wrappers can extend it without forking the walk.
     *
     * @var array<int,string>
     */
    private const OWN_FRAMES = [
        'callerName', 'buildEntry', 'formatHumanReadable',
        'log', 'debug', 'info', 'warning', 'error', 'critical',
    ];

    /**
     * Return the function name that called Log::{debug,info,warning,error}.
     *
     * Active only when ``TINA4_LOG_FUNC`` is truthy — the lookup uses
     * ``debug_backtrace`` which is ~5% overhead per log call. Walks past
     * Log's own frames (see ``OWN_FRAMES``) to land on the real caller,
     * so the count is robust whether the test invokes ``buildEntry``
     * directly or goes through ``Log::info`` → ``log`` → ``buildEntry``.
     *
     * Returns ``null`` if the stack is too shallow, if the matched frame
     * is anonymous (``{closure}``), or if anything goes wrong — never
     * throws. Parity feature #41 across all four Tina4 frameworks.
     */
    public static function callerName(): ?string
    {
        try {
            if (!Env::bool('TINA4_LOG_FUNC')) {
                return null;
            }
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            // Walk past Log's own frames. Cap the walk to defend against
            // pathological wrapper depth.
            $cap = min(16, count($frames));
            for ($i = 0; $i < $cap; $i++) {
                $frame = $frames[$i] ?? null;
                if ($frame === null) {
                    return null;
                }
                $function = $frame['function'] ?? null;
                if ($function === null || $function === '') {
                    continue;
                }
                // Skip frames inside Log itself.
                if (in_array($function, self::OWN_FRAMES, true)) {
                    continue;
                }
                // Anonymous closures show up as "{closure}" (PHP < 8.4)
                // or "{closure:File::method():line}" (PHP 8.4+) — both
                // start with "{closure" and carry no useful symbol, so
                // filter them out instead of leaking gibberish.
                if (str_starts_with($function, '{closure')) {
                    return null;
                }
                return $function;
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Format a log entry for human-readable output.
     */
    /**
     * Maximum characters written to the CONSOLE for one line. The file keeps
     * the whole thing; a terminal does not need 10MB. Same number in all four.
     */
    private const STDOUT_MAX_CHARS = 2000;

    /**
     * Turn anything into a single safe line of text.
     *
     * A valid UTF-8 string passes through. Binary is described rather than
     * dumped: raw bytes at a terminal garble it and can emit escape sequences.
     * An array or object becomes JSON, because an array rendered as text is the
     * whole reason the caller logged it. The logger must never be surprised by
     * what it is handed, and must never be the reason a request dies.
     */
    private static function coerceMessage(mixed $message): string
    {
        if (is_string($message)) {
            if (!self::isUtf8($message)) {
                return '<binary ' . strlen($message) . ' bytes>';
            }
            $text = $message;
        } elseif ($message === null) {
            $text = '';
        } elseif (is_scalar($message)) {
            $text = (string) $message;
        } else {
            $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = $encoded === false ? print_r($message, true) : $encoded;
        }
        // Strip control characters so nothing can drive the terminal.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? $text;
    }

    /** Cap a console line. The file keeps the full line. */
    private static function truncateForStdout(string $line): string
    {
        $len = self::charLength($line);
        if ($len <= self::STDOUT_MAX_CHARS) {
            return $line;
        }
        return self::charSubstr($line, self::STDOUT_MAX_CHARS) . "... (truncated, {$len} chars)";
    }

    /*
     * WHY THESE THREE HELPERS EXIST INSTEAD OF mb_check_encoding/mb_strlen/mb_substr
     *
     * ext-mbstring is NOT enabled in a stock php-src build, and composer.json
     * requires only ext-json - v3's zero-runtime-dependency promise. So on a
     * perfectly ordinary PHP the mb_* calls were a fatal "call to undefined
     * function", and the logger is the worst possible place for that: Log::error
     * is what Session::safeWrite() calls when a session backend fails, so a
     * DEGRADABLE backend failure became a fatal that HID ITS OWN CAUSE. The
     * docblock above already promised the logger "must never be the reason a
     * request dies"; this is what makes that true.
     *
     * The fallback is ext-pcre, which cannot be disabled in PHP - unlike
     * mbstring it is always there. mbstring is still PREFERRED when present: it
     * is faster and it is what the rest of the framework uses.
     *
     * Declaring ext-mbstring in composer.json was the other option and was
     * rejected: it would make a suggested extension a hard requirement and break
     * the zero-dependency promise. ext-openssl is already handled this way -
     * suggested, checked at the point of use - and this follows that precedent.
     */

    /** True when the bytes are valid UTF-8. */
    private static function isUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }
        // The empty pattern with /u makes PCRE validate the subject as UTF-8 and
        // return false on malformed bytes. This is the canonical mbstring-free check.
        return $value === '' || preg_match('//u', $value) === 1;
    }

    /** Length in CHARACTERS, not bytes, so a multi-byte line is not cut mid-glyph. */
    private static function charLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }
        $count = preg_match_all('/./us', $value);
        // preg_match_all returns false on malformed input; byte length is the
        // honest floor there, and coerceMessage has already rejected non-UTF-8.
        return $count === false ? strlen($value) : $count;
    }

    /** First $chars characters, counted in characters. */
    private static function charSubstr(string $value, int $chars): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $chars);
        }
        $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? substr($value, 0, $chars) : implode('', array_slice($parts, 0, $chars));
    }

    /**
     * Is this target a FILE PATH or a DIRECTORY?
     *
     * An existing directory is always a directory, extension or not. Otherwise
     * a basename with an extension (app.log, app.txt) is a file and anything
     * else is a directory to create, so the path need not exist yet. Identical
     * rule in all four frameworks.
     */
    private static function targetIsFile(string $path): bool
    {
        if (is_dir($path)) {
            return false;
        }
        $base = basename($path);
        return str_contains($base, '.') && !str_starts_with($base, '.');
    }

    private static function formatHumanReadable(array $entry): string
    {
        $parts = [
            $entry['timestamp'],
            // Pad to 8, not 7: CRITICAL is eight characters, so a 7-wide column
            // was broken by our own highest level. 8 is the only width that fits
            // every level name. Cross-framework format table (feature 2).
            '[' . str_pad($entry['level'], 8) . ']',
        ];

        if (isset($entry['request_id'])) {
            $parts[] = '[' . $entry['request_id'] . ']';
        }

        // Optional caller-name segment — only present when TINA4_LOG_FUNC
        // was truthy at the time buildEntry() ran (feature #41).
        if (isset($entry['function'])) {
            $parts[] = '[' . $entry['function'] . ']';
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
            self::LEVEL_DEBUG => "\033[36m",    // Cyan
            self::LEVEL_INFO => "\033[32m",     // Green
            self::LEVEL_WARNING => "\033[33m",  // Yellow
            self::LEVEL_ERROR => "\033[31m",    // Red
            self::LEVEL_CRITICAL => "\033[35m", // Magenta
        ];

        // No ANSI in production (a log shipper reads this) and none in JSON mode
        // either — an escape sequence wrapped around a JSON object makes the
        // line unparseable, which would make the one format you can explicitly
        // ask for useless on stdout. Since 2026-08-01 TEXT is the default
        // format, so the colour decision hangs off the development flag rather
        // than off the format (which no longer implies an environment).
        $plain = !self::$development || !self::$humanReadable;
        $color = $plain ? '' : ($colors[$level] ?? '');
        $reset = $plain ? '' : "\033[0m";

        if (defined('STDOUT')) {
            $stdout = \STDOUT;
        } else {
            $stdout = @fopen('php://stdout', 'w');
        }

        if (is_resource($stdout)) {
            @fwrite($stdout, $color . $line . $reset);
            // v3.13.14: flush so logs appear immediately under the long-running
            // built-in server (stream_socket_server) instead of sitting in the
            // stream's userspace buffer — otherwise `docker logs` lags or, on an
            // abrupt stop, loses the tail.
            @fflush($stdout);
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

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // The second is_dir() covers the race where a concurrent process
            // created the directory between the check and the mkdir.
            self::onWriteFailure("cannot create log directory '{$dir}'");
            return;
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        // Rotate if file exceeds max size. TINA4_LOG_ROTATE_SIZE=0 disables.
        if (self::$maxFileSize > 0 && is_file($filePath) && filesize($filePath) >= self::$maxFileSize) {
            self::rotateLog($filePath);
        }

        if (@file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            self::onWriteFailure("cannot write log file '{$filePath}'");
        }
    }

    /**
     * React to a failed log write according to TINA4_LOG_STRICT.
     *
     * Default (unset/false): swallow it. A failing log sink must never be the
     * reason a request dies — the same policy every Tina4 sink degrades under.
     *
     * TINA4_LOG_STRICT truthy: RAISE, so a deployment that depends on its audit
     * trail finds out immediately instead of running blind on a full disk or a
     * read-only volume. TINA4_LOG_STRICT was documented on all four env-var
     * pages while only Ruby implemented it — a documented no-op in three
     * frameworks, which is worse than an undocumented gap.
     *
     * @param string $reason What failed, with the path involved.
     * @throws \RuntimeException When TINA4_LOG_STRICT is truthy.
     */
    private static function onWriteFailure(string $reason): void
    {
        if (self::$strict) {
            throw new \RuntimeException(
                'Tina4 log write failed: ' . $reason . ' (TINA4_LOG_STRICT is on)'
            );
        }
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
        self::$humanReadable = true;
        self::$development = false;
        self::$strict = false;
        self::$minLevel = self::LEVEL_DEBUG;
        self::$maxFileSize = self::DEFAULT_ROTATE_SIZE;
        self::$keepFiles = self::DEFAULT_ROTATE_KEEP;
        // Back to "nothing has configured me": the next log call re-reads
        // TINA4_LOG_* exactly as a fresh process would.
        self::$configured = false;
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
}
