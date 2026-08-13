<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/** Invalid setting, removed setting, or an inaccessible selected sink. */
class LogConfigurationError extends \InvalidArgumentException
{
    public ?string $setting;
    public $value;
    public $accepted;
    public ?string $sink;
    public ?string $operation;

    public function __construct(
        string $message, ?string $setting = null, $value = null, $accepted = null,
        ?string $sink = null, ?string $operation = null
    ) {
        parent::__construct($message);
        $this->setting = $setting;
        $this->value = $value;
        $this->accepted = $accepted;
        $this->sink = $sink;
        $this->operation = $operation;
    }
}

/** Invalid argument to a public logger method. */
class LogArgumentError extends \InvalidArgumentException
{
    public ?string $argument;
    public $accepted;

    public function __construct(string $message, ?string $argument = null, $accepted = null)
    {
        parent::__construct($message);
        $this->argument = $argument;
        $this->accepted = $accepted;
    }
}

/** A selected sink failed after configuration succeeded, under strict mode. */
class LogWriteError extends \RuntimeException
{
    public ?string $sink;
    public ?string $operation;

    public function __construct(string $message, ?string $sink = null, ?string $operation = null)
    {
        parent::__construct($message);
        $this->sink = $sink;
        $this->operation = $operation;
    }
}

/**
 * One owned log file: bounded, PREDICTIVE rotation guarded by a single
 * in-process (thread-equivalent -- PHP has no threads in the CLI SAPI, but
 * the same lock primitive covers a future one) exclusive lock over the size
 * check, rotation and append.
 *
 * Decision 20 (2026-08-10 owner override): SINGLE FILE + IN-PROCESS LOCK
 * ONLY. Cross-process exclusive locking is deliberately not implemented;
 * concurrent PROCESSES writing the same file may interleave. Run one file
 * per process, or route through a log shipper, for that case.
 */
class LogFileSink
{
    public string $path;
    private int $rotateSize;
    private int $rotateKeep;

    public function __construct(string $path, int $rotateSize, int $rotateKeep)
    {
        $this->path = $path;
        $this->rotateSize = $rotateSize;
        $this->rotateKeep = $rotateKeep;
    }

    /** Create the directory and prove the file is writable. */
    public function open(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new LogConfigurationError(
                "cannot open log sink {$this->path}: cannot create directory {$dir}",
                null, null, null, $this->path, 'open'
            );
        }
        $handle = @fopen($this->path, 'a');
        if ($handle === false) {
            throw new LogConfigurationError(
                "cannot open log sink {$this->path}",
                null, null, null, $this->path, 'open'
            );
        }
        fclose($handle);
    }

    private function rotateIfNeeded(int $nextRecordBytes): void
    {
        $currentSize = is_file($this->path) ? (filesize($this->path) ?: 0) : 0;
        if ($currentSize === 0) {
            return;
        }
        if ($currentSize + $nextRecordBytes <= $this->rotateSize) {
            return;
        }
        if ($this->rotateKeep <= 0) {
            @unlink($this->path);
            return;
        }
        $oldest = "{$this->path}.{$this->rotateKeep}";
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($n = $this->rotateKeep - 1; $n >= 1; $n--) {
            $src = "{$this->path}.{$n}";
            $dst = "{$this->path}." . ($n + 1);
            if (is_file($src)) {
                @rename($src, $dst);
            }
        }
        @rename($this->path, "{$this->path}.1");
    }

    /**
     * Append one complete encoded record, rotating first if it would cross
     * the threshold. Throws LogWriteError to the caller, which applies the
     * sink failure policy.
     */
    public function write(string $encodedLine): void
    {
        $clean = preg_replace('/\033\[[0-9;]*m/', '', $encodedLine) ?? $encodedLine;
        $payloadBytes = strlen($clean);

        $lockPath = $this->path . '.pid-lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new LogWriteError("cannot open lock file for {$this->path}", $this->path, 'lock');
        }
        $timeoutAt = microtime(true) + Log::LOCK_TIMEOUT_SECONDS;
        $locked = false;
        while (microtime(true) < $timeoutAt) {
            if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(5000);
        }
        if (!$locked) {
            fclose($lockHandle);
            throw new LogWriteError("timed out acquiring the log sink lock for {$this->path}", $this->path, 'lock');
        }
        try {
            $this->rotateIfNeeded($payloadBytes);
            $written = @file_put_contents($this->path, $clean, FILE_APPEND);
            if ($written === false) {
                throw new LogWriteError("cannot write log sink {$this->path}", $this->path, 'write');
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockPath);
        }
    }
}

