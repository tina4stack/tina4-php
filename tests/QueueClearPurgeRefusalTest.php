<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * clear()/purge() on a broker backend refuse by name (ADR-0022, invariant 6).
 *
 * clear() and purge($status) are STATUS-ADDRESSED: they remove jobs selected by
 * status. Neither RabbitMQ nor Kafka can address messages by status - RabbitMQ's
 * basic.get pops the head of the queue and its only bulk operation drains the
 * WHOLE queue; a Kafka log is read in offset order and leaves only by retention.
 * So both backends RAISE \RuntimeException naming the backend AND the operation,
 * exactly as they already do for push(priority), retryFailed() and popById().
 *
 * ADR-0022: "a broker that cannot address messages by status refuses the
 * operation by name." It may never silently no-op (claiming the queue was
 * emptied when it was not) nor destructively drain the live queue (data loss).
 *
 * NO MOCKS and NO BROKER. The refusal is a guard that fires BEFORE any socket is
 * opened (the constructor resolves config only; clear()/purge() throw before
 * ensureConnected()), so constructing the real backend and calling the real
 * method is a complete, local, red-first test. The file backend is the negative
 * control: it CAN address by status, so it must still answer for real rather
 * than join a blanket refusal.
 */
final class QueueClearPurgeRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        // A unique file-store path so the negative control never collides with a
        // previous run or another test.
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qclr_' . bin2hex(random_bytes(5)));
    }

    /**
     * POSITIVE: clear() raises naming the backend and the operation. Before this
     * release Kafka's clear() returned 0 (silent no-op) and RabbitMQ's drained
     * the live queue; both are the failure mode invariant 6 exists to forbid.
     */
    public function testClearOnABrokerBackendRefusesByName(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            $queue = new \Tina4\Queue($backend, [], 'clr_' . bin2hex(random_bytes(6)));

            $message = null;
            try {
                $queue->clear();
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            // Asserted OUTSIDE the catch on purpose. PHPUnit's AssertionFailedError
            // extends \RuntimeException, so a $this->fail()/assert INSIDE the try
            // would be swallowed by the catch above - the classic ghost that stays
            // green even when clear() did NOT refuse. Capturing the message and
            // asserting out here makes this a real gate: a non-refusing clear()
            // leaves $message null and assertNotNull fails.
            $this->assertNotNull($message, "the {$backend} backend must refuse clear() by raising, not silently no-op or drain the queue");
            $this->assertStringContainsString($backend, (string)$message, 'the refusal must name the backend');
            $this->assertStringContainsString('clear', strtolower((string)$message), 'the refusal must name the operation');
        }
    }

    /**
     * POSITIVE: purge($status) raises naming the backend and the operation.
     */
    public function testPurgeOnABrokerBackendRefusesByName(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            $queue = new \Tina4\Queue($backend, [], 'prg_' . bin2hex(random_bytes(6)));

            $message = null;
            try {
                $queue->purge('completed');
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            // Asserted OUTSIDE the catch - see the note in the clear() case: a
            // PHPUnit failure inside the try would be caught as a \RuntimeException
            // and hide a non-refusing purge().
            $this->assertNotNull($message, "the {$backend} backend must refuse purge() by raising, not silently no-op or drain the queue");
            $this->assertStringContainsString($backend, (string)$message, 'the refusal must name the backend');
            $this->assertStringContainsString('purge', strtolower((string)$message), 'the refusal must name the operation');
        }
    }

    /**
     * NEGATIVE control: a backend that CAN address by status must answer, not
     * refuse. Without this, making every clear()/purge() raise would pass the
     * two cases above while breaking the whole queue. The file backend returns a
     * real int and never raises.
     */
    public function testTheFileBackendStillClearsAndPurgesForReal(): void
    {
        $clearQueue = new \Tina4\Queue('file', [], 'file_' . bin2hex(random_bytes(6)));
        $clearQueue->push(['m' => 'keep']);
        $this->assertIsInt($clearQueue->clear(), 'the file backend must clear for real');

        $purgeQueue = new \Tina4\Queue('file', [], 'file_' . bin2hex(random_bytes(6)));
        $this->assertIsInt($purgeQueue->purge('completed'), 'the file backend must purge for real');
    }
}
