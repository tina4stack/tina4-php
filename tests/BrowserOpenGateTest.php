<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
 declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * `tina4 serve` MAY OPEN A BROWSER ONLY FOR A DEVELOPER AT A DESK.
 *
 * App::run() opened the default browser whenever the banner was printed. It did
 * not read TINA4_NO_BROWSER, it did it with TINA4_DEBUG off (a production boot),
 * and it did it under CI. Every test that booted a server without suppressing
 * the banner opened a tab on the machine running the suite.
 *
 * The browser now opens only when ALL of these hold: development mode is on,
 * TINA4_NO_BROWSER is not truthy, and no CI variable is set.
 *
 * NO MOCKS. Each case boots a real `tina4 serve` child process whose PATH
 * starts with a directory holding real `open` / `xdg-open` shell scripts that
 * append their argument to a marker file. App::run() launches the platform's
 * opener exactly as it would on a developer's machine; the test observes that
 * process launch, and no real browser is touched by the positive control.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserOpenGateTest extends TestCase
{
    /** The CI variables App::CI_ENVIRONMENT_VARIABLES lists, written out so the test does not trust the code it checks. */
    private const CI_VARIABLES = ['CI', 'CONTINUOUS_INTEGRATION', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'JENKINS_URL', 'TF_BUILD', 'TEAMCITY_VERSION'];

    /** Seconds to wait for an opener that should (or must not) run. */
    private const OPEN_WAIT_SECONDS = 4;

    /** @var list<array{proc: resource, dir: string}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if (!is_resource($server['proc'])) {
                continue;
            }
            $pid = proc_get_status($server['proc'])['pid'] ?? 0;
            proc_terminate($server['proc'], SIGTERM);
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && (proc_get_status($server['proc'])['running'] ?? false)) {
                usleep(50000);
            }
            if ($pid > 0 && (proc_get_status($server['proc'])['running'] ?? false)) {
                @posix_kill($pid, SIGKILL);
            }
            @proc_close($server['proc']);
        }
        $this->servers = [];
    }

    /**
     * Boot a real server with $env layered over a CLEAN environment (no CI
     * variable, no TINA4_NO_BROWSER - the phpunit bootstrap sets that one) and
     * return the marker file the opener writes to.
     *
     * @param array<string, string> $env
     */
    private function bootAndWatch(array $env): string
    {
        $dir = \TempPath::dir('tina4_browser_gate_');
        $port = \FreePort::get();
        $marker = $dir . '/opened.txt';
        mkdir($dir . '/bin', 0755, true);
        foreach (['open', 'xdg-open'] as $opener) {
            file_put_contents($dir . "/bin/{$opener}", "#!/bin/sh\nprintf '%s\\n' \"\$1\" >> " . escapeshellarg($marker) . "\n");
            chmod($dir . "/bin/{$opener}", 0755);
        }

        mkdir($dir . '/src/routes', 0755, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($dir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);
        file_put_contents($dir . '/.env', "TINA4_OVERRIDE_CLIENT=true\n");

        $childEnv = getenv();
        foreach (array_keys($childEnv) as $name) {
            if (in_array($name, ['TINA4_NO_BROWSER', 'TINA4_SUPPRESS', 'TINA4_DEBUG', ...self::CI_VARIABLES], true)) {
                unset($childEnv[$name]);
            }
        }
        $childEnv = array_merge($childEnv, ['PATH' => $dir . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin')], $env);

        $proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
            $pipes,
            $dir,
            $childEnv
        );
        $this->assertIsResource($proc, 'could not start the test server');
        $this->servers[] = ['proc' => $proc, 'dir' => $dir];

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                break;
            }
            usleep(150000);
        }
        $this->assertLessThan($deadline, microtime(true), 'the server never came up: ' . @file_get_contents($dir . '/server.log'));

        // Wait for the opener (it runs in the background), or long enough to
        // be sure it is not coming.
        $until = microtime(true) + self::OPEN_WAIT_SECONDS;
        while (microtime(true) < $until && !is_file($marker)) {
            usleep(100000);
        }
        return $marker;
    }

    public function testADeveloperBootOpensTheBrowserAtTheServer(): void
    {
        // The positive control: without it every case below would pass on a
        // server that never opens anything.
        $marker = $this->bootAndWatch(['TINA4_DEBUG' => 'true']);
        $this->assertFileExists($marker, 'a development boot with nothing suppressing it must open the browser');
        $this->assertMatchesRegularExpression('#^http://localhost:\d+\n$#', (string)file_get_contents($marker));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function suppressed(): array
    {
        return [
            'TINA4_NO_BROWSER=true' => [['TINA4_DEBUG' => 'true', 'TINA4_NO_BROWSER' => 'true'], 'TINA4_NO_BROWSER is set'],
            'TINA4_NO_BROWSER=1' => [['TINA4_DEBUG' => 'true', 'TINA4_NO_BROWSER' => '1'], 'TINA4_NO_BROWSER is set'],
            'production (TINA4_DEBUG=false)' => [['TINA4_DEBUG' => 'false'], 'this is not a development boot'],
            'CI=true' => [['TINA4_DEBUG' => 'true', 'CI' => 'true'], 'a CI variable is set'],
            'GITHUB_ACTIONS=true' => [['TINA4_DEBUG' => 'true', 'GITHUB_ACTIONS' => 'true'], 'a CI variable is set'],
        ];
    }

    /** @param array<string, string> $env */
    #[DataProvider('suppressed')]
    public function testTheBrowserStaysClosedWhenAnyConditionFails(array $env, string $why): void
    {
        $marker = $this->bootAndWatch($env);
        $this->assertFileDoesNotExist($marker, "the browser was opened although {$why}");
    }
}
