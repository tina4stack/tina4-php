<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * The shared connect bound — TINA4_DATABASE_CONNECT_TIMEOUT.
 *
 *   unit:       SECONDS
 *   default:    10
 *   <= 0:       disables the bound (unbounded — the old behaviour)
 *   garbage:    warn and use 10
 *   on expiry:  throw, naming the host, the port, the elapsed seconds, and
 *               the variable that tunes it
 *
 * A database connect that can block forever hangs the application with no log,
 * no error and no signal: the process sits at 0.0% CPU looking healthy while
 * every request behind it queues. The Node sibling measured a Firebird driver
 * that never called back sitting for 16 minutes.
 *
 * MEASURED HERE (Ubuntu 24.04.4 LTS x86_64, PHP 8.3.6) against a REAL socket
 * that accepts and never replies — a listening socket whose owner never
 * accept()s, so the kernel completes the TCP handshake from the listen backlog
 * and the driver then waits forever for a reply. Nothing is simulated; the peer
 * is the OS TCP stack. With no bound armed, SEVEN of the eight drivers blocked
 * past 25s: ext-pgsql, pdo_pgsql, mysqli, pdo_mysql, pdo_dblib, ext-interbase
 * and PDO_Firebird. Only ext-mongodb stopped on its own (10.01s).
 *
 * Each adapter arms its OWN driver's timeout. No sleep, no signal, no watchdog
 * thread: only the driver can abandon its own socket cleanly, and PHP cannot
 * interrupt a blocking call inside a C driver (MEASURED: pcntl_alarm with an
 * async SIGALRM handler did NOT interrupt ibase_connect() or PDO_Firebird —
 * libfbclient retries through EINTR, so the alarm never lands).
 *
 * The mechanism per driver was chosen by MEASUREMENT, not by documentation —
 * two of the documented ones do not do what they say:
 *
 *   ext-pgsql      connect_timeout=N in the libpq conninfo            3.00s
 *   pdo_pgsql      PDO::ATTR_TIMEOUT — NOT the DSN. pdo_pgsql appends
 *                  its own "connect_timeout=<ATTR_TIMEOUT|30>" to the
 *                  conninfo and libpq lets the LAST duplicate win, so a
 *                  connect_timeout put in the DSN is silently overridden
 *                  (measured: DSN copy still blocked past 25s)          3.00s
 *   mysqli         MYSQLI_OPT_CONNECT_TIMEOUT *and* MYSQLI_OPT_READ_TIMEOUT
 *                  via mysqli_init()/real_connect(). CONNECT_TIMEOUT alone
 *                  bounds only the TCP connect, not the greeting-packet read
 *                  (measured: CONNECT_TIMEOUT alone still blocked past 25s) 3.00s
 *   ext-mongodb    connectTimeoutMS + serverSelectionTimeoutMS on the URI 3.00s
 *   sqlsrv         LoginTimeout in the connection info
 *   pdo_odbc       PDO::ATTR_TIMEOUT (SQL_ATTR_LOGIN_TIMEOUT)
 *   pdo_dblib      PDO::ATTR_TIMEOUT + DBLIB_ATTR_CONNECTION_TIMEOUT
 *   Firebird       NO driver mechanism exists in ext-interbase or
 *                  PDO_Firebird — neither exposes isc_dpb_connect_timeout.
 *                  Bounded by {@see preflightConnectTimeout()} instead.
 *
 * SQLite is absent on purpose: it opens a local file and has no network to
 * block on, so there is nothing to bound.
 */
trait ConnectTimeoutTrait
{
    /** Contract default, in SECONDS, when the variable is unset or garbage. */
    private const CONNECT_TIMEOUT_DEFAULT = 10;

    /** Wall clock at the moment the driver connect was armed. */
    private float $connectStartedAt = 0.0;

    /** Seconds armed for the current connect; <= 0 means unbounded. */
    private int $connectTimeoutArmed = 0;

    /** Raw values already warned about, so a reconnect loop cannot spam the log. */
    private static array $connectTimeoutWarned = [];

    /**
     * Arm the bound and start the clock. Call immediately before the driver
     * connect; the returned value is the timeout to hand the driver, and 0 or
     * less means "do not arm anything — wait indefinitely, as before".
     */
    protected function beginConnectTimeout(): int
    {
        $this->connectTimeoutArmed = $this->resolveConnectTimeout();
        $this->connectStartedAt = microtime(true);

        return $this->connectTimeoutArmed;
    }

