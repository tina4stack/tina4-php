<?php

namespace Tina4;

/**
 * Identity-checked port takeover, shared by the CLI and the runtime paths.
 *
 * `tina4 serve` reclaims a busy port so the edit-restart loop does not fail with
 * "address already in use". The convenience has a sharp edge: "whatever is
 * listening" is not always the old Tina4 server, and before this class BOTH
 * takeover paths (the CLI `killProcessOnPort` and the runtime bind-failure
 * `Server::freePort`) SIGTERM'd whatever held the port, with NO check that the
 * victim was a Tina4 dev server -- a foreign holder (another dev server, a
 * database, a stray listener) was killed.
 *
 * This is the ONE takeover implementation both paths call (TAKEOVER-DEC-02), so
 * the runtime path can never again be a weaker twin of the CLI path. It adds:
 *
 *  - Identity (TAKEOVER-DEC-01): a Tina4 dev server writes a per-port PID file
 *    (`data/.tina4-serve-<port>.pid`) when it binds and removes it on clean exit.
 *    Takeover only signals a holder whose PID matches that file; a holder with no
 *    matching Tina4 PID file is REFUSED, never killed.
 *  - Dev gate + opt-out (TAKEOVER-DEC-03): takeover runs only in dev
 *    (`TINA4_DEBUG` truthy) and only when not opted out (`TINA4_NO_TAKEOVER` /
 *    `tina4 serve --no-kill`). A production bind never kills a port holder.
 *  - The existing PID safety filter and container guard, unchanged, on top.
 *
 * Refusing is always safe (the developer frees the port by hand); over-killing
 * was the bug this fixes.
 */
class PortTakeover
{
    /** Nothing on the port (or only unsafe PIDs) -- the bind may proceed. */
    public const NOTHING = 'nothing';
    /** A confirmed Tina4 dev server was signalled -- the port is being reclaimed. */
    public const KILLED = 'killed';
    /** The holder is NOT identifiably Tina4 -- refused, nothing killed. */
    public const REFUSED_FOREIGN = 'refused_foreign';
    /** Takeover opted out (TINA4_NO_TAKEOVER / --no-kill) -- refused. */
    public const REFUSED_OPTOUT = 'refused_optout';
    /** Not dev mode -- takeover is dev-only, refused. */
    public const REFUSED_PROD = 'refused_prod';
    /** Inside a container the server IS the container -- skipped, nothing killed. */
    public const SKIPPED_CONTAINER = 'skipped_container';

    /** The statuses that mean a holder was left running on purpose. */
    public const REFUSALS = [self::REFUSED_FOREIGN, self::REFUSED_OPTOUT, self::REFUSED_PROD];