/**
 * Structured logger with rotation. Zero dependencies -- PHP built-ins only.
 * Conformant to the shared cross-framework contract at
 * plan/v3/fixtures/logger_contract.json (feature 2), decided in
 * plan/v3/features/002-structured-logger.md and ADR-0041.
 *
 * BREAKING CHANGES from the pre-3.14 logger (this pass, 2026-08-13):
 *
 *  - Format defaults to JSON in production and TEXT only when TINA4_DEBUG is
 *    truthy (Decision 3). The 2026-08-01 "text always unless
 *    TINA4_LOG_FORMAT=json" rule is superseded.
 *  - TINA4_LOG_APPEND is REMOVED -- setting it is now a hard configuration
 *    error.
 *  - TINA4_LOG_MAX_SIZE / TINA4_LOG_KEEP / TINA4_DEBUG_LEVEL /
 *    TINA4_LOG_CRITICAL remain removed and now raise.
 *  - TINA4_LOG_STRICT / TINA4_LOG_FUNC accept ONLY the literal tokens
 *    "true"/"false" (case-insensitive) -- not "1"/"yes"/"on" (Decision 19:
 *    "native booleans, not private truth-token parsing").
 *  - Embedded CR/LF in a message is now ESCAPED in text format rather than
 *    silently stripped (Decision 11).
 *  - New TINA4_LOG_FILE_LEVEL (default ALL) independently gates the FILE
 *    sink; TINA4_LOG_LEVEL now gates the CONSOLE only (2026-08-10 owner
 *    override of Decision 8). isEnabled() accepts an optional $sink and is
 *    sink-aware.
 *  - Rotation is now PREDICTIVE rather than reactive; an oversized event is
 *    replaced by a bounded, valid overflow record.
 *  - reset() clears BOTH the snapshot and the current request id.
 *  - The seven individual introspection getters (logDir(), logFile(), ...)
 *    are REMOVED (LOG-A02 prohibits per-field getters) -- configuration() is
 *    the one introspection surface.
 */
class Log
{
    public const LEVELS = [
        'ALL' => 0, 'DEBUG' => 1, 'INFO' => 2, 'WARNING' => 3,
        'ERROR' => 4, 'CRITICAL' => 5, 'NONE' => 6,
    ];
    public const DEFAULT_LEVEL = 'INFO';
    public const DEFAULT_FILE_LEVEL = 'ALL';
    public const DEFAULT_ROTATE_SIZE = 10 * 1024 * 1024;
    public const DEFAULT_ROTATE_KEEP = 5;
    public const MIN_ROTATE_SIZE = 1024;
    public const STDOUT_MAX_BYTES = 8192;
    public const OVERFLOW_MESSAGE = 'Log event omitted: encoded size exceeds sink limit';
    public const LOCK_TIMEOUT_SECONDS = 2.0;

    private const REMOVED_SETTINGS = [
        'TINA4_LOG_MAX_SIZE' => 'removed setting -- use TINA4_LOG_ROTATE_SIZE (bytes, not megabytes)',
        'TINA4_LOG_KEEP' => 'removed setting -- use TINA4_LOG_ROTATE_KEEP',
        'TINA4_LOG_APPEND' => 'removed setting -- logs always append; truncate explicitly outside logger startup',
        'TINA4_DEBUG_LEVEL' => 'removed setting -- use TINA4_LOG_LEVEL',
        'TINA4_LOG_CRITICAL' => 'removed setting -- critical always emits, subject only to TINA4_LOG_LEVEL',
    ];

