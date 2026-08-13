<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MQTT username/password auth and TLS (mqtts://) -- real brokers, no mocks.
 *
 * Mirrors tina4_python tests/test_mqtt_auth_tls.py and tina4-ruby
 * spec/mqtt_auth_tls_spec.rb. Broker layout from plan/v3/spikes/mqtt-infra.sh:
 *
 *   1883  anonymous
 *   1884  allow_anonymous false + password_file   -> the auth tests
 *   8883  TLS, self-signed CA                      -> the mqtts tests
 *
 * The TLS tests matter more than they look. A TLS suite passes just as happily
 * with verification switched off, and then "we have TLS" quietly means "we trust
 * any certificate" -- so the load-bearing assertions are the NEGATIVE ones: a
 * self-signed cert must be REJECTED when its CA is not supplied, and supplying a
 * CA for one client must not make a LATER client trust it.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\Mqtt;
use Tina4\MqttError;

class MqttAuthTlsTest extends TestCase
{
    private string $authUrl;
    private string $tlsUrl;
    private string $anonUrl;
    private string $username;
    private string $password;
    private ?string $ca = null;

    protected function setUp(): void
    {
        $this->authUrl = getenv('TINA4_TEST_MQTT_AUTH_URL') ?: 'mqtt://127.0.0.1:1884';
        $this->tlsUrl = getenv('TINA4_TEST_MQTT_TLS_URL') ?: 'mqtts://127.0.0.1:8883';
        $this->anonUrl = getenv('TINA4_TEST_MQTT_URL') ?: 'mqtt://127.0.0.1:1883';
        $this->username = getenv('TINA4_TEST_MQTT_USERNAME') ?: 'tina4';
        $this->password = getenv('TINA4_TEST_MQTT_PASSWORD') ?: 'testpass';
        $this->ca = $this->caFile();
    }

    private function caFile(): ?string
    {
        foreach (['TINA4_TEST_MQTT_CA_FILE', 'TINA4_MQTT_TEST_CA'] as $name) {
            $v = trim((string) getenv($name));
            if ($v !== '') {
                return $v;
            }
        }
        // Fall back to where mqtt-infra.sh writes its certs by default: its DIR
        // is $TMPDIR/tina4-mqtt-infra, the same location the Ruby helper defaults
        // to, so `sh mqtt-infra.sh && phpunit` just works.
        $tmp = rtrim(getenv('TMPDIR') ?: '/tmp', '/');
        $default = $tmp . '/tina4-mqtt-infra/certs/ca.crt';
        return is_file($default) ? realpath($default) : null;
    }

    private function reachable(string $url): bool
    {
        $p = Mqtt::parseUrl($url);
        $s = @fsockopen($p['host'], $p['port'], $e, $s2, 2);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }

    /**
     * Does the configured CA actually VERIFY the broker's certificate?
     *
     * The guard used to accept any CA file that merely EXISTS. A stale
     * $TMPDIR/tina4-mqtt-infra/certs/ca.crt left behind by an earlier
     * mqtt-infra.sh run therefore made these tests RUN and then fail on a
     * certificate mismatch -- six red tests in every framework caused purely by
     * the environment, with an error that reads like a code regression.
     *
     * A CA that cannot validate the broker is functionally the same as no CA,
     * so prove it with a real handshake and skip when it does not hold. This is
     * self-healing: regenerate the certs and the tests run again.
     *
     * @param string      $url MQTT TLS URL to probe.
     * @param string|null $ca  Path to the candidate CA bundle, or null.
     * @return bool True only when the handshake verifies against that CA.
     */
    private function caVerifies(string $url, ?string $ca): bool
    {
        if ($ca === null || !is_file($ca)) {
            return false;
        }
        $p = Mqtt::parseUrl($url);
        $context = stream_context_create([
            'ssl' => [
                'cafile' => $ca,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $p['host'],
            ],
        ]);
        $socket = @stream_socket_client(
            "ssl://{$p['host']}:{$p['port']}",
            $errno,
            $errstr,
            3,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function requireAuthBroker(): void
    {
        if (!$this->reachable($this->authUrl)) {
            $this->markTestSkipped("auth broker not reachable at {$this->authUrl}");
        }
    }

    private function requireTlsBroker(): void
    {
        if (!$this->reachable($this->tlsUrl) || !$this->caVerifies($this->tlsUrl, $this->ca)) {
            $this->markTestSkipped(
                "TLS broker not reachable at {$this->tlsUrl}, or no CA is configured, or the "
                . "configured CA does not verify the broker's certificate (stale certs -- "
                . "re-run mqtt-infra.sh, or set TINA4_TEST_MQTT_CA_FILE)"
            );
        }
    }

    private function authUrlWith(string $user, string $pw): string
    {
        $p = Mqtt::parseUrl($this->authUrl);
        $scheme = $p['tls'] ? 'mqtts' : 'mqtt';
        $creds = rawurlencode($user) . ':' . rawurlencode($pw);
        return "{$scheme}://{$creds}@{$p['host']}:{$p['port']}";
    }

    // -- auth ---------------------------------------------------------------

    public function testAuthenticatesWithUrlCredentialsAndRoundTrips(): void
    {
        $this->requireAuthBroker();
        $c = new Mqtt(url: $this->authUrlWith($this->username, $this->password), clientId: 'tina4-phpunit-auth-good', timeout: 5);
        $this->assertTrue($c->connected());
        $this->assertSame($this->username, $c->username);
        $topic = 'tina4/phpunit/authtls/authed';
        $this->assertSame(1, $c->subscribe($topic, 1));
        $this->assertIsInt($c->publish($topic, 'authenticated', 1));
        $this->assertSame('authenticated', $c->receive(3)->payload);
        $c->disconnect();
    }

    public function testCredentialsAsKwargs(): void
    {
        $this->requireAuthBroker();
        $c = new Mqtt(url: $this->authUrl, clientId: 'tina4-phpunit-auth-kw', username: $this->username, password: $this->password, timeout: 5);
        $this->assertTrue($c->connected());
        $c->disconnect();
    }

    public function testKwargsWinOverUrl(): void
    {
        $this->requireAuthBroker();
        $wrong = $this->authUrlWith($this->username, 'definitely-wrong');
        $c = new Mqtt(url: $wrong, clientId: 'tina4-phpunit-auth-override', username: $this->username, password: $this->password, timeout: 5);
        $this->assertTrue($c->connected());
        $c->disconnect();
    }

    public function testNoCredentialsRefused(): void
    {
        $this->requireAuthBroker();
        try {
            new Mqtt(url: $this->authUrl, clientId: 'tina4-phpunit-auth-nocreds', timeout: 5);
            $this->fail('anonymous connect to the auth listener should have been refused');
        } catch (MqttError $e) {
            $this->assertStringContainsString('broker refused the connection', $e->getMessage());
            $this->assertStringContainsString('CONNACK return code', $e->getMessage());
        }
    }

    public function testWrongPasswordRefused(): void
    {
        $this->requireAuthBroker();
        $this->expectException(MqttError::class);
        $this->expectExceptionMessageMatches('/CONNACK return code [45]/');
        new Mqtt(url: $this->authUrlWith($this->username, 'wrong-password'), clientId: 'tina4-phpunit-auth-badpass', timeout: 5);
    }

    public function testUnknownUsernameRefused(): void
    {
        $this->requireAuthBroker();
        $this->expectException(MqttError::class);
        $this->expectExceptionMessageMatches('/CONNACK return code [45]/');
        new Mqtt(url: $this->authUrlWith('nobody', $this->password), clientId: 'tina4-phpunit-auth-baduser', timeout: 5);
    }

    public function testAnonymousListenerAcceptsSuppliedCredentials(): void
    {
        // Credentials the broker does not need must not break the CONNECT payload.
        if (!$this->reachable($this->anonUrl)) {
            $this->markTestSkipped('anon broker not reachable');
        }
        $c = new Mqtt(url: $this->anonUrl, clientId: 'tina4-phpunit-anon-creds', username: $this->username, password: $this->password, timeout: 5);
        $this->assertTrue($c->connected());
        $this->assertIsInt($c->publish('tina4/phpunit/authtls/anon', 'fine', 1));
        $c->disconnect();
    }

    // -- TLS ----------------------------------------------------------------

    public function testConnectsWithCaAndNegotiatesRealCipher(): void
    {
        $this->requireTlsBroker();
        $c = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls', caFile: $this->ca, timeout: 5);
        $this->assertTrue($c->connected());
        $this->assertTrue($c->tls());
        // Not "did it connect" but "is it actually encrypted": a negotiated
        // cipher and a TLS version can only come from a completed handshake.
        $this->assertIsString($c->cipher());
        $this->assertNotSame('', $c->cipher());
        $this->assertContains($c->tlsVersion(), ['TLSv1.2', 'TLSv1.3']);
        $c->disconnect();
    }

    public function testPublishSubscribeOverTls(): void
    {
        $this->requireTlsBroker();
        $c = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-rt', caFile: $this->ca, timeout: 5);
        $topic = 'tina4/phpunit/authtls/over-tls';
        $this->assertSame(1, $c->subscribe($topic, 1));
        $c->publish($topic, 'over-tls', 1);
        $this->assertSame('over-tls', $c->receive(5)->payload);
        $c->disconnect();
    }

    public function testReassemblesPayloadLargerThanOneTlsRecord(): void
    {
        // A TLS record is at most 16 KB; a 1 MiB payload spans many records AND
        // leaves decrypted bytes buffered inside the TLS layer. A reader that
        // waits on the raw socket while plaintext already sits in the SSL buffer
        // HANGS here -- blocking fread returns the buffered plaintext instead.
        $this->requireTlsBroker();
        $c = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-big', caFile: $this->ca, timeout: 10, readTimeout: 20);
        $topic = 'tina4/phpunit/authtls/tls-stream';
        $c->subscribe($topic, 1);
        $payload = bin2hex(random_bytes(524288));   // 1,048,576 chars
        $c->publish($topic, $payload, 1);
        $got = $c->receive(20);
        $this->assertSame(1048576, strlen($got->payload));
        $this->assertSame($payload, $got->text());
        $c->disconnect();
    }

    public function testRejectsSelfSignedWhenNoCaSupplied(): void
    {
        // THE test. Everything else about TLS passes identically with
        // verification disabled; only this fails. If it goes green while the
        // positive test also passes, we are not verifying anything.
        $this->requireTlsBroker();
        try {
            new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-noca', timeout: 5);
            $this->fail('a self-signed broker cert must be rejected when no CA is supplied');
        } catch (MqttError $e) {
            $msg = strtolower($e->getMessage());
            $hit = str_contains($msg, 'certificate verif')
                || str_contains($msg, 'self-signed')
                || str_contains($msg, 'self signed')
                || str_contains($msg, 'unable to get local issuer');
            $this->assertTrue($hit, "unexpected TLS rejection message: {$e->getMessage()}");
        }
    }

    public function testNoCaLeakIntoLaterClient(): void
    {
        // A CA loaded for one client must NOT be trusted by a later client. Each
        // client builds its OWN stream context/store, so the second still rejects.
        $this->requireTlsBroker();
        $trusted = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-first', caFile: $this->ca, timeout: 5);
        $this->assertTrue($trusted->connected());
        try {
            new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-second', timeout: 5);
            $this->fail('the CA from the first client must not leak into the second');
        } catch (MqttError) {
            $this->addToAssertionCount(1);
        } finally {
            $trusted->disconnect();
        }
    }

    public function testReadsCaFromEnv(): void
    {
        $this->requireTlsBroker();
        $prev = getenv('TINA4_MQTT_CA_FILE');
        $prevArr = $_ENV['TINA4_MQTT_CA_FILE'] ?? null;
        putenv("TINA4_MQTT_CA_FILE={$this->ca}");
        $_ENV['TINA4_MQTT_CA_FILE'] = $this->ca;
        try {
            $c = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-env', timeout: 5);
            $this->assertTrue($c->connected());
            $c->disconnect();
        } finally {
            if ($prev === false) {
                putenv('TINA4_MQTT_CA_FILE');
                unset($_ENV['TINA4_MQTT_CA_FILE']);
            } else {
                putenv("TINA4_MQTT_CA_FILE={$prev}");
                $_ENV['TINA4_MQTT_CA_FILE'] = $prevArr;
            }
        }
    }

    public function testVerifyOffOnlyWhenExplicitAndLogsLoudly(): void
    {
        // Disabling verification is a foot-gun, so it is never the default and it
        // never happens quietly. Asserted against the REAL log FILE the real Log
        // writes (same approach as tina4-ruby / test_log.py) -- capturing stdout
        // is order-fragile, so point the Log at our own temp file.
        $this->requireTlsBroker();
        $tmpDir = sys_get_temp_dir() . '/tina4_mqtt_log_' . getmypid() . '_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $prevDebug = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=true');
        Log::reset();
        // logger_contract rewrite (2026-08-13): the `development` param is
        // gone -- request both sinks explicitly.
        Log::configure(logDir: $tmpDir, output: 'both');
        try {
            $insecure = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-insecure', tlsVerify: false, timeout: 5);
            $this->assertTrue($insecure->connected());
            $this->assertTrue($insecure->tls());
            $logged = file_get_contents($tmpDir . '/tina4.log');
            $this->assertStringContainsString('verification is DISABLED', $logged);
            $this->assertStringContainsString('man in the middle', $logged);
            $this->assertStringContainsString('TINA4_MQTT_CA_FILE', $logged);
            $insecure->disconnect();
        } finally {
            Log::reset();
            if ($prevDebug === false) {
                putenv('TINA4_DEBUG');
            } else {
                putenv("TINA4_DEBUG={$prevDebug}");
            }
            @unlink($tmpDir . '/tina4.log');
            @rmdir($tmpDir);
        }
    }

    public function testEnvVerifyFlagIsTwoWay(): void
    {
        $this->requireTlsBroker();
        $prev = getenv('TINA4_MQTT_TLS_VERIFY');
        $prevArr = $_ENV['TINA4_MQTT_TLS_VERIFY'] ?? null;
        try {
            putenv('TINA4_MQTT_TLS_VERIFY=false');
            $_ENV['TINA4_MQTT_TLS_VERIFY'] = 'false';
            $insecure = new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-envoff', timeout: 5);
            $this->assertTrue($insecure->connected());
            $insecure->disconnect();

            // The negative half: the env var must not be a one-way door.
            putenv('TINA4_MQTT_TLS_VERIFY=true');
            $_ENV['TINA4_MQTT_TLS_VERIFY'] = 'true';
            $this->expectException(MqttError::class);
            new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-envon', timeout: 5);
        } finally {
            if ($prev === false) {
                putenv('TINA4_MQTT_TLS_VERIFY');
                unset($_ENV['TINA4_MQTT_TLS_VERIFY']);
            } else {
                putenv("TINA4_MQTT_TLS_VERIFY={$prev}");
                $_ENV['TINA4_MQTT_TLS_VERIFY'] = $prevArr;
            }
        }
    }

    public function testMissingCaFileReportedByPath(): void
    {
        $this->requireTlsBroker();
        try {
            new Mqtt(url: $this->tlsUrl, clientId: 'tina4-phpunit-tls-nofile', caFile: '/nonexistent/tina4-ca.crt', timeout: 5);
            $this->fail('a missing CA file must be reported, not silently ignored');
        } catch (MqttError $e) {
            $this->assertStringContainsString('/nonexistent/tina4-ca.crt', $e->getMessage());
            $this->assertStringContainsString('CA file', $e->getMessage());
        }
    }

    public function testAuthAndTlsOnTheSameConnection(): void
    {
        $this->requireTlsBroker();
        if (!$this->reachable($this->authUrl)) {
            $this->markTestSkipped('auth broker not reachable');
        }
        $c = new Mqtt(
            url: $this->tlsUrl,
            clientId: 'tina4-phpunit-tls-auth',
            username: $this->username,
            password: $this->password,
            caFile: $this->ca,
            timeout: 5
        );
        $this->assertTrue($c->connected());
        $this->assertTrue($c->tls());
        $this->assertIsInt($c->publish('tina4/phpunit/authtls/tls-auth', 'both', 1));
        $c->disconnect();
    }
}
