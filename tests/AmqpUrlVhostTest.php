<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * AMQP URL vhost contract (RabbitMQ URI spec).
 *
 * THE VHOST IS THE PATH SEGMENT, URL-DECODED, WITH NO LEADING SLASH.
 *
 * REGRESSION. All four frameworks used to prepend '/', so
 * amqp://guest:guest@rabbit:5672/orders asked the broker for a virtual host
 * literally named "/orders". No broker has that one - it is named "orders" -
 * so every publish failed against a named vhost, which is the ordinary
 * multi-tenant setup and the form every RabbitMQ tutorial shows.
 *
 * Nothing caught it because the only URL shape that worked was the one
 * carrying NO vhost, which is what every test and every dev box used - and
 * because the live-integration tests reimplemented the parser, bug included,
 * so they agreed with the framework instead of checking it.
 *
 * Pure parsing of a string: no broker, no socket, no double.
 */
final class AmqpUrlVhostTest extends TestCase
{
    private function vhost(string $url): ?string
    {
        return \Tina4\Queue::parseAmqpUrl($url)['vhost'] ?? null;
    }

    public function testVhostIsThePathSegmentNotSlashPrefixed(): void
    {
        // POSITIVE: the name the broker actually has.
        $this->assertSame('orders', $this->vhost('amqp://guest:guest@rabbit:5672/orders'));
        // NEGATIVE: and specifically NOT the old slash-prefixed name.
        $this->assertNotSame('/orders', $this->vhost('amqp://guest:guest@rabbit:5672/orders'));
    }

    public function testVhostIsPercentDecoded(): void
    {
        // The DEFAULT vhost is named "/", which cannot appear literally in a
        // path, so the spec spells it "%2f". Undecoded it asks for "%2f".
        $this->assertSame('/', $this->vhost('amqp://rabbit:5672/%2f'));
        $this->assertSame('a/b', $this->vhost('amqp://rabbit:5672/a%2Fb'));
        // '+' is NOT a space here: this is a path, not a form body.
        $this->assertSame('a+b', $this->vhost('amqp://rabbit:5672/a+b'));
    }

    public function testNoVhostGivenLeavesTheCallersDefault(): void
    {
        $this->assertNull($this->vhost('amqp://rabbit:5672'));
        // A bare trailing slash is "not specified" too - see the deviation note
        // in Queue.php. Reading it as the empty vhost name would break a
        // working amqp://host:5672/ for no benefit.
        $this->assertNull($this->vhost('amqp://rabbit:5672/'));
    }

    public function testCredentialsAndHostPortStillParse(): void
    {
        $cfg = \Tina4\Queue::parseAmqpUrl('amqps://user:pass@rabbit.example.com:5671/orders');
        $this->assertSame('user', $cfg['username']);
        $this->assertSame('pass', $cfg['password']);
        $this->assertSame('rabbit.example.com', $cfg['host']);
        $this->assertSame(5671, $cfg['port']);
        $this->assertSame('orders', $cfg['vhost']);
    }
}