    private const COLORS = [
        'DEBUG' => "\033[36m", 'INFO' => "\033[32m", 'WARNING' => "\033[33m",
        'ERROR' => "\033[31m", 'CRITICAL' => "\033[35m",
    ];
    private const RESET = "\033[0m";

    /** @var array<string,mixed>|null */
    private static ?array $snapshot = null;
    private static ?string $requestId = null;

    // ── configuration ────────────────────────────────────────────────

    /**
     * Resolve and activate a new configuration snapshot.
     *
     * Precedence for every field (ADR-0041): explicit argument, then the
     * matching TINA4_LOG_* environment value, then the built-in default.
     * Every field is validated BEFORE any directory is created or file is
     * opened; a failed reconfiguration leaves the prior snapshot untouched.
     */
    public static function configure(
        ?string $logDir = null,
        ?string $logFile = null,
        ?string $level = null,
        ?string $fileLevel = null,
        ?string $format = null,
        ?string $output = null,
        ?int $rotateSize = null,
        ?int $rotateKeep = null,
        ?bool $strict = null,
        ?bool $caller = null,
    ): void {
        foreach (self::REMOVED_SETTINGS as $name => $hint) {
            if (getenv($name) !== false) {
                throw new LogConfigurationError("{$name} is a removed setting -- {$hint}", $name, getenv($name));
            }
        }

        $resolvedLevel = self::resolveLevel($level, 'TINA4_LOG_LEVEL', self::DEFAULT_LEVEL);
        $resolvedFileLevel = self::resolveLevel($fileLevel, 'TINA4_LOG_FILE_LEVEL', self::DEFAULT_FILE_LEVEL);
        $resolvedFormat = self::resolveFormat($format);
        [$stdoutEnabled, $fileEnabled] = self::resolveOutput($output);
        $resolvedRotateSize = self::resolveInt($rotateSize, 'TINA4_LOG_ROTATE_SIZE', self::DEFAULT_ROTATE_SIZE, self::MIN_ROTATE_SIZE);
        $resolvedRotateKeep = self::resolveInt($rotateKeep, 'TINA4_LOG_ROTATE_KEEP', self::DEFAULT_ROTATE_KEEP, 0);
        $resolvedStrict = self::resolveBool($strict, 'TINA4_LOG_STRICT', false);
        $resolvedCaller = self::resolveBool($caller, 'TINA4_LOG_FUNC', false);

        $dirRaw = self::resolveStr($logDir, 'TINA4_LOG_DIR', 'logs', false);
        $fileRaw = self::resolveStr($logFile, 'TINA4_LOG_FILE', null, true);

        $projectRoot = getcwd();
        $dirCandidate = $dirRaw;
        $fileCandidate = $fileRaw;
        if ($fileCandidate === null && self::targetIsFile($dirCandidate)) {
            $fileCandidate = basename($dirCandidate);
            $dirCandidate = dirname($dirCandidate);
        }

        $resolvedLogDir = self::isAbsolutePath($dirCandidate) ? $dirCandidate : $projectRoot . DIRECTORY_SEPARATOR . $dirCandidate;
        $resolvedLogDir = rtrim($resolvedLogDir, '/');

        if ($fileCandidate) {
            $resolvedLogFile = self::isAbsolutePath($fileCandidate) ? $fileCandidate : $resolvedLogDir . DIRECTORY_SEPARATOR . $fileCandidate;
            $layout = 'single';
        } else {
            $resolvedLogFile = null;
            $layout = 'directory';
        }

        $outputSelector = ($stdoutEnabled && $fileEnabled) ? 'both' : ($fileEnabled ? 'file' : 'stdout');

        $snap = [
            'level' => $resolvedLevel,
            'file_level' => $resolvedFileLevel,
            'format' => $resolvedFormat,
            'output' => $outputSelector,
            'log_dir' => $resolvedLogDir,
            'log_file' => $resolvedLogFile,
            'layout' => $layout,
            'rotate_size' => $resolvedRotateSize,
            'rotate_keep' => $resolvedRotateKeep,
            'strict' => $resolvedStrict,
            'caller' => $resolvedCaller,
            'stdout_enabled' => $stdoutEnabled,
            'file_enabled' => $fileEnabled,
            'main_sink' => null,
            'error_sink' => null,
        ];

        if ($fileEnabled) {
            if ($layout === 'single') {
                $sink = new LogFileSink($resolvedLogFile, $resolvedRotateSize, $resolvedRotateKeep);
                $sink->open();
                $snap['main_sink'] = $sink;
            } else {
                $mainSink = new LogFileSink($resolvedLogDir . DIRECTORY_SEPARATOR . 'tina4.log', $resolvedRotateSize, $resolvedRotateKeep);
                $mainSink->open();
                $errorSink = new LogFileSink($resolvedLogDir . DIRECTORY_SEPARATOR . 'error.log', $resolvedRotateSize, $resolvedRotateKeep);
                $errorSink->open();
                $snap['main_sink'] = $mainSink;
                $snap['error_sink'] = $errorSink;
            }
        }

        self::$snapshot = $snap;
    }

