<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * `tina4php init` must scaffold a .env the framework can boot with.
 *
 * The scaffold wrote TINA4_DEBUG_LEVEL=ALL, a setting the logger removed and now
 * rejects with LogConfigurationError, so every freshly scaffolded project threw
 * on its first log line. Runs the real CLI into a temp dir, then configures the
 * real logger in a child PHP process from the scaffolded file. No mocks.
 */

use PHPUnit\Framework\TestCase;

class InitScaffoldEnvTest extends TestCase
{
    public function testTheScaffoldedEnvBootsTheLogger(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_init_' . uniqid();
        mkdir($dir, 0755, true);
        $cli = realpath(__DIR__ . '/../bin/tina4php');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' init ' . escapeshellarg($dir) . ' 2>&1', $initOutput, $initExit);
        $this->assertSame(0, $initExit, implode("\n", $initOutput));
        $envFile = $dir . '/.env';
        $this->assertFileExists($envFile);
        $this->assertStringNotContainsString('TINA4_DEBUG_LEVEL', (string)file_get_contents($envFile));

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = "require '{$autoload}'; \\Tina4\\DotEnv::loadEnv('{$envFile}'); "
            . "\\Tina4\\Log::configure(); \\Tina4\\Log::info('scaffold boots'); echo 'LOGGED';";
        $env = array_filter(getenv(), fn($key) => !str_starts_with($key, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        $env['TINA4_NO_BROWSER'] = 'true';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('LOGGED', $output);
    }
}
