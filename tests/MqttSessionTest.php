<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MQTT sessions and resilience -- real broker, no mocks.
 *
 * Mirrors tina4_python tests/test_mqtt_session.py and tina4-ruby
 * spec/mqtt_session_spec.rb. Drives a real Mosquitto on 1883: the offline replay
 * a durable session provides, and the awkward ordering (queued QoS 1 messages
 * arriving BEFORE the SUBACK on a cleanSession=false resume) that is exactly why
 * the ack-wait inbox has to cover SUBSCRIBE and not just PUBLISH.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Mqtt;

class MqttSessionTest extends TestCase
{
    private const BROKER = 'mqtt://127.0.0.1:1883';

    private function requireBroker(): void
    {
        $s = @fsockopen('127.0.0.1', 1883, $e, $s2, 1);
        if (!$s) {
            $this->markTestSkipped('no MQTT broker on 127.0.0.1:1883');
        }
        fclose($s);
    }

    public function testReplaysQos1MessagesPublishedWhileOfflineInOrder(): void
    {
        $this->requireBroker();
        $run = bin2hex(random_bytes(4));
        $topic = "tina4/phpunit/session/{$run}/offline";
        $durableId = "tina4-phpunit-durable-{$run}";

        // Establish the durable subscription, then go offline.
        $first = new Mqtt(url: self::BROKER, clientId: $durableId, cleanSession: false);
        $first->subscribe($topic, 1);
        $first->disconnect();

        // Publish while the durable subscriber is offline.
        $pub = new Mqtt(url: self::BROKER, clientId: "tina4-phpunit-pub-{$run}");
        for ($i = 0; $i < 3; $i++) {
            $pub->publish($topic, "{\"n\":{$i}}", 1);
        }
        $pub->disconnect();

        // Resume the SAME client id, cleanSession=false -- the broker hands over
        // what it queued for us while we were gone.
        $resumed = new Mqtt(url: self::BROKER, clientId: $durableId, cleanSession: false);
        $replayed = [];
        for ($i = 0; $i < 3; $i++) {
            $replayed[] = $resumed->receive(5)->text();
        }
        $this->assertSame(['{"n":0}', '{"n":1}', '{"n":2}'], $replayed);
        $resumed->disconnect();
    }

    public function testReplayedPublishesStayOutOfTheSubackWait(): void
    {
        // On a cleanSession=false resume the broker starts replaying queued QoS 1
        // messages the moment it sends anything -- a replayed PUBLISH can arrive
        // BEFORE the SUBACK of a fresh subscribe. The ack-wait inbox must park it,
        // not mistake it for the SUBACK, or the durable replay is lost.
        $this->requireBroker();
        $run = bin2hex(random_bytes(4));
        $topic = "tina4/phpunit/session/{$run}/replay-suback";
        $durableId = "tina4-phpunit-replay-{$run}";

        $first = new Mqtt(url: self::BROKER, clientId: $durableId, cleanSession: false);
        $first->subscribe($topic, 1);
        $first->disconnect();

        $pub = new Mqtt(url: self::BROKER, clientId: "tina4-phpunit-replay-pub-{$run}");
        for ($i = 0; $i < 3; $i++) {
            $pub->publish($topic, "queued-{$i}", 1);
        }
        $pub->disconnect();

        // Resume and immediately issue ANOTHER subscribe: its SUBACK now competes
        // with the replayed PUBLISHes already streaming in.
        $resumed = new Mqtt(url: self::BROKER, clientId: $durableId, cleanSession: false);
        $this->assertSame(1, $resumed->subscribe($topic, 1));   // must not choke on an interleaved PUBLISH
        $replayed = [];
        for ($i = 0; $i < 3; $i++) {
            $replayed[] = $resumed->receive(5)->text();
        }
        $this->assertSame(['queued-0', 'queued-1', 'queued-2'], $replayed);
        $resumed->disconnect();
    }

    public function testCleanSessionTrueDoesNotReplay(): void
    {
        // The flag is real: a default cleanSession=true must NOT look like a
        // durable session, or an app would silently lose data it thought queued.
        $this->requireBroker();
        $run = bin2hex(random_bytes(4));
        $topic = "tina4/phpunit/session/{$run}/clean";
        $cleanId = "tina4-phpunit-clean-{$run}";

        $first = new Mqtt(url: self::BROKER, clientId: $cleanId, cleanSession: true);
        $first->subscribe($topic, 1);
        $first->disconnect();   // cleanSession=true -> broker throws the subscription away

        $pub = new Mqtt(url: self::BROKER, clientId: "tina4-phpunit-clean-pub-{$run}");
        $pub->publish($topic, 'lost', 1);
        $pub->disconnect();

        $resumed = new Mqtt(url: self::BROKER, clientId: $cleanId, cleanSession: true);
        $resumed->subscribe($topic, 1);
        $this->expectException(Throwable::class);   // nothing was queued -> times out
        try {
            $resumed->receive(1);
        } finally {
            $resumed->disconnect();
        }
    }
}
