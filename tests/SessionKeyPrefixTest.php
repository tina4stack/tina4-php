<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SESSION CONTRACT: the session key prefix is configurable by env var, on every
 * RESP backend.
 *
 * ADR-0024: swapping one session backend for another changes ONE env var and
 * nothing else. Namespacing the keys those backends write is part of that
 * configuration surface, and it was present in ONE framework out of four.
 *
 * WHY THIS FILE EXISTS. Measured 2026-08-05 across all four frameworks:
 *
 *     TINA4_SESSION_MEMCACHED_PREFIX   python YES  php YES  ruby YES  node YES
 *     TINA4_SESSION_VALKEY_PREFIX      python no   php no   ruby YES  node YES
 *     TINA4_SESSION_REDIS_PREFIX       python no   php no   ruby no   node YES
 *
 * Three tiers for one idea. Two apps sharing a Redis could namespace their
 * sessions on Node and silently collide on PHP - and an operator reading the
 * Node documentation would set a variable PHP ignores, with no error and no
 * signal. That is the ADR-0024 failure mode exactly: identical configuration,
 * different observable outcome.
 *
 * NO MOCKS. Both backends are the real service, and every claim about the key's
 * NAME is checked over a RESP socket THIS TEST owns - never by asking the
 * handler what it thinks it wrote. A handler that lies consistently would pass
 * a self-report; it cannot pass this.
 *
 * THE THREE CASES, and why each is load-bearing:
 *   1. positive   - the env var really names the key ON THE SERVER.
 *   2. precedence - an explicit option still beats the env var. Without it,
 *                   "always read the env var" passes case 1 and breaks every
 *                   caller that passes keyPrefix explicitly.
 *   3. negative   - with nothing set the default is still 'tina4:session:'.
 *                   Without it, "always prepend the variable, empty or not"
 *                   passes cases 1 and 2 and renames every existing key in
 *                   every deployment that never asked for a prefix, which on a
 *                   session store logs everybody out at once.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session\RedisSessionHandler;
use Tina4\Session\ValkeySessionHandler;

