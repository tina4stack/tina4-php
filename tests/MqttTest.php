<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MQTT 3.1.1 client — real-broker tests (no mocks).
 *
 * Mirrors tina4_python tests/test_mqtt.py and tina4-ruby spec/mqtt_spec.rb.
 * Every network test hits a REAL Eclipse Mosquitto on 127.0.0.1:1883 (the
 * anonymous listener from plan/v3/spikes/mqtt-infra.sh). No test double stands
 * in for the broker: a mock-passing MQTT test proves nothing about the wire
 * protocol, which is the whole point of a hand-rolled client. Tests that need no
 * broker (varint, url parse, QoS-2 refusal) are pure-logic and use no double.
 *
 * The broker-backed tests skip cleanly when nothing is listening on 1883.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Mqtt;
use Tina4\MqttError;

class MqttTest extends TestCase
{
    private const BROKER = 'mqtt://127.0.0.1:1883';

    private static function brokerUp(string $host = '127.0.0.1', int $port = 1883): bool
    {
        $s = @fsockopen($host, $port, $e, $s2, 1);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }

    private function requireBroker(): void
    {
        if (!self::brokerUp()) {
            $this->markTestSkipped('no MQTT broker on 127.0.0.1:1883');
        }
    }

    private function topic(string $suffix): string
    {
        return 'tina4/phpunit/' . bin2hex(random_bytes(4)) . '/' . $suffix;
    }

    // -- pure logic (no broker) --------------------------------------------

    public function testEncodeRemainingLengthVarint(): void
    {
        $this->assertSame('00', bin2hex(Mqtt::encodeRemainingLength(0)));
        $this->assertSame('7f', bin2hex(Mqtt::encodeRemainingLength(127)));
        $this->assertSame('8001', bin2hex(Mqtt::encodeRemainingLength(128)));   // multi-byte boundary
        $this->assertSame('ff7f', bin2hex(Mqtt::encodeRemainingLength(16383)));
        $this->assertSame('ffffff7f', bin2hex(Mqtt::encodeRemainingLength(268435455)));
    }

    public function testEncodeRemainingLengthRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mqtt::encodeRemainingLength(268435456);
    }

    public function testEncodeRemainingLengthRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mqtt::encodeRemainingLength(-1);
    }

    public function testParseUrlPlain(): void
    {
        $this->assertSame(
            ['host' => 'host', 'port' => 1883, 'tls' => false, 'username' => null, 'password' => null],
            Mqtt::parseUrl('mqtt://host:1883')
        );
    }

    public function testParseUrlDefaultsPortByScheme(): void
    {
        $this->assertSame(1883, Mqtt::parseUrl('mqtt://host')['port']);
        $this->assertSame(8883, Mqtt::parseUrl('mqtts://host')['port']);
        $this->assertTrue(Mqtt::parseUrl('mqtts://host')['tls']);
    }

    public function testParseUrlUserinfoPercentDecoded(): void
    {
        $p = Mqtt::parseUrl('mqtt://user:p%40ss%2Fword@host:1883');
        $this->assertSame('user', $p['username']);
        $this->assertSame('p@ss/word', $p['password']);
    }

    public function testParseUrlPlusIsNotASpace(): void
    {
        // rawurldecode, NOT urldecode: a "+" in a password must survive verbatim.
        $this->assertSame('a+b', Mqtt::parseUrl('mqtt://u:a+b@host')['password']);
    }

    public function testParseUrlIpv6Bracketed(): void
    {
        $p = Mqtt::parseUrl('mqtt://[::1]:1883');
        $this->assertSame('::1', $p['host']);
        $this->assertSame(1883, $p['port']);
    }

    public function testParseUrlLastAtWinsForUnencodedAtInPassword(): void
    {
        $p = Mqtt::parseUrl('mqtt://u:pa@ss@host:1883');
        $this->assertSame('host', $p['host']);
        $this->assertSame('pa@ss', $p['password']);
    }

    public function testParseUrlEmptyRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mqtt::parseUrl('   ');
    }

    public function testParseUrlUnsupportedSchemeRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mqtt::parseUrl('ws://host:9001');
    }

    public function testPublishQos2RaisesNamingLimitAndAlternative(): void
    {
        $m = new Mqtt(connect: false);
        try {
            $m->publish('t', 'x', 2);
            $this->fail('QoS 2 publish should have raised');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('QoS 2', $e->getMessage());          // names the limit
            $this->assertStringContainsString('idempotent', $e->getMessage());     // names the alternative
            $this->assertStringContainsString('device_timestamp', $e->getMessage());
        }
    }

    public function testSubscribeQos2Raises(): void
    {
        $m = new Mqtt(connect: false);
        $this->expectException(InvalidArgumentException::class);
        $m->subscribe('t', 2);
    }

    public function testInvalidQosRaises(): void
    {
        $m = new Mqtt(connect: false);
        $this->expectException(InvalidArgumentException::class);
        $m->publish('t', 'x', 5);
    }

    public function testPasswordWithoutUsernameRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Mqtt(url: 'mqtt://host', password: 'secret', connect: false);
    }

    public function testGeneratesClientId(): void
    {
        $m = new Mqtt(connect: false);
        $this->assertStringStartsWith('tina4-', $m->clientId);
    }

    public function testDebugInfoNeverLeaksPassword(): void
    {
        $m = new Mqtt(url: 'mqtt://user:topsecret@host', connect: false);
        $dump = print_r($m->__debugInfo(), true);
        $this->assertStringNotContainsString('topsecret', $dump);
        $this->assertSame('user', $m->username);
    }

    // -- real broker -------------------------------------------------------

    public function testConnectAndDisconnect(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-conn');
        $this->assertTrue($m->connected());
        $m->disconnect();
        $this->assertFalse($m->connected());
    }

    public function testPublishQos0ReturnsNull(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER);
        $this->assertNull($m->publish($this->topic('q0'), 'hello', 0));
        $m->disconnect();
    }

    public function testPublishQos1ReturnsPacketIdAndPubacks(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER);
        $pid = $m->publish($this->topic('q1'), 'hello', 1);
        $this->assertIsInt($pid);
        $this->assertGreaterThanOrEqual(1, $pid);
        $this->assertLessThanOrEqual(0xFFFF, $pid);
        $m->disconnect();
    }

    public function testSubscribeReturnsGrantedQos(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER);
        $this->assertSame(1, $m->subscribe($this->topic('sub'), 1));
        $m->disconnect();
    }

    public function testQos1RoundTripTopicAndPayloadIntact(): void
    {
        $this->requireBroker();
        $t = $this->topic('telemetry');
        $sub = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-sub');
        $sub->subscribe($t, 1);
        $pub = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-pub');
        $pub->publish($t, '{"kwh":12.5}', 1);
        $msg = $sub->receive(5);
        $this->assertSame($t, $msg->topic);
        $this->assertSame('{"kwh":12.5}', $msg->text());
        $this->assertSame(1, $msg->qos);
        $this->assertTrue($msg->isAcknowledged());
        $sub->disconnect();
        $pub->disconnect();
    }

    public function testWildcardSubscription(): void
    {
        $this->requireBroker();
        $base = $this->topic('dev');
        $sub = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-wild');
        $sub->subscribe($base . '/+/telemetry', 1);
        $pub = new Mqtt(url: self::BROKER);
        $pub->publish($base . '/meter-42/telemetry', 'x', 1);
        $msg = $sub->receive(5);
        $this->assertSame($base . '/meter-42/telemetry', $msg->topic);
        $sub->disconnect();
        $pub->disconnect();
    }

    public function testRetainedMessageDeliveredToLateSubscriber(): void
    {
        $this->requireBroker();
        $t = $this->topic('state');
        $pub = new Mqtt(url: self::BROKER);
        $pub->publish($t, 'online', 1, true);
        $late = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-late');
        $late->subscribe($t, 1);
        $msg = $late->receive(5);
        $this->assertSame('online', $msg->payload);
        $this->assertTrue($msg->isRetained());
        $pub->publish($t, '', 1, true);   // clear
        $pub->disconnect();
        $late->disconnect();
    }

    public function testRetainedClearedByEmptyPayload(): void
    {
        $this->requireBroker();
        $t = $this->topic('clearme');
        $pub = new Mqtt(url: self::BROKER);
        $pub->publish($t, 'value', 1, true);
        $pub->publish($t, '', 1, true);   // documented reset
        $late = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-cleared');
        $late->subscribe($t, 1);
        $this->expectException(Throwable::class);   // nothing retained -> times out
        try {
            $late->receive(1);
        } finally {
            $pub->disconnect();
            $late->disconnect();
        }
    }

    public function testPingRoundTrip(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER);
        $this->assertTrue($m->ping(5));
        $m->disconnect();
    }

    public function testConsumeGeneratorAcksAfterBody(): void
    {
        $this->requireBroker();
        $t = $this->topic('consume');
        $sub = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-consume');
        $sub->subscribe($t, 1);
        $pub = new Mqtt(url: self::BROKER);
        for ($i = 0; $i < 3; $i++) {
            $pub->publish($t, "msg-{$i}", 1);
        }
        $seen = [];
        foreach ($sub->consume(qos: 1, iterations: 3, timeout: 5) as $msg) {
            $seen[] = $msg->text();
        }
        $this->assertSame(['msg-0', 'msg-1', 'msg-2'], $seen);
        $sub->disconnect();
        $pub->disconnect();
    }

    public function testPublishAndSubscribeOnOneConnectionDoesNotMistakePublishForAck(): void
    {
        // The ack-inbox correction: an inbound PUBLISH must not be mistaken for a
        // PUBACK when the same connection both publishes and subscribes.
        $this->requireBroker();
        $t = $this->topic('selfpub');
        $c = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-selfpub');
        $c->subscribe($t, 1);
        // publish to our own subscription at QoS 1: the broker may push the
        // PUBLISH before our PUBACK -- waitForAcknowledgement must loop past it.
        $pid = $c->publish($t, 'roundtrip', 1);
        $this->assertIsInt($pid);
        $msg = $c->receive(5);
        $this->assertSame('roundtrip', $msg->text());
        $c->disconnect();
    }

    public function testLastWillFiresOnUngracefulClose(): void
    {
        $this->requireBroker();
        $willTopic = $this->topic('will');
        $watcher = new Mqtt(url: self::BROKER, clientId: 'tina4-phpunit-watch');
        $watcher->subscribe($willTopic, 1);
        $dying = new Mqtt(
            url: self::BROKER,
            clientId: 'tina4-phpunit-dying',
            keepalive: 1,
            willTopic: $willTopic,
            willPayload: 'device-died',
            willQos: 1
        );
        $dying->kill();   // drop the socket WITHOUT a DISCONNECT
        $msg = $watcher->receive(8);   // broker fires the will past 1.5x keepalive
        $this->assertSame('device-died', $msg->payload);
        $watcher->disconnect();
    }

    public function testWriteAfterDisconnectRaises(): void
    {
        $this->requireBroker();
        $m = new Mqtt(url: self::BROKER);
        $m->disconnect();
        $this->expectException(MqttError::class);
        $m->publish('t', 'x', 0);
    }
}
