<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Service runner with cron-based scheduling, discovery, and process management.
 * Zero dependencies — uses only PHP built-in functions.
 */
class ServiceRunner
{
    /** @var array<string, array{handler: callable, options: array}> Registered services */
    private static array $services = [];

    /** @var array<string, array{pid: int|null, running: bool, startedAt: string|null}> Runtime status */
    private static array $status = [];

    /** @var string Base directory for PID files */
    private static string $pidDir = 'data/services';

    /** @var bool Flag to signal shutdown */
    private static bool $shutdown = false;

    /**
     * Register a named service with its handler and options.
     *
     * @param string $name Unique service name
     * @param callable $handler The handler function, receives a context object
     * @param array $options Configuration: timing, daemon, maxRetries, timeout
     */
    public static function register(string $name, callable $handler, array $options = []): void
    {
        $defaults = [
            'timing' => '',        // cron syntax: "*/5 * * * *"
            'daemon' => false,     // if true, handler manages its own loop
            'maxRetries' => 3,     // restart on crash
            'timeout' => 0,        // seconds, 0 = no limit
        ];

        self::$services[$name] = [
            'handler' => $handler,
            'options' => array_merge($defaults, $options),
        ];

        self::$status[$name] = [
            'pid' => null,
            'running' => false,
            'startedAt' => null,
        ];
    }

    /**
     * Discover services from PHP files in a directory.
     * Each file must return an array with 'name', 'handler', and optional 'timing', 'daemon', etc.
     *
     * @param string $serviceDir Directory to scan
     * @return array<string> Names of discovered services
     */
    public static function discover(string $serviceDir = ''): array
    {
        if ($serviceDir === '') {
            $serviceDir = getenv('TINA4_SERVICE_DIR') ?: 'src/services';
        }

        $discovered = [];

        if (!is_dir($serviceDir)) {
            return $discovered;
        }

        $files = glob($serviceDir . '/*.php');
        if ($files === false) {
            return $discovered;
        }

        foreach ($files as $file) {
            $config = require $file;

            if (!is_array($config) || !isset($config['name'], $config['handler'])) {
                Log::warning('Invalid service file, missing name or handler', ['file' => $file]);
                continue;
            }

            $options = [];
            foreach (['timing', 'daemon', 'maxRetries', 'timeout'] as $key) {
                if (isset($config[$key])) {
                    $options[$key] = $config[$key];
                }
            }

            self::register($config['name'], $config['handler'], $options);
            $discovered[] = $config['name'];
        }

        return $discovered;
    }