class SessionKeyPrefixTest extends TestCase
{
    private const DEFAULT_PREFIX = 'tina4:session:';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** @var array<int, array{0: string, 1: string}> host/port/db/env, per backend. */
    private function backends(): array
    {
        return [
            'redis' => [
                'class' => RedisSessionHandler::class,
                'host' => getenv('TINA4_SESSION_REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('TINA4_SESSION_REDIS_PORT') ?: 6379),
                'db' => (int)(getenv('TINA4_SESSION_REDIS_DB') ?: 0),
                'env' => 'TINA4_SESSION_REDIS_PREFIX',
            ],
            'valkey' => [
                'class' => ValkeySessionHandler::class,
                'host' => getenv('TINA4_SESSION_VALKEY_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('TINA4_SESSION_VALKEY_PORT') ?: 6380),
                'db' => (int)(getenv('TINA4_SESSION_VALKEY_DB') ?: 0),
                'env' => 'TINA4_SESSION_VALKEY_PREFIX',
            ],
        ];
    }

    protected function setUp(): void
    {
        foreach (['TINA4_SESSION_REDIS_PREFIX', 'TINA4_SESSION_VALKEY_PREFIX'] as $name) {
            $this->originalEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    /** Skip only when the service is genuinely absent; under CI that is a failure. */
    private function requireBackend(string $name, string $host, int $port): void
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($sock === false) {
            $message = "{$name} not reachable at {$host}:{$port}";
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        fclose($sock);
    }

    /**
     * Does the server hold this exact key? Asked over OUR socket, in the SAME
     * database the handler wrote to - a fresh connection is always db 0, so
     * without the SELECT this would answer about a database nobody wrote to.
     */
    private function serverHasKey(string $host, int $port, int $database, string $key): bool
    {
        $sock = fsockopen($host, $port, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "could not open a RESP socket to {$host}:{$port}");
        stream_set_timeout($sock, 5);

        try {
            if ($database !== 0) {
                $this->writeCommand($sock, ['SELECT', (string)$database]);
                $selected = fgets($sock);
                $this->assertIsString($selected);
                $this->assertStringStartsWith('+OK', $selected, "SELECT {$database} refused");
            }
            $this->writeCommand($sock, ['EXISTS', $key]);
            $reply = fgets($sock);
            $this->assertIsString($reply, "no EXISTS reply for {$key}");
            $this->assertStringStartsWith(':', $reply, "unexpected EXISTS reply: {$reply}");

            return (int)trim(substr($reply, 1)) === 1;
        } finally {
            fclose($sock);
        }
    }

    private function deleteKeys(string $host, int $port, int $database, array $keys): void
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($sock === false) {
            return;
        }
        stream_set_timeout($sock, 5);
        if ($database !== 0) {
            $this->writeCommand($sock, ['SELECT', (string)$database]);
            fgets($sock);
        }
        foreach ($keys as $key) {
            $this->writeCommand($sock, ['DEL', $key]);
            fgets($sock);
        }
        fclose($sock);
    }

    /** @param string[] $args */
    private function writeCommand($sock, array $args): void
    {
        $wire = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $wire .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        fwrite($sock, $wire);
    }

    public function testSessionKeyPrefixEnvVarNamesTheKeyOnTheServer(): void
    {
        foreach ($this->backends() as $name => $cfg) {
            $this->requireBackend($name, $cfg['host'], $cfg['port']);

            $configured = 'itest' . bin2hex(random_bytes(4)) . ':';
            putenv($cfg['env'] . '=' . $configured);

            $handler = new $cfg['class']([
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'db' => $cfg['db'],
                'ttl' => 60,
            ]);
            $sessionId = 'prefix-' . bin2hex(random_bytes(4));

            try {
                $handler->write($sessionId, ['seeded' => true]);
                $this->assertTrue(
                    $this->serverHasKey($cfg['host'], $cfg['port'], $cfg['db'], $configured . $sessionId),
                    "{$name}: {$cfg['env']}={$configured} was ignored - nothing at "
                    . "{$configured}{$sessionId} on the server"
                );
                // The DEFAULT name must be ABSENT, or the prefix was appended to
                // rather than used, and two deployments would still collide.
                $this->assertFalse(
                    $this->serverHasKey($cfg['host'], $cfg['port'], $cfg['db'], self::DEFAULT_PREFIX . $sessionId),
                    "{$name}: the key was ALSO written under the default prefix"
                );
            } finally {
                $this->deleteKeys($cfg['host'], $cfg['port'], $cfg['db'], [
                    $configured . $sessionId,
                    self::DEFAULT_PREFIX . $sessionId,
                ]);
            }
        }
    }

    public function testSessionKeyPrefixOptionWinsOverTheEnvVar(): void
    {
        foreach ($this->backends() as $name => $cfg) {
            $this->requireBackend($name, $cfg['host'], $cfg['port']);

            putenv($cfg['env'] . '=fromenv:');
            $explicit = 'explicit' . bin2hex(random_bytes(4)) . ':';

            $handler = new $cfg['class']([
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'db' => $cfg['db'],
                'keyPrefix' => $explicit,
                'ttl' => 60,
            ]);
            $sessionId = 'prefix-' . bin2hex(random_bytes(4));

            try {
                $handler->write($sessionId, ['seeded' => true]);
                $this->assertTrue(
                    $this->serverHasKey($cfg['host'], $cfg['port'], $cfg['db'], $explicit . $sessionId),
                    "{$name}: an explicit keyPrefix lost to {$cfg['env']}"
                );
                $this->assertFalse(
                    $this->serverHasKey($cfg['host'], $cfg['port'], $cfg['db'], 'fromenv:' . $sessionId),
                    "{$name}: the env prefix was used even though keyPrefix was given"
                );
            } finally {
                $this->deleteKeys($cfg['host'], $cfg['port'], $cfg['db'], [
                    $explicit . $sessionId,
                    'fromenv:' . $sessionId,
                ]);
            }
        }
    }

    public function testSessionKeyPrefixDefaultsWhenNothingIsSet(): void
    {
        foreach ($this->backends() as $name => $cfg) {
            $this->requireBackend($name, $cfg['host'], $cfg['port']);

            putenv($cfg['env']);

            $handler = new $cfg['class']([
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'db' => $cfg['db'],
                'ttl' => 60,
            ]);
            $ref = new \ReflectionClass($handler);
            $this->assertSame(
                self::DEFAULT_PREFIX,
                $ref->getProperty('keyPrefix')->getValue($handler),
                "{$name}: the documented default was lost"
            );

            $sessionId = 'prefix-' . bin2hex(random_bytes(4));
            try {
                $handler->write($sessionId, ['seeded' => true]);
                $this->assertTrue(
                    $this->serverHasKey($cfg['host'], $cfg['port'], $cfg['db'], self::DEFAULT_PREFIX . $sessionId),
                    "{$name}: nothing at the default key on the server"
                );
            } finally {
                $this->deleteKeys($cfg['host'], $cfg['port'], $cfg['db'], [self::DEFAULT_PREFIX . $sessionId]);
            }
        }
    }
}