    /**
     * Throw the contract exception if the armed bound has been reached.
     *
     * Call this at the adapter's connect-FAILURE site, before its own error
     * path, so a genuine driver error (bad password, no such database) still
     * reports its real cause and only a real expiry reports a timeout.
     *
     * The deadline test needs no tolerance: a driver cannot report a timeout
     * before its own deadline. MEASURED overshoot at a 3s bound — ext-pgsql
     * 3.002781s, pdo_pgsql 3.003829s, mysqli 3.002987s, ext-mongodb 3.003139s.
     *
     * This TRANSLATES the driver's failure rather than pre-empting it: the
     * driver's own timer is the only timer, so the operator's N means N (no
     * grace inflating it to N + something), and the driver's diagnosis — often
     * the more specific one — is KEPT, both appended to the message and set as
     * the previous exception. Without the translation the operator gets the
     * raw driver text ("timeout expired", "MySQL server has gone away", "No
     * suitable servers found"), none of which names a variable to tune and
     * most of which name no host or port.
     *
     * @param string $engine Adapter label for the message (e.g. "PostgresAdapter").
     * @param string $target What it tried to reach, as "host:port".
     * @param string|\Throwable|null $cause The driver's own failure, kept as the cause.
     */
    protected function throwIfConnectTimedOut(
        string $engine,
        string $target,
        string|\Throwable|null $cause = null
    ): void {
        $elapsed = microtime(true) - $this->connectStartedAt;
        if ($this->connectTimeoutArmed <= 0 || $elapsed < $this->connectTimeoutArmed) {
            return;
        }

        $driverSaid = $cause instanceof \Throwable ? $cause->getMessage() : (string) $cause;
        $driverSaid = trim((string) preg_replace('/\s+/', ' ', $driverSaid));

        throw new \RuntimeException(
            sprintf(
                '%s: connect to %s timed out after %.1f seconds '
                . '(TINA4_DATABASE_CONNECT_TIMEOUT=%d seconds; set it to 0 to wait indefinitely)',
                $engine,
                $target,
                $elapsed,
                $this->connectTimeoutArmed
            ) . ($driverSaid !== '' ? ' — the driver said: ' . $driverSaid : ''),
            0,
            $cause instanceof \Throwable ? $cause : null
        );
    }

    /**
     * Bound the connect for a driver that has NO timeout of its own, by
     * reaching the port ourselves first with a bounded TCP connect.
     *
     * This is the Firebird path. ext-interbase and PDO_Firebird expose no
     * connect timeout, and libfbclient retries through EINTR so a signal
     * cannot interrupt it either (both MEASURED). A bounded TCP connect is
     * the only real bound left, and it covers the case that actually hangs
     * indefinitely at the network layer: a host that is down or behind a
     * firewall that DROPS rather than rejects.
     *
     * It does NOT cover a peer that completes the TCP handshake and then never
     * speaks the protocol — the pre-flight connects fine and hands over to the
     * driver, which then waits on libfbclient's own ConnectionTimeout
     * (firebird.conf, default 180s; MEASURED here at 180.10s, so finite but
     * far above this bound). Lowering that would mean writing a firebird.conf
     * and repointing the process-global FIREBIRD env var, which is cached by
     * the client library on first read and would silently change every other
     * Firebird connection in the process — not worth it.
     *
     * Skipped entirely for an embedded/local database (no host), which has no
     * socket to bound.
     *
     * @param string $engine Adapter label for the message.
     * @param string $host   Server host; '' for an embedded/local database.
     * @param int    $port   Server port.
     */
    protected function preflightConnectTimeout(string $engine, string $host, int $port): void
    {
        if ($this->connectTimeoutArmed <= 0 || $host === '' || $port <= 0) {
            return;
        }

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errorNumber,
            $errorString,
            (float) $this->connectTimeoutArmed
        );

        if ($socket !== false) {
            fclose($socket);
            return;
        }

        // Out of time is the contract failure; anything else (refused, DNS)
        // is a real error the driver will report better than we can, so let
        // it through to the driver rather than pre-empting its message.
        $this->throwIfConnectTimedOut($engine, "{$host}:{$port}", (string) $errorString);
    }

    /**
     * Resolve TINA4_DATABASE_CONNECT_TIMEOUT to whole seconds.
     *
     * Unset or blank is the default (silently). Anything that is not a whole
     * number of seconds is garbage: warn once per distinct value and use the
     * default. A value of 0 or less is a deliberate opt-out and is returned
     * as-is for the callers to read as "unbounded".
     */
    private function resolveConnectTimeout(): int
    {
        $raw = \Tina4\DotEnv::getEnv('TINA4_DATABASE_CONNECT_TIMEOUT');
        if ($raw === null || trim($raw) === '') {
            return self::CONNECT_TIMEOUT_DEFAULT;
        }

        $seconds = filter_var(trim($raw), FILTER_VALIDATE_INT);
        if ($seconds === false) {
            if (!isset(self::$connectTimeoutWarned[$raw])) {
                self::$connectTimeoutWarned[$raw] = true;
                \Tina4\Log::warning(
                    'TINA4_DATABASE_CONNECT_TIMEOUT must be a whole number of seconds, got '
                    . var_export($raw, true) . ' — using the default of '
                    . self::CONNECT_TIMEOUT_DEFAULT . ' seconds'
                );
            }

            return self::CONNECT_TIMEOUT_DEFAULT;
        }

        return $seconds;
    }
}