    public static function isTruthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', '1', 'yes', 'on'], true);
    }

    private static function env(string $key, string $default = ''): string
    {
        if (class_exists('\Tina4\DotEnv')) {
            return (string) \Tina4\DotEnv::getEnv($key, $default);
        }
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return (string) $value;
    }

    /** Dev mode = TINA4_DEBUG truthy. Takeover runs only in dev. */
    public static function isDev(): bool
    {
        return self::isTruthy(self::env('TINA4_DEBUG', 'false'));
    }

    /** True when takeover is disabled via TINA4_NO_TAKEOVER. */
    public static function noTakeoverOptedOut(): bool
    {
        return self::isTruthy(self::env('TINA4_NO_TAKEOVER', 'false'));
    }

    /**
     * True when this process is running inside a container.
     *
     * Reclaiming a port makes sense on a dev machine; inside a container the
     * server IS the container, so there is no stale sibling to reclaim from.
     */
    public static function inContainer(): bool
    {
        if (is_file('/.dockerenv') || is_file('/run/.containerenv')) {
            return true;
        }
        $blob = @file_get_contents('/proc/1/cgroup');
        if ($blob === false) {
            return false;
        }
        return str_contains($blob, 'docker')
            || str_contains($blob, 'containerd')
            || str_contains($blob, 'kubepods');
    }

    /**
     * The PIDs from `lsof -ti` output that are safe to signal.
     *
     * Pure so the safety rule can be tested directly. A non-numeric field casts
     * to 0, and signalling PID 0 hits EVERY process in the caller's own process
     * group -- the server kills itself. Accept only all-digit tokens; never PID 0
     * (our group), PID 1 (init), ourselves, or our own process group. This is the
     * PID-SAFETY gate only; whether a survivor is a Tina4 server is the SEPARATE
     * identity check in takeOverPort().
     *
     * @return int[]
     */
    public static function selectablePids(string $lsofOutput, int $me, ?int $myGroup = null): array
    {
        $pids = [];
        foreach (preg_split('/\s+/', trim($lsofOutput)) as $token) {
            if ($token === '' || !ctype_digit($token)) {
                continue;               // never cast junk into a PID
            }
            $pid = (int) $token;
            if ($pid <= 1 || $pid === $me) {
                continue;               // 0 = our group, 1 = init, me = suicide
            }
            if ($myGroup !== null && $pid === $myGroup) {
                continue;
            }
            if (!in_array($pid, $pids, true)) {
                $pids[] = $pid;
            }
        }
        return $pids;
    }

    public static function runtimeDir(?string $baseDir = null): string
    {
        return $baseDir ?? (getcwd() . DIRECTORY_SEPARATOR . 'data');
    }

    public static function pidfilePath(int $port, ?string $baseDir = null): string
    {
        return self::runtimeDir($baseDir) . DIRECTORY_SEPARATOR . ".tina4-serve-{$port}.pid";
    }

    /** Record THIS process as the Tina4 dev server on *port* (best-effort). */
    public static function writePidfile(int $port, ?string $baseDir = null, ?int $pid = null): void
    {
        $dir = self::runtimeDir($baseDir);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(self::pidfilePath($port, $baseDir), (string) ($pid ?? getmypid()));
    }

    /** The PID a Tina4 dev server recorded for *port*, or null if none/garbage. */
    public static function readPidfile(int $port, ?string $baseDir = null): ?int
    {
        $raw = @file_get_contents(self::pidfilePath($port, $baseDir));
        if ($raw === false) {
            return null;
        }
        $token = trim($raw);
        return ctype_digit($token) ? (int) $token : null;
    }

    /** Drop the PID file for *port* (clean shutdown, or after reclaiming it). */
    public static function removePidfile(int $port, ?string $baseDir = null): void
    {
        @unlink(self::pidfilePath($port, $baseDir));
    }

    /** Raw lsof/netstat PID tokens for whatever holds *port*. @return string[] */
    private static function portHolders(int $port): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec('netstat -ano', $output);
            $tokens = [];
            foreach ($output as $line) {
                if (str_contains($line, ":{$port}")
                    && (str_contains($line, 'LISTENING') || str_contains($line, 'ESTABLISHED'))) {
                    $parts = preg_split('/\s+/', trim($line));
                    $last = end($parts);
                    if ($last !== false && ctype_digit($last)) {
                        $tokens[] = $last;
                    }
                }
            }
            return $tokens;
        }
        $output = @shell_exec("lsof -ti :{$port} 2>/dev/null");
        if ($output === null || trim($output) === '') {
            return [];
        }
        return preg_split('/\s+/', trim($output)) ?: [];
    }

    private static function killPid(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        @exec("kill -15 {$pid}");
        return true;
    }

    /**
     * Reclaim *port* ONLY from an identity-confirmed Tina4 dev server.
     *
     * The single guarded path for both the CLI (`tina4 serve`) and the runtime
     * bind-failure fallback. `$dev` and `$noTakeover` are passed in so this stays
     * pure and directly testable; callers resolve them from isDev() /
     * noTakeoverOptedOut().
     *
     * @return array{status:string,port:int,killed:int[],message:string}
     */
    public static function takeOverPort(
        int $port,
        bool $dev,
        bool $noTakeover,
        ?string $baseDir = null,
        float $grace = 0.5
    ): array {
        if ($noTakeover) {
            return self::result(self::REFUSED_OPTOUT, $port, [],
                "Port {$port} is in use and takeover is disabled (TINA4_NO_TAKEOVER/--no-kill) "
                . "-- free it or choose another port.");
        }
        if (!$dev) {
            return self::result(self::REFUSED_PROD, $port, [],
                "Port {$port} is in use; takeover is disabled outside dev mode "
                . "-- free it or choose another port.");
        }
        if (self::inContainer()) {
            return self::result(self::SKIPPED_CONTAINER, $port);
        }

        $tokens = self::portHolders($port);
        if (empty($tokens)) {
            return self::result(self::NOTHING, $port);
        }

        $me = function_exists('posix_getpid') ? posix_getpid() : (int) getmypid();
        $myGroup = function_exists('posix_getpgrp') ? posix_getpgrp() : null;
        $holders = self::selectablePids(implode(' ', $tokens), $me, $myGroup);
        if (empty($holders)) {
            return self::result(self::NOTHING, $port);
        }

        $recorded = self::readPidfile($port, $baseDir);
        $tina4Holders = $recorded === null
            ? []
            : array_values(array_filter($holders, static fn ($pid) => $pid === $recorded));

        if (empty($tina4Holders)) {
            return self::result(self::REFUSED_FOREIGN, $port, [],
                "Port {$port} is held by a non-Tina4 process -- free it or choose another port.");
        }

        $killed = [];
        foreach ($tina4Holders as $pid) {
            if (self::killPid($pid)) {
                $killed[] = $pid;
            }
        }
        if (empty($killed)) {
            return self::result(self::NOTHING, $port);
        }

        self::removePidfile($port, $baseDir);
        if ($grace > 0) {
            usleep((int) ($grace * 1_000_000));
        }
        return self::result(self::KILLED, $port, $killed,
            "Reclaimed port {$port} from Tina4 dev server (PID: " . implode(', ', $killed) . ").");
    }

    /**
     * @param int[] $killed
     * @return array{status:string,port:int,killed:int[],message:string}
     */
    private static function result(string $status, int $port, array $killed = [], string $message = ''): array
    {
        return ['status' => $status, 'port' => $port, 'killed' => $killed, 'message' => $message];
    }
}
