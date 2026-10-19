<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

use PHPUnit\Framework\TestCase;

/**
 * A JWT secret, a URL with a password in it, and an S3 secret are ordinary
 * string parameters. PHP prints those in a stack trace, and App logs the
 * trace. The value must not survive into that string.
 */
class AuthCredentialLeakTest extends TestCase
{
    public function testAFailureTraceDoesNotCarryTheSecret(): void
    {
        $secret = 'Zq9Auth11Sec';
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $pem = "-----BEGIN PRIVATE KEY-----\n" . $secret . "\n-----END PRIVATE KEY-----";
        $script = '<?php require ' . var_export($autoload, true) . ';'
            . '$secret = ' . var_export($secret, true) . ';'
            . '$pem = ' . var_export($pem, true) . ';'
            . 'try { Tina4\Auth::getToken(["a"=>1], $secret); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            . 'putenv("TINA4_SECRET=" . $secret); $_ENV["TINA4_SECRET"] = $secret;'
            . 'try { Tina4\Auth::getToken(["a"=>1]); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            . 'putenv("TINA4_SECRET"); unset($_ENV["TINA4_SECRET"]);'
            . 'try { Tina4\Auth::getToken(["a"=>1], $secret, 60, "RS256"); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            . 'try { Tina4\Auth::getToken(["a"=>1], $pem, 60, "RS256"); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            . 'try { Tina4\Auth::validToken("a.b.c", $secret, "NOPE"); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            . 'try { foreach ((new Tina4\Api("http://u:$secret@127.0.0.1:1"))->streamBytes("/x", ["timeout"=>1, "connect_timeout"=>0.2]) as $c) {} }'
            . 'catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }'
            // An empty bucket is what makes the constructor throw, with the secret among its arguments.
            . 'putenv("TINA4_STORAGE_BUCKET"); unset($_ENV["TINA4_STORAGE_BUCKET"]);'
            . 'try { new Tina4\Realtime\S3Storage("http://127.0.0.1:1", "k", $secret, "", "r"); } catch (Throwable $e) { echo $e->getTraceAsString(), "\n"; }';
        $file = sys_get_temp_dir() . '/tina4_auth_trace_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $script);
        try {
            $cmd = [PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
                '-d', 'zend.exception_string_param_max_len=1000000', $file];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($proc);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('Auth::sign(', $out, "the signer trace was not printed:\n" . $out);
        $this->assertStringContainsString('Auth::validToken(', $out, "the validToken trace was not printed:\n" . $out);
        $this->assertStringContainsString('openStreamSocket(', $out, "the stream trace was not printed:\n" . $out);
        $this->assertStringContainsString('S3Storage->__construct(', $out, "the S3 trace was not printed:\n" . $out);
        $this->assertStringContainsString('SensitiveParameterValue', $out, 'frames must still be shown, with the secret replaced');
        $this->assertStringNotContainsString($secret, $out, "a stack trace carries the secret:\n" . $out);
    }

    public function testTheSecretParametersAreMarkedSensitive(): void
    {
        $expected = [
            [\Tina4\Auth::class, 'getToken', ['secret']],
            [\Tina4\Auth::class, 'validToken', ['secret']],
            [\Tina4\Auth::class, 'sign', ['secret']],
            [\Tina4\Api::class, 'openStreamSocket', ['url']],
            [\Tina4\Realtime\S3Storage::class, '__construct', ['secret']],
        ];
        $unmarked = [];
        foreach ($expected as [$class, $method, $params]) {
            $byName = [];
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $param) {
                $byName[$param->getName()] = $param;
            }
            foreach ($params as $name) {
                $this->assertArrayHasKey($name, $byName, "$class::$method has no \$$name");
                if ($byName[$name]->getAttributes(SensitiveParameter::class) === []) {
                    $unmarked[] = "$class::$method(\$$name)";
                }
            }
        }
        $this->assertSame([], $unmarked, "these parameters show a credential in any stack trace:\n  - " . implode("\n  - ", $unmarked));
    }
}
