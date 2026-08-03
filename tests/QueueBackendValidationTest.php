<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: an-unsupported-operation-raises-naming-itself
 *
 * A typo in TINA4_QUEUE_BACKEND must not produce a running app writing every
 * job to local disk.
 *
 * MEASURED 2026-08-03: PHP and Node accepted ANY string as a backend name and
 * silently fell through to the local file store. Python and Ruby already raised,
 * naming the valid set - so this was a two-of-four divergence on a rule the
 * SESSION backend had already adopted for exactly the same reason.
 *
 * The failure mode is the one that costs most: nothing errors, the app looks
 * healthy, and every job goes to a container filesystem that nothing consumes
 * and that vanishes on the next deploy.
 *
 * These two case names are shared verbatim with the Python, Ruby and Node
 * suites, because scripts/audit-contract-fixtures.py resolves ONE fixture case
 * against EVERY framework's file.
 */
final class QueueBackendValidationTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TINA4_QUEUE_BACKEND', 'TINA4_QUEUE_PATH'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qbv_' . bin2hex(random_bytes(5)));
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }

    /**
     * An unknown queue backend raises instead of silently using file.
     */
    public function testAnUnknownQueueBackendRaisesInsteadOfSilentlyUsingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown queue backend/');

        new \Tina4\Queue('totally-bogus-backend', [], 'validation');
    }

    /**
     * The message must name the offending value AND the valid set, so the
     * operator can fix it without reading the source.
     */
    public function testTheUnknownBackendErrorNamesTheValueAndTheValidSet(): void
    {
        try {
            new \Tina4\Queue('rabbitmqq', [], 'validation');
            $this->fail('a bogus backend name must raise');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('rabbitmqq', $e->getMessage(), 'the bad value must be named');
            foreach (['file', 'rabbitmq', 'kafka', 'mongodb'] as $valid) {
                $this->assertStringContainsString($valid, $e->getMessage(), "the valid set must name '{$valid}'");
            }
        }
    }

    /**
     * A queue backend name is normalised before it is resolved.
     *
     * ' File ' is the same backend as 'file'. Without this, a stray space in a
     * .env turns a valid configuration into the unknown-backend raise above -
     * which would trade a silent bug for a loud one rather than fixing it.
     */
    public function testAQueueBackendNameIsNormalisedBeforeItIsResolved(): void
    {
        foreach ([' file ', 'FILE', 'File', ' lite', 'DEFAULT'] as $spelling) {
            // Constructing must NOT raise: without the trim+lowercase these fall
            // through to the unknown-backend guard above, which would trade a
            // silent bug for a loud one rather than fixing it.
            $queue = new \Tina4\Queue($spelling, [], 'validation');
            $this->assertInstanceOf(\Tina4\Queue::class, $queue, "'{$spelling}' must resolve");
        }
    }

    /**
     * NEGATIVE: the guard must not swallow a genuinely valid external name.
     *
     * Without this pair, "make everything raise" would pass the test above.
     */
    public function testTheGuardStillAcceptsEveryDocumentedBackendName(): void
    {
        putenv('TINA4_QUEUE_MONGO_URL=mongodb://127.0.0.1:27017');
        foreach (['mongodb', 'mongo', 'MongoDB'] as $spelling) {
            $queue = new \Tina4\Queue($spelling, [], 'validation');
            $this->assertInstanceOf(\Tina4\Queue::class, $queue, "'{$spelling}' must resolve");
        }
    }
}
