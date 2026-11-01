<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Kafka consumer offset-reset override (tina4-php#263) and the raw producer
 * refusal on non-Kafka backends (tina4-php#264). These are pure/offline — no
 * broker needed, so they run in every CI job. The live raw round-trip that
 * proves a raw produce carries no envelope is in KafkaIntegrationTest
 * (testRawPushEmitsUnenvelopedValue), gated on a real broker.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;
use Tina4\Queue\KafkaBackend;

class KafkaOffsetAndRawTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['TINA4_KAFKA_AUTO_OFFSET_RESET', 'KAFKA_AUTO_OFFSET_RESET'] as $k) {
            $this->saved[$k] = getenv($k);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
    }

    // ── #263: auto.offset.reset resolution ──────────────────────────────────

    public function testOffsetResetDefaultsToEarliest(): void
    {
        $this->assertSame('earliest', KafkaBackend::offsetReset());
    }

    public function testOffsetResetHonoursTina4Env(): void
    {
        putenv('TINA4_KAFKA_AUTO_OFFSET_RESET=latest');
        $this->assertSame('latest', KafkaBackend::offsetReset());
    }

    public function testOffsetResetFallsBackToBareKafkaEnv(): void
    {
        putenv('KAFKA_AUTO_OFFSET_RESET=latest');
        $this->assertSame('latest', KafkaBackend::offsetReset());
    }

    public function testTina4EnvWinsOverBareKafkaEnv(): void
    {
        putenv('TINA4_KAFKA_AUTO_OFFSET_RESET=latest');
        putenv('KAFKA_AUTO_OFFSET_RESET=earliest');
        $this->assertSame('latest', KafkaBackend::offsetReset());
    }

    public function testBlankOffsetResetFallsThroughToDefault(): void
    {
        putenv('TINA4_KAFKA_AUTO_OFFSET_RESET=');
        $this->assertSame('earliest', KafkaBackend::offsetReset());
    }

    // ── #264: raw is Kafka-only — other backends refuse by name ─────────────

    public function testRawPushOnFileBackendIsRefusedByName(): void
    {
        $queue = new Queue('file', [], 'tina4_raw_refusal_' . bin2hex(random_bytes(3)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('file queue backend cannot honour push(raw=true)');
        $queue->push(['event' => 'x'], 0, 0, true);
    }

    public function testPushWithoutRawOnFileBackendIsUnchanged(): void
    {
        $queue = new Queue('file', [], 'tina4_raw_default_' . bin2hex(random_bytes(3)));
        $id = $queue->push(['event' => 'x']);
        $this->assertNotEmpty($id);
        $job = $queue->pop();
        $this->assertNotNull($job);
        $body = $job['payload'] ?? $job;
        $this->assertSame('x', $body['event'] ?? null);
        $job->complete();
    }
}
