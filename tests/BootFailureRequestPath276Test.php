<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression for tina4-php#276: a failure inside App::start() must not reach the
 * client as a 200.
 *
 * No mocks. A REAL `php -S` runs the full App boot path (tests/fixtures/
 * boot_failure_276_app.php) and a raw socket reads exactly what it answered.
 *
 * Two boot failures were observed under `php -S`:
 *   1. A TINA4_SECRET shorter than 32 bytes threw out of start() BEFORE start()
 *      installed its exception handler, so PHP's default handler answered: 200
 *      OK with "Fatal error: Uncaught RuntimeException …" and a stack trace of
 *      server file paths when display_errors was on, an empty 500 when off.
 *   2. An unreachable database while migrations/ held a .sql file threw out of
 *      autoMigrateOnStartup() (the DB was resolved OUTSIDE the try), escaping
 *      start()/__invoke()/handle(): every route — /health included — answered
 *      200 with an empty body, while the auto-migrator's docblock promises the
 *      service still starts.
 *
 * Expected (and what the other three frameworks already do): a boot failure is
 * logged once and answered with a plain 500 carrying no detail, whatever
 * display_errors says; an unreachable database at startup is logged and the app
 * keeps serving, /health included.
 *
 * Mutation check: reverting either fix in App.php reproduces the exact defect —
 * revert the __invoke() try/catch and case 1 goes back to a 200 (with the trace
 * under display_errors=1); revert the auto-migrate DB-resolution move and case 2
 * goes back to a 200 with an empty /health body.
 */
class BootFailureRequestPath276Test extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/boot_failure_276_app.php';

    /** @var string[] temp dirs created this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            @array_map('unlink', glob($dir . '/migrations/*') ?: []);
            @rmdir($dir . '/migrations');
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function baseEnv(): array
    {
        $env = getenv();
        $env['TINA4_NO_BROWSER'] = 'true';
        $env['TINA4_SUPPRESS'] = 'true';
        $env['TINA4_DEBUG'] = 'false';
        return $env;
    }

    // ── Case 1: a short secret gives a detail-free 500 under BOTH display_errors ──

    public function testShortSecretGivesAPlain500WithDisplayErrorsOn(): void
    {
        $this->assertShortSecretIs500('1');
    }

    public function testShortSecretGivesAPlain500WithDisplayErrorsOff(): void
    {
        $this->assertShortSecretIs500('0');
    }

    private function assertShortSecretIs500(string $displayErrors): void
    {
        $env = $this->baseEnv();
        $env['TINA4_SECRET'] = 'short-secret-20bytes'; // 20 bytes < the 32-byte floor
        $env['REPRO_DISPLAY_ERRORS'] = $displayErrors;

        $server = \TestServer::start(self::FIXTURE, $env);
        try {
            $res = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/');
            $this->assertSame(
                '500',
                substr($res['status'], 0, 3),
                "a short secret must answer 500, not 200 (display_errors={$displayErrors}); "
                . "status was {$res['status']}; log: {$server->log()}"
            );
            // No leak, whatever display_errors said: no PHP fatal banner, no
            // "Stack trace", no server .php path, no exception class name.
            foreach (['Fatal error', 'Stack trace', 'RuntimeException', 'Auth.php', '.php(', 'TINA4_SECRET'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $res['body'],
                    "the 500 body must not leak boot detail ('{$needle}') (display_errors={$displayErrors}); body: {$res['body']}"
                );
            }
        } finally {
            $server->stop();
        }
    }

    // ── Case 2: an unreachable database at startup keeps the app serving ─────────

    public function testUnreachableDatabaseAtStartupKeepsHealthServing(): void
    {
        $base = $this->makeBaseWithMigration();

        $env = $this->baseEnv();
        $env['TINA4_SECRET'] = bin2hex(random_bytes(32)); // 64 hex chars — valid
        // A PostgreSQL URL pointing at a closed port: getDatabase() connects
        // eagerly, so this throws during startup auto-migration.
        $env['TINA4_DATABASE_URL'] = 'postgres://127.0.0.1:1/x';
        $env['REPRO_BASE'] = $base;

        $server = \TestServer::start(self::FIXTURE, $env, $base);
        try {
            $health = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/health');
            $this->assertSame(
                '200',
                substr($health['status'], 0, 3),
                "an unreachable database at startup must not take /health down; "
                . "status was {$health['status']}; log: {$server->log()}"
            );
            $decoded = json_decode($health['body'], true);
            $this->assertIsArray($decoded, "/health must still return its JSON body; got: {$health['body']}");
            $this->assertSame('ok', $decoded['status'] ?? null, "/health must report ok; body: {$health['body']}");

            // And the app genuinely booted — a route answers rather than the
            // empty-body 200 the escaping throw produced.
            $root = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/');
            $this->assertNotSame(
                '',
                trim($root['body']),
                "GET / must answer with a real body, not the empty 200 the escaping "
                . "startup throw produced; status {$root['status']}; log: {$server->log()}"
            );
        } finally {
            $server->stop();
        }
    }

    /** A temp base dir carrying migrations/000001_noop.sql. */
    private function makeBaseWithMigration(): string
    {
        $base = sys_get_temp_dir() . '/tina4-276-' . bin2hex(random_bytes(6));
        if (!mkdir($base . '/migrations', 0777, true) && !is_dir($base . '/migrations')) {
            $this->fail("could not create temp base {$base}");
        }
        file_put_contents($base . '/migrations/000001_noop.sql', "SELECT 1;\n");
        $this->tmpDirs[] = $base;
        return $base;
    }

    // ── raw socket HTTP client ───────────────────────────────────────────────

    /**
     * @param string[] $extraHeaders
     * @return array{status: string, body: string}
     */
    private function rawRequest(
        string $host,
        int $port,
        string $method,
        string $path,
        array $extraHeaders = []
    ): array {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");

        $lines = ["{$method} {$path} HTTP/1.1", "Host: {$host}:{$port}", 'Connection: close'];
        foreach ($extraHeaders as $h) {
            $lines[] = $h;
        }
        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");

        $raw = $this->readUntilClose($socket, 10.0);
        fclose($socket);

        [$head, $bodyText] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $headerLines = explode("\r\n", $head);
        $status = trim((string)preg_replace('#^HTTP/\d\.\d\s+#', '', array_shift($headerLines) ?: ''));

        return ['status' => $status, 'body' => $bodyText];
    }

    private function readUntilClose($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }

        return $buffer;
    }
}
