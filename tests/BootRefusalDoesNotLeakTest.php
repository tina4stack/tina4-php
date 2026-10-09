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
 * A refused boot answers the client as a 500 and leaks nothing.
 *
 * App::start() has two guards that refuse a start, and Auth::requireBootSecret()
 * refuses by THROWING. Under a front controller -- PHP-FPM, Apache, `php -S` --
 * index.php runs per request, so that throw answers the CLIENT rather than a
 * console. It used to be raised BEFORE start() installed the exception handler,
 * which made it the one Tina4 error no Tina4 handler ever saw: PHP's default
 * handler answered it, and with display_errors on the client got the exception
 * message, the framework's absolute paths and a full stack trace -- under a 200,
 * because PHP leaves the status alone whenever it displays the error itself.
 *
 * NO MOCKS: a real throwaway project served over real HTTP by `php -S`, with
 * display_errors deliberately ON, which is the configuration that leaked.
 */
final class BootRefusalDoesNotLeakTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/tina4-boot-refusal-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/src/routes', 0777, true);

        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            self::$dir . '/index.php',
            "<?php\n"
            // The misconfiguration this guards: a front controller that shows
            // PHP's errors. The framework must leak nothing even so.
            . "ini_set('display_errors', '1');\n"
            . "ini_set('log_errors', '0');\n"
            . "require {$autoload};\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "\$app->handle();\n"
        );
        // A front controller that has already started its response (a banner, a
        // flushed buffer) before Tina4 boots: the status can no longer be set.
        file_put_contents(
            self::$dir . '/late.php',
            "<?php\n"
            . "ini_set('display_errors', '1');\n"
            . "ini_set('log_errors', '0');\n"
            . "require {$autoload};\n"
            . "echo 'banner';\n"
            . "flush();\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "\$app->handle();\n"
        );
        file_put_contents(self::$dir . '/src/routes/.keep', '');
    }

    public static function tearDownAfterClass(): void
    {
        TestServer::stopAll();
    }

    /** @return array<string, string> */
    private function env(string $secret): array
    {
        return [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SECRET' => $secret,
        ];
    }

    /**
     * GET $path from $server and return [status, body].
     *
     * @return array{0: int, 1: string}
     */
    private function get(TestServer $server, string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Connection: close\r\n",
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents($server->base() . $path, false, $context);
        $this->assertNotFalse($body, "request to {$path} failed: " . $server->log());
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        return [$status, (string)$body];
    }

    public function testShortSecretAnswers500AndLeaksNothing(): void
    {
        $server = TestServer::start(self::$dir . '/index.php', $this->env('short'), self::$dir);
        [$status, $body] = $this->get($server, '/anything');

        $this->assertSame(500, $status, "a refused boot must answer 500, got {$status}: {$body}");
        $this->assertStringNotContainsString('Stack trace', $body, 'the client was given a stack trace');
        $this->assertStringNotContainsString(dirname(__DIR__) . '/Tina4', $body, "the client was given the framework's absolute paths");
        $this->assertStringNotContainsString('RuntimeException', $body, 'the client was given the exception class');
        // Outside dev the reason is for the log, not the client (CWE-209).
        $this->assertStringNotContainsString('TINA4_SECRET', $body, 'a non-dev client was told which setting is wrong');
        $this->assertStringContainsString('Server Error', $body);
        $this->assertStringContainsString(
            'TINA4_SECRET',
            $server->log(),
            'the refusal never reached Tina4 own log'
        );
        // The handler asks whether the dev toolbar's ErrorTracker exists. On a boot
        // refusal DevAdmin is not loaded, so it does not, and asking through the
        // autoloader made Tina4 print a "class not found" hint next to the real
        // error in the log.
        $this->assertStringNotContainsString(
            'No close match',
            $server->log(),
            'the refusal was followed by a spurious class-not-found hint'
        );
        $server->stop();
    }

    /**
     * The same refusal in dev may say WHY -- the message, never the trace or the
     * paths. A developer who cannot see the console (a container, a browser tab)
     * otherwise gets a blank 500 and no idea what to fix.
     */
    public function testDevModeStatesTheReasonButStillNoTrace(): void
    {
        $env = $this->env('short') + [];
        $env['TINA4_DEBUG'] = 'true';
        $server = TestServer::start(self::$dir . '/index.php', $env, self::$dir);
        [$status, $body] = $this->get($server, '/anything');

        $this->assertSame(500, $status);
        $this->assertStringContainsString('TINA4_SECRET', $body, 'dev should name the setting to fix');
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString(dirname(__DIR__) . '/Tina4', $body);
        $server->stop();
    }

    /**
     * Once the response has started there is no status left to set. The handler
     * must not try: setting one would add a "headers already sent" warning,
     * which display_errors then prints into the page.
     */
    public function testRefusalAfterOutputStartedAddsNoWarning(): void
    {
        $server = TestServer::start(self::$dir . '/late.php', $this->env('short'), self::$dir);
        [, $body] = $this->get($server, '/anything');

        $this->assertStringStartsWith('banner', $body);
        $this->assertStringNotContainsString('headers already sent', $body, 'the handler tried to set a status after output began');
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringContainsString('TINA4_SECRET', $server->log(), 'the refusal still has to be logged');
        $server->stop();
    }

    /**
     * The other guard: a v3.11-era un-prefixed variable (DATABASE_URL is the one
     * a container platform sets for you). In a console it exits 2; in a web
     * request an exit() ends the request normally, so every route answered an
     * empty 200 and a health check read the refused app as up.
     */
    public function testLegacyEnvVarRefusalAnswers500(): void
    {
        $env = $this->env(bin2hex(random_bytes(16)));
        $env['DATABASE_URL'] = 'sqlite:///legacy.db';
        $server = TestServer::start(self::$dir . '/index.php', $env, self::$dir);
        [$status, $body] = $this->get($server, '/health');

        $this->assertSame(500, $status, "a refused boot must answer 500, got {$status}: {$body}");
        $this->assertStringContainsString('Server Error', $body);
        $this->assertStringNotContainsString('DATABASE_URL', $body, 'a non-dev client was told which setting is wrong');
        $this->assertStringContainsString('TINA4_DATABASE_URL', $server->log(), 'the refusal never reached the log');
        $server->stop();
    }

    /**
     * A refusal from the App constructor, before start() runs at all: a bad
     * TINA4_LOG_* value is refused by Log::configure(). The logger it refuses is
     * the one the handler reports to, so the reason has to reach PHP's log.
     */
    public function testABadLogSettingAnswers500AndLeaksNothing(): void
    {
        $env = $this->env(bin2hex(random_bytes(16)));
        $env['TINA4_LOG_FORMAT'] = 'xml';
        $server = TestServer::start(self::$dir . '/index.php', $env, self::$dir);
        [$status, $body] = $this->get($server, '/health');

        $this->assertSame(500, $status, "a refused boot must answer 500, got {$status}: {$body}");
        $this->assertStringContainsString('Server Error', $body);
        $this->assertStringNotContainsString('Stack trace', $body, 'the client was given a stack trace');
        $this->assertStringNotContainsString(dirname(__DIR__) . '/Tina4', $body, "the client was given the framework's absolute paths");
        $this->assertStringNotContainsString('TINA4_LOG_FORMAT', $body, 'a non-dev client was told which setting is wrong');
        $this->assertStringContainsString('TINA4_LOG_FORMAT=xml is not valid', $server->log(), 'the refusal reached no log');
        $server->stop();
    }

    /** A good secret is untouched by any of this. */
    public function testGoodSecretStillServes(): void
    {
        $server = TestServer::start(self::$dir . '/index.php', $this->env(bin2hex(random_bytes(16))), self::$dir);
        [$status] = $this->get($server, '/');
        $this->assertNotSame(500, $status, 'a 32-byte secret must boot');
        $server->stop();
    }
}
