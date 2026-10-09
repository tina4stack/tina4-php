<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An unreachable database at boot does not take the app down.
 *
 * App::start() applies pending migrations (TINA4_AUTO_MIGRATE, on by default)
 * whenever migrations/ holds a .sql file, and its docblock promises that a
 * failure there is logged and the service still starts. Resolving the database
 * CONNECTS, and that connect used to sit outside the guard: an unreachable
 * database threw out of start() before routing, so under a front controller
 * every route -- /health included -- answered an empty 200, and a load
 * balancer read a dead app as a healthy one.
 *
 * NO MOCKS: a real throwaway project with a real migration, served over real
 * HTTP by `php -S`, pointed at a PostgreSQL port nothing listens on.
 */
final class UnreachableDatabaseAtBootKeepsServingTest extends TestCase
{
    private static string $dir = '';
    private static ?TestServer $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pgsql') && !extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('[needs:postgres] needs ext-pgsql or pdo_pgsql so the connect is attempted and refused');
        }

        self::$dir = sys_get_temp_dir() . '/tina4-unreachable-db-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/src/routes', 0777, true);
        mkdir(self::$dir . '/migrations', 0777, true);

        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            self::$dir . '/index.php',
            "<?php\nrequire {$autoload};\n(new \\Tina4\\App(basePath: __DIR__))->handle();\n"
        );
        // A route that never touches the database.
        file_put_contents(
            self::$dir . '/src/routes/ping.php',
            "<?php\n\\Tina4\\Router::get('/ping', fn (\$request, \$response) => \$response('pong', 200, 'text/plain'))->noAuth();\n"
        );
        // Any pending .sql file is what makes start() open the database.
        file_put_contents(self::$dir . '/migrations/000001_noop.sql', "SELECT 1;\n");

        // A port that was free a moment ago: the connect is refused at once.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = self::$port = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$server = TestServer::start(self::$dir . '/index.php', [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_AUTO_MIGRATE' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SECRET' => bin2hex(random_bytes(32)),
            'TINA4_DATABASE_URL' => "postgres://127.0.0.1:{$port}/tina4_unreachable",
        ], self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stopAll();
    }

    /**
     * GET $path and return [status, body].
     *
     * @return array{0: int, 1: string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Connection: close\r\n",
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents(self::$server->base() . $path, false, $context);
        $this->assertNotFalse($body, "request to {$path} failed: " . self::$server->log());
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        return [$status, (string)$body];
    }

    public function testHealthStillAnswersWithItsJson(): void
    {
        [$status, $body] = $this->get('/health');

        $this->assertSame(200, $status, "/health must stay up, got {$status}: {$body}");
        $health = json_decode($body, true);
        $this->assertIsArray($health, "/health must answer its JSON, got: '{$body}'");
        $this->assertSame('ok', $health['status'] ?? null);
    }

    public function testARouteThatNeedsNoDatabaseStillDispatches(): void
    {
        [$status, $body] = $this->get('/ping');

        $this->assertSame(200, $status, "got {$status}: {$body}");
        $this->assertSame('pong', $body);
    }

    public function testTheFailureIsLoggedAsAStartupMigrationFailure(): void
    {
        $this->get('/ping');

        $log = self::$server->log();
        $this->assertStringContainsString('Startup auto-migration failed', $log);
        // The CONNECT failed, not something before it (a missing driver lands
        // in the same catch and would pass every other check here).
        $this->assertStringContainsString("port='" . self::$port . "'", $log, 'the failure logged was not the refused connect');
        $this->assertStringNotContainsString('Uncaught', $log, 'the connect failure escaped start()');
    }
}