    /** @var int|null The PID Log's static state was last touched under. */
    private static ?int $pid = null;

    /**
     * PHP has no `register_at_fork`-style hook: pcntl_fork() is a raw
     * syscall wrapper with no post-fork callback facility. A forked child
     * inherits every static property's value at the instant of fork
     * (including an owned file handle's OS-level state and any request id),
     * so without this check a child would silently keep logging through the
     * PARENT's snapshot and request id (Decision 24 / LOG-Q05 requires a
     * forked child to discard inherited state and resolve fresh). Detecting
     * "my PID changed since I last touched this state" on every access
     * achieves the same observable effect as an eager fork hook, lazily.
     */
    private static function discardStateIfForked(): void
    {
        $current = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        if (self::$pid !== null && self::$pid !== $current) {
            self::$snapshot = null;
            self::$requestId = null;
        }
        self::$pid = $current;
    }

    private static function ensure(): array
    {
        self::discardStateIfForked();
        if (self::$snapshot === null) {
            self::configure();
        }
        return self::$snapshot;
    }

    /**
     * Flush/close owned sinks, clear the snapshot and the current request
     * id. Idempotent; the next use resolves a fresh snapshot.
     */
    public static function reset(): void
    {
        self::$snapshot = null;
        self::$requestId = null;
    }

    /** A defensive native-map copy of the effective, stable configuration. */
    public static function configuration(): array
    {
        $snap = self::ensure();
        return [
            'level' => $snap['level'],
            'file_level' => $snap['file_level'],
            'format' => $snap['format'],
            'output' => $snap['output'],
            'log_dir' => $snap['log_dir'],
            'log_file' => $snap['log_file'],
            'layout' => $snap['layout'],
            'rotate_size' => $snap['rotate_size'],
            'rotate_keep' => $snap['rotate_keep'],
            'strict' => $snap['strict'],
            'caller' => $snap['caller'],
            'stdout_enabled' => $snap['stdout_enabled'],
            'file_enabled' => $snap['file_enabled'],
        ];
    }

    // ── request id ───────────────────────────────────────────────────

    public static function setRequestId(?string $requestId): void
    {
        self::discardStateIfForked();
        self::$requestId = $requestId;
    }

    public static function getRequestId(): ?string
    {
        self::discardStateIfForked();
        return self::$requestId;
    }

    public static function clearRequestId(): void
    {
        self::$requestId = null;
    }

