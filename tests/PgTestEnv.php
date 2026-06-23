<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Shared PostgreSQL connection settings for the live-PG integration tests.
 *
 * Resolves host/port/user/pass from TINA4_TEST_POSTGRES_URL when set (CI and the
 * local docker harness expose PG on 55432), falling back to the historical
 * localhost defaults so a bare `phpunit` still works against a default-port PG.
 * Each test keeps its own database name (tests use tina4_php, tina4_rb, tina4)
 * but shares the host/port/credentials so they all hit the provisioned server.
 *
 * This is a plain test helper (NOT a mock) — it only computes connection
 * parameters; every test still talks to the REAL PostgreSQL.
 */
final class PgTestEnv
{
    public string $host = 'localhost';
    public int $port = 55432;
    public string $user = 'tina4';
    public string $pass = 'tina4';

    public static function resolve(): self
    {
        $env = new self();

        $url = getenv('TINA4_TEST_POSTGRES_URL');
        if ($url !== false && $url !== '') {
            $rest = preg_replace('#^postgres(ql)?://#', '', $url);

            $atPos = strpos($rest, '@');
            if ($atPos !== false) {
                $creds = substr($rest, 0, $atPos);
                $rest = substr($rest, $atPos + 1);
                $colon = strpos($creds, ':');
                if ($colon !== false) {
                    $env->user = substr($creds, 0, $colon);
                    $env->pass = substr($creds, $colon + 1);
                } elseif ($creds !== '') {
                    $env->user = $creds;
                }
            }

            $hostport = $rest;
            $slash = strpos($rest, '/');
            if ($slash !== false) {
                $hostport = substr($rest, 0, $slash);
            }

            $portColon = strpos($hostport, ':');
            if ($portColon !== false) {
                $env->host = substr($hostport, 0, $portColon) ?: 'localhost';
                $env->port = (int) substr($hostport, $portColon + 1) ?: 5432;
            } elseif ($hostport !== '') {
                $env->host = $hostport;
            }
        }

        return $env;
    }

    /** TCP-reachability probe used by every PG test's skip guard. */
    public function reachable(float $timeout = 1.0): bool
    {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /** postgres://host:port/<database> for the given database name. */
    public function url(string $database): string
    {
        return sprintf('postgres://%s:%d/%s', $this->host, $this->port, $database);
    }
}
