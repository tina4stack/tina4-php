<?php

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

final class CliRoutesSafeTest extends TestCase
{
    public function testRoutesDiscoversSourceWithoutExecutingIndexEntrypoint(): void
    {
        $fixture = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/cli_routes_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $invariants = array_column($fixture['invariants'], null, 'id');
        $routePath = $invariants['canonical-route-is-listed']['route_path'];
        $markerName = $invariants['application-entrypoint-is-not-executed']['marker_name'];
        $project = sys_get_temp_dir() . '/tina4-routes-safe-' . bin2hex(random_bytes(6));
        $routeDir = $project . '/src/routes/' . trim($routePath, '/');
        mkdir($routeDir, 0777, true);
        $marker = $project . '/' . $markerName;
        file_put_contents(
            $routeDir . '/get.php',
            "<?php\nreturn static fn (\$request, \$response) => \$response(['ok' => true]);\n"
        );
        file_put_contents(
            $project . '/index.php',
            "<?php\nfile_put_contents(" . var_export($marker, true) . ", 'unsafe');\nthrow new \\RuntimeException('routes executed index.php');\n"
        );

        $cli = dirname(__DIR__) . '/bin/tina4php';
        $process = proc_open(
            [PHP_BINARY, $cli, 'routes'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $project
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        try {
            self::assertSame(0, $status, $stdout . $stderr);
            self::assertStringContainsString($routePath, $stdout);
            self::assertFileDoesNotExist($marker, 'index.php was executed by the routes command');
        } finally {
            @unlink($routeDir . '/get.php');
            @unlink($project . '/index.php');
            @unlink($marker);
            @rmdir($routeDir);
            @rmdir($project . '/src/routes');
            @rmdir($project . '/src');
            @rmdir($project);
        }
    }
}