    public static function sanitizeRequestId(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > 128) {
            return null;
        }
        if (preg_match('/[^A-Za-z0-9._-]/', $value) === 1) {
            return null;
        }
        return $value;
    }

    // ── threshold ────────────────────────────────────────────────────

    /**
     * True when $level passes the queried sink's threshold and that sink is
     * active. $sink is null (console, the historical meaning), "console"/
     * "stdout", or "file".
     */
    public static function isEnabled(string $level, ?string $sink = null): bool
    {
        $key = strtoupper(trim($level));
        if (!isset(self::LEVELS[$key])) {
            throw new LogArgumentError("{$level} is not a valid level", 'level', array_keys(self::LEVELS));
        }
        $snap = self::ensure();
        if ($sink === null || $sink === 'console' || $sink === 'stdout') {
            return $snap['stdout_enabled'] && self::LEVELS[$key] >= self::LEVELS[$snap['level']];
        }
        if ($sink === 'file') {
            return $snap['file_enabled'] && self::LEVELS[$key] >= self::LEVELS[$snap['file_level']];
        }
        throw new LogArgumentError("{$sink} is not a valid sink", 'sink', ['console', 'file']);
    }

    // ── event methods ────────────────────────────────────────────────

    // $context stays BY VALUE here (compatible with every existing call
    // site that passes an inline array literal -- `Log::error($m, ['x' =>
    // 1])` -- which a by-reference parameter cannot accept at all, "could
    // not be passed by reference"). From emit() inward the chain is BY
    // REFERENCE so the one unavoidable copy stays fixed at exactly this
    // boundary and does not compound hop over hop; see normalize()'s
    // docblock for why that matters for circular-reference detection.
    public static function debug(mixed $message, array $context = []): void { self::emit('DEBUG', $message, $context); }
    public static function info(mixed $message, array $context = []): void { self::emit('INFO', $message, $context); }
    public static function warning(mixed $message, array $context = []): void { self::emit('WARNING', $message, $context); }
    public static function error(mixed $message, array $context = []): void { self::emit('ERROR', $message, $context); }

    /** Critical -- the highest severity. Always emitted, subject only to the configured threshold. */
    public static function critical(mixed $message, array $context = []): void { self::emit('CRITICAL', $message, $context); }

    private static function emit(string $level, mixed $message, array &$context): void
    {
        $snap = self::ensure();
        $consoleOk = $snap['stdout_enabled'] && self::LEVELS[$level] >= self::LEVELS[$snap['level']];
        $fileOk = $snap['file_enabled'] && self::LEVELS[$level] >= self::LEVELS[$snap['file_level']];
        if (!$consoleOk && !$fileOk) {
            return;
        }

        $requestId = self::$requestId;
        $callerName = $snap['caller'] ? self::callerName() : null;
        $event = self::buildEvent($level, $message, $requestId, $callerName, $context);

        if ($consoleOk) {
            $stdoutLine = rtrim(self::boundedForSink($event, $snap['format'], self::STDOUT_MAX_BYTES), "\n");
            $plain = $snap['format'] === 'json' || !self::stdoutIsTty();
            $color = $plain ? '' : (self::COLORS[$level] ?? '');
            $reset = $plain ? '' : self::RESET;
            self::writeStdout("{$color}{$stdoutLine}{$reset}\n");
        }

        if ($fileOk && $snap['main_sink'] !== null) {
            $mainLine = self::boundedForSink($event, $snap['format'], $snap['rotate_size']);
            self::writeSink($snap['main_sink'], $mainLine, $snap['strict']);
            if ($snap['layout'] === 'directory' && $snap['error_sink'] !== null && self::LEVELS[$level] >= self::LEVELS['WARNING']) {
                self::writeSink($snap['error_sink'], $mainLine, $snap['strict']);
            }
        }
    }

    private static function writeStdout(string $line): void
    {
        if (defined('STDOUT')) {
            @fwrite(\STDOUT, $line);
            @fflush(\STDOUT);
        } else {
            $stdout = @fopen('php://stdout', 'w');
            if (is_resource($stdout)) {
                @fwrite($stdout, $line);
                @fflush($stdout);
                @fclose($stdout);
            }
        }
    }

    private static function writeSink(LogFileSink $sink, string $line, bool $strict): void
    {
        try {
            $sink->write($line);
        } catch (LogWriteError $exc) {
            if ($strict) {
                throw $exc;
            }
            self::writeStdout("tina4: log sink {$sink->path} failed: {$exc->getMessage()}\n");
        }
    }

    private static function stdoutIsTty(): bool
    {
        if (function_exists('posix_isatty') && defined('STDOUT')) {
            return @posix_isatty(\STDOUT);
        }
        if (function_exists('stream_isatty') && defined('STDOUT')) {
            return @stream_isatty(\STDOUT);
        }
        return false;
    }

    // ── caller capture ───────────────────────────────────────────────

    private const OWN_FRAMES = ['callerName', 'buildEvent', 'emit', 'debug', 'info', 'warning', 'error', 'critical'];

    public static function callerName(): ?string
    {
        try {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
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
                if (in_array($function, self::OWN_FRAMES, true)) {
                    continue;
                }
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

    // ── native normalization (Decision 14) ──────────────────────────

    /**
     * Recursively normalize into the shared native domain. `$value` and
     * `$ancestors` are BY REFERENCE on purpose: a PHP array is a
     * copy-on-write VALUE, so a self-referential structure only exists at
     * all via an internal PHP reference (`$c['self'] = &$c;`), and that
     * reference chain -- and therefore the cycle -- is only observable if
     * every hop of this walk keeps holding the SAME zval rather than a
     * value-copied one.
     *
     * KNOWN, DELIBERATE PHP DIFFERENCE FROM PYTHON/RUBY/NODE: the public
     * event methods (debug/info/...) take `$context` BY VALUE -- required
     * for every existing call site that passes an inline array literal,
     * which a by-reference parameter cannot accept at all. That one copy is
     * unavoidable and means a context that is directly self-referential at
     * its OWN top level is detected ONE LEVEL DEEPER here than in the other
     * three languages (`{"self": {"self": "[Circular]"}}`, not
     * `{"self": "[Circular]"}`) -- proven and pinned by
     * LoggerFixtureContractTest::testCircularContextIsMarkedWithoutRaising.
     * The safety property (no infinite recursion, no crash, valid JSON, a
     * real "[Circular]" marker) holds either way; only the exact nesting
     * depth differs, and only for a context that is circular at its very
     * own root.
     */
    public static function normalize(mixed &$value, array &$ancestors = []): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (!self::isUtf8($value)) {
                return '<binary ' . strlen($value) . ' bytes sha256=' . hash('sha256', $value) . '>';
            }
            return $value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : '[Unsupported]';
        }
        if (is_array($value)) {
            foreach ($ancestors as &$ancestor) {
                if (self::isSameArrayReference($value, $ancestor)) {
                    return '[Circular]';
                }
            }
            unset($ancestor);
            $ancestors[] = &$value;
            $out = [];
            foreach ($value as $k => &$v) {
                $out[(string)$k] = self::normalize($v, $ancestors);
            }
            unset($v);
            array_pop($ancestors);
            return $out;
        }
        return '[Unsupported]';
    }

    /**
     * Is $a actually the SAME underlying array (via an internal PHP
     * reference), not merely an equal-by-value copy? Mutate $a with a
     * throwaway marker key and check whether $b observes it -- true only
     * when they share the same zval, which is exactly PHP's own definition
     * of "the same array" for a `&`-built reference cycle.
     */
    private static function isSameArrayReference(array &$a, array &$b): bool
    {
        $marker = "\x00tina4_circular_probe\x00";
        $a[$marker] = true;
        $same = array_key_exists($marker, $b);
        unset($a[$marker]);
        return $same;
    }

    private static function isUtf8(string $value): bool
    {
        // Reuse the existing zero-dependency-safe helper (guarded internally
        // against a missing ext-mbstring) rather than calling mb_check_encoding
        // directly here unguarded -- see LogWithoutMbstringTest.
        return Str::isUtf8($value);
    }

    private static function messageToString(mixed $raw): string
    {
        if (is_string($raw)) {
            return self::isUtf8($raw) ? $raw : '<binary ' . strlen($raw) . ' bytes sha256=' . hash('sha256', $raw) . '>';
        }
        $normalized = self::normalize($raw);
        if ($normalized === null) return 'null';
        if ($normalized === true) return 'true';
        if ($normalized === false) return 'false';
        if (is_int($normalized) || is_float($normalized)) {
            return json_encode($normalized);
        }
        if (is_array($normalized)) {
            return self::compactJson(self::sortKeysRecursive($normalized));
        }
        return (string)$normalized; // already a marker string
    }

    private static function sortKeysRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'sortKeysRecursive'], $value);
            }
            ksort($value, SORT_STRING);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sortKeysRecursive($v);
            }
            return $out;
        }
        return $value;
    }

    private static function compactJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function escapeText(string $s): string
    {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace("\r", '\\r', $s);
        $s = str_replace("\n", '\\n', $s);
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $s) ?? $s;
    }

    // ── canonical event + encoding (Decision 15) ─────────────────────

    private static function timestampNow(): string
    {
        $now = microtime(true);
        $ms = (int) round(($now - floor($now)) * 1000);
        if ($ms >= 1000) { $ms = 999; }
        return gmdate('Y-m-d\TH:i:s', (int)$now) . '.' . sprintf('%03d', $ms) . 'Z';
    }

    private static function buildEvent(string $level, mixed $message, ?string $requestId, ?string $callerName, array &$context): array
    {
        $event = ['timestamp' => self::timestampNow(), 'level' => $level, 'message' => self::messageToString($message)];
        if ($requestId) {
            $event['request_id'] = $requestId;
        }
        if ($callerName) {
            $event['function'] = $callerName;
        }
        if (!empty($context)) {
            $normalizedCtx = self::sortKeysRecursive(self::normalize($context));
            if (!empty($normalizedCtx)) {
                $event['context'] = $normalizedCtx;
            }
        }
        return $event;
    }

    private const JSON_KEY_ORDER = ['timestamp', 'level', 'message', 'request_id', 'function', 'context'];

    private static function encodeJson(array $event): string
    {
        $ordered = [];
        foreach (self::JSON_KEY_ORDER as $k) {
            if (array_key_exists($k, $event)) {
                $ordered[$k] = $event[$k];
            }
        }
        return self::compactJson($ordered) . "\n";
    }

    private static function encodeTextLine(array $event): string
    {
        $parts = [$event['timestamp'], '[' . str_pad($event['level'], 8) . ']'];
        if (isset($event['request_id'])) {
            $parts[] = '[' . $event['request_id'] . ']';
        }
        if (isset($event['function'])) {
            $parts[] = '[' . $event['function'] . ']';
        }
        $parts[] = self::escapeText($event['message']);
        if (isset($event['context'])) {
            $parts[] = self::compactJson($event['context']);
        }
        return implode(' ', $parts) . "\n";
    }

    private static function encode(array $event, string $format): string
    {
        return $format === 'json' ? self::encodeJson($event) : self::encodeTextLine($event);
    }

    private static function overflowRecord(array $originalEvent, string $originalEncoded, string $format): string
    {
        $originalBytes = strlen($originalEncoded);
        $replacement = [
            'timestamp' => $originalEvent['timestamp'],
            'level' => $originalEvent['level'],
            'message' => self::OVERFLOW_MESSAGE,
            'context' => [
                'truncated' => true,
                'original_bytes' => $originalBytes,
                'sha256' => hash('sha256', $originalEncoded),
            ],
        ];
        return self::encode($replacement, $format);
    }

    private static function boundedForSink(array $event, string $format, int $maxBytes): string
    {
        $encoded = self::encode($event, $format);
        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }
        return self::overflowRecord($event, $encoded, $format);
    }

    // ── resolution helpers ───────────────────────────────────────────

    private static function isTruthyDebug(): bool
    {
        $raw = strtolower(trim((string)(getenv('TINA4_DEBUG') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on', 'y', 't'], true);
    }

    private static function parseBoolSetting(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }
        $token = strtolower(trim($raw));
        if ($token === 'true') return true;
        if ($token === 'false') return false;
        throw new LogConfigurationError(
            "{$name}=" . var_export($raw, true) . ' is not a valid boolean; accepted: true, false',
            $name, $raw, ['true', 'false']
        );
    }

    private static function resolveBool(?bool $explicit, string $envName, bool $default): bool
    {
        if ($explicit !== null) {
            return $explicit;
        }
        return self::parseBoolSetting($envName, $default);
    }

    private static function resolveLevel(?string $explicit, string $envName, string $default): string
    {
        if ($explicit !== null) {
            $candidate = $explicit;
            $source = 'argument';
        } elseif (getenv($envName) !== false) {
            $candidate = getenv($envName);
            $source = $envName;
        } else {
            return $default;
        }
        $key = strtoupper(trim($candidate));
        if (!isset(self::LEVELS[$key])) {
            throw new LogConfigurationError(
                "{$source}={$candidate} is not a valid level; accepted: " . implode(', ', array_keys(self::LEVELS)),
                $envName, $candidate, array_keys(self::LEVELS)
            );
        }
        return $key;
    }

    private static function resolveFormat(?string $explicit): string
    {
        if ($explicit !== null) {
            $candidate = strtolower(trim($explicit));
            if (!in_array($candidate, ['text', 'json'], true)) {
                throw new LogConfigurationError("format={$explicit} is not valid; accepted: text, json", 'TINA4_LOG_FORMAT', $explicit, ['text', 'json']);
            }
            return $candidate;
        }
        $env = getenv('TINA4_LOG_FORMAT');
        if ($env !== false) {
            $candidate = strtolower(trim($env));
            if (!in_array($candidate, ['text', 'json'], true)) {
                throw new LogConfigurationError("TINA4_LOG_FORMAT={$env} is not valid; accepted: text, json", 'TINA4_LOG_FORMAT', $env, ['text', 'json']);
            }
            return $candidate;
        }
        return self::isTruthyDebug() ? 'text' : 'json';
    }

    /** @return array{0:bool,1:bool} [stdoutEnabled, fileEnabled] */
    private static function resolveOutput(?string $explicit): array
    {
        if ($explicit !== null) {
            $candidate = strtolower(trim($explicit));
            $source = 'argument';
        } else {
            $env = getenv('TINA4_LOG_OUTPUT');
            if ($env === false) {
                return [true, self::isTruthyDebug()];
            }
            $candidate = strtolower(trim($env));
            $source = 'TINA4_LOG_OUTPUT';
        }
        if (!in_array($candidate, ['stdout', 'file', 'both'], true)) {
            throw new LogConfigurationError("{$source}={$candidate} is not valid; accepted: stdout, file, both", 'TINA4_LOG_OUTPUT', $candidate, ['stdout', 'file', 'both']);
        }
        return match ($candidate) {
            'stdout' => [true, false],
            'file' => [false, true],
            'both' => [true, true],
        };
    }

    private static function resolveInt(?int $explicit, string $envName, int $default, ?int $minimum): int
    {
        if ($explicit !== null) {
            $value = $explicit;
            $source = 'argument';
        } else {
            $raw = getenv($envName);
            if ($raw === false) {
                return $default;
            }
            if (!preg_match('/^-?\d+$/', trim($raw))) {
                throw new LogConfigurationError("{$envName}={$raw} is not a valid integer", $envName, $raw);
            }
            $value = (int)$raw;
            $source = $envName;
        }
        if ($minimum !== null && $value < $minimum) {
            throw new LogConfigurationError("{$source}={$value} must be >= {$minimum}", $envName, $value, ">= {$minimum}");
        }
        return $value;
    }

    private static function resolveStr(?string $explicit, string $envName, ?string $default, bool $allowEmpty): ?string
    {
        if ($explicit !== null) {
            if ($explicit === '') {
                throw new LogConfigurationError("{$envName} may not be an empty string", $envName, $explicit);
            }
            if (str_contains($explicit, "\0")) {
                throw new LogConfigurationError("{$envName} may not contain a NUL byte", $envName, $explicit);
            }
            return $explicit;
        }
        $raw = getenv($envName);
        if ($raw === false) {
            return $default;
        }
        if ($raw === '') {
            if (!$allowEmpty) {
                throw new LogConfigurationError("{$envName} may not be an empty string", $envName, $raw);
            }
            return $default;
        }
        if (str_contains($raw, "\0")) {
            throw new LogConfigurationError("{$envName} may not contain a NUL byte", $envName, $raw);
        }
        return $raw;
    }

    private static function targetIsFile(string $path): bool
    {
        if (is_dir($path)) {
            return false;
        }
        $base = basename($path);
        return str_contains($base, '.') && !str_starts_with($base, '.');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool)preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