    /**
     * Start all registered services or a specific one.
     *
     * @param string|null $name Service name, or null for all
     */
    public static function start(?string $name = null): void
    {
        self::$shutdown = false;
        $sleepTime = (int)(getenv('TINA4_SERVICE_SLEEP') ?: 5);

        $names = $name !== null ? [$name] : array_keys(self::$services);

        foreach ($names as $serviceName) {
            if (!isset(self::$services[$serviceName])) {
                Log::error('Service not found', ['name' => $serviceName]);
                continue;
            }

            $service = self::$services[$serviceName];
            $options = $service['options'];

            // If pcntl is available, fork
            if (function_exists('pcntl_fork') && $name === null) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    Log::error('Failed to fork process', ['name' => $serviceName]);
                    continue;
                }
                if ($pid > 0) {
                    // Parent: record PID
                    self::writePidFile($serviceName, $pid);
                    self::$status[$serviceName] = [
                        'pid' => $pid,
                        'running' => true,
                        'startedAt' => date('c'),
                    ];
                    continue;
                }
                // Child: run the service loop
                self::runLoop($serviceName, $service['handler'], $options, $sleepTime);
                exit(0);
            } else {
                // No fork: run in foreground (blocks)
                self::writePidFile($serviceName, getmypid());
                self::$status[$serviceName] = [
                    'pid' => getmypid(),
                    'running' => true,
                    'startedAt' => date('c'),
                ];
                self::runLoop($serviceName, $service['handler'], $options, $sleepTime);
            }
        }
    }

    /**
     * Stop all or a specific service.
     *
     * @param string|null $name Service name, or null for all
     */
    public static function stop(?string $name = null): void
    {
        $names = $name !== null ? [$name] : array_keys(self::$services);

        foreach ($names as $serviceName) {
            $pidFile = self::pidFilePath($serviceName);

            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);

                // Send SIGTERM if possible
                if ($pid > 0 && function_exists('posix_kill')) {
                    posix_kill($pid, 15); // SIGTERM
                }

                // Write stop file as fallback
                self::writeStopFile($serviceName);

                @unlink($pidFile);
            }

            if (isset(self::$status[$serviceName])) {
                self::$status[$serviceName]['running'] = false;
                self::$status[$serviceName]['pid'] = null;
            }
        }
    }

    /**
     * List all registered services with their status.
     *
     * @return array<string, array{name: string, options: array, running: bool, pid: int|null, startedAt: string|null}>
     */
    public static function list(): array
    {
        $result = [];

        foreach (self::$services as $name => $service) {
            $running = self::isRunning($name);
            $status = self::$status[$name] ?? ['pid' => null, 'running' => false, 'startedAt' => null];

            $result[$name] = [
                'name' => $name,
                'options' => $service['options'],
                'running' => $running,
                'pid' => $status['pid'],
                'startedAt' => $status['startedAt'],
            ];
        }

        return $result;
    }

    /**
     * Check if a service is currently running.
     *
     * @param string $name Service name
     * @return bool
     */
    public static function isRunning(string $name): bool
    {
        $pidFile = self::pidFilePath($name);

        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int)file_get_contents($pidFile);

        if ($pid <= 0) {
            return false;
        }

        // Check if process is actually alive
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: assume running if PID file exists
        return true;
    }

    /**
     * Main service loop. Runs the handler based on timing/daemon options.
     *
     * @param string $name Service name
     * @param callable $handler The handler to execute
     * @param array $options Service options
     * @param int $sleepTime Seconds between loop iterations
     */
    private static function runLoop(string $name, callable $handler, array $options, int $sleepTime = 5): void
    {
        $retries = 0;
        $maxRetries = $options['maxRetries'];
        $lastRun = 0;

        Log::info('Service started', ['name' => $name, 'options' => $options]);

        while (!self::shouldStop($name) && $retries <= $maxRetries) {
            try {
                $context = self::createContext($name, $lastRun);

                if ($options['daemon']) {
                    // Daemon mode: handler manages its own loop
                    $handler($context);
                    break;
                }

                // Cron mode: check timing
                if (empty($options['timing']) || self::matchCron($options['timing'])) {
                    $startTime = time();
                    $handler($context);
                    $lastRun = time();

                    // Reset retries on success
                    $retries = 0;

                    // Check timeout
                    if ($options['timeout'] > 0 && (time() - $startTime) > $options['timeout']) {
                        Log::warning('Service handler exceeded timeout', [
                            'name' => $name,
                            'timeout' => $options['timeout'],
                        ]);
                    }
                }

                sleep($sleepTime);
            } catch (\Throwable $e) {
                $retries++;
                Log::error('Service error', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                    'retry' => $retries,
                    'maxRetries' => $maxRetries,
                ]);

                if ($retries > $maxRetries) {
                    Log::error('Service max retries exceeded, stopping', ['name' => $name]);
                    break;
                }

                sleep($sleepTime);
            }
        }

        // Cleanup
        self::removePidFile($name);
        self::removeStopFile($name);

        Log::info('Service stopped', ['name' => $name]);
    }

    /**
     * Match a 5-field cron pattern against the current time.
     * Fields: minute hour day-of-month month day-of-week
     *
     * Supports:
     *   *       — wildcard, matches any value
     *   N/5     — interval (every 5 units)
     *   1,4,34  — list of values
     *   3       — exact value
     *   1-5     — range of values
     *
     * @param string $pattern Cron pattern (5 fields)
     * @param \DateTimeInterface|null $now Override current time for testing
     * @return bool
     */
    public static function matchCron(string $pattern, ?\DateTimeInterface $now = null): bool
    {
        $fields = preg_split('/\s+/', trim($pattern));

        if (count($fields) !== 5) {
            Log::warning('Invalid cron pattern', ['pattern' => $pattern]);
            return false;
        }

        if ($now === null) {
            $now = new \DateTime();
        }

        $currentValues = [
            (int)$now->format('i'),  // minute
            (int)$now->format('G'),  // hour
            (int)$now->format('j'),  // day of month
            (int)$now->format('n'),  // month
            (int)$now->format('w'),  // day of week (0=Sunday)
        ];

        for ($i = 0; $i < 5; $i++) {
            if (!self::fieldMatches($fields[$i], $currentValues[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a single cron field matches a current value.
     *
     * @param string $field Cron field expression
     * @param int $value Current time value
     * @return bool
     */
    private static function fieldMatches(string $field, int $value): bool
    {
        // Wildcard
        if ($field === '*') {
            return true;
        }

        // List (e.g., "1,3,5")
        if (str_contains($field, ',')) {
            $values = explode(',', $field);
            foreach ($values as $v) {
                if (self::fieldMatches(trim($v), $value)) {
                    return true;
                }
            }
            return false;
        }

        // Interval on wildcard (e.g., "*/5")
        if (preg_match('/^\*\/(\d+)$/', $field, $matches)) {
            $step = (int)$matches[1];
            return $step > 0 && $value % $step === 0;
        }

        // Range with optional step (e.g., "1-5" or "1-5/2")
        if (preg_match('/^(\d+)-(\d+)(\/(\d+))?$/', $field, $matches)) {
            $start = (int)$matches[1];
            $end = (int)$matches[2];
            $step = isset($matches[4]) ? (int)$matches[4] : 1;

            if ($value < $start || $value > $end) {
                return false;
            }

            return ($value - $start) % $step === 0;
        }

        // Exact value
        return (int)$field === $value;
    }

    /**
     * Create the context object passed to service handlers.
     *
     * @param string $name Service name
     * @param int $lastRun Timestamp of last run
     * @return object
     */
    public static function createContext(string $name, int $lastRun = 0): object
    {
        return (object)[
            'running' => true,
            'lastRun' => $lastRun,
            'logger' => new class {
                public function debug(string $msg, array $ctx = []): void { Log::debug($msg, $ctx); }
                public function info(string $msg, array $ctx = []): void { Log::info($msg, $ctx); }
                public function warning(string $msg, array $ctx = []): void { Log::warning($msg, $ctx); }
                public function error(string $msg, array $ctx = []): void { Log::error($msg, $ctx); }
            },
            'name' => $name,
        ];
    }

    /**
     * Check if a service should stop (via stop file or shutdown flag).
     */
    private static function shouldStop(string $name): bool
    {
        if (self::$shutdown) {
            return true;
        }

        $stopFile = self::stopFilePath($name);
        return file_exists($stopFile);
    }

    /**
     * Set the PID directory path.
     */
    public static function setPidDir(string $dir): void
    {
        self::$pidDir = rtrim($dir, '/');
    }

    /**
     * Get the PID directory path.
     */
    public static function getPidDir(): string
    {
        return self::$pidDir;
    }

    /**
     * Get the PID file path for a service.
     */
    public static function pidFilePath(string $name): string
    {
        return self::$pidDir . DIRECTORY_SEPARATOR . $name . '.pid';
    }

    /**
     * Write a PID file for a service.
     */
    public static function writePidFile(string $name, int $pid): void
    {
        $dir = self::$pidDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::pidFilePath($name), (string)$pid);
    }

    /**
     * Remove a PID file for a service.
     */
    public static function removePidFile(string $name): void
    {
        $pidFile = self::pidFilePath($name);
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
    }

    /**
     * Get the stop file path for a service.
     */
    private static function stopFilePath(string $name): string
    {
        return self::$pidDir . DIRECTORY_SEPARATOR . $name . '.stop';
    }

    /**
     * Write a stop file to signal a service to stop.
     */
    private static function writeStopFile(string $name): void
    {
        $dir = self::$pidDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::stopFilePath($name), (string)time());
    }

    /**
     * Remove a stop file.
     */
    private static function removeStopFile(string $name): void
    {
        $stopFile = self::stopFilePath($name);
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }
    }

    /**
     * Signal shutdown for all services.
     */
    public static function shutdown(): void
    {
        self::$shutdown = true;
    }

    /**
     * Reset all state (useful for testing).
     */
    public static function reset(): void
    {
        self::$services = [];
        self::$status = [];
        self::$shutdown = false;
        self::$pidDir = 'data/services';
    }
}
