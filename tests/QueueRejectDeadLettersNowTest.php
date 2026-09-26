<?php

/**
 * Tina4 — Queue::reject() dead-letters IMMEDIATELY, no retry (ADR-0023).
 *
 * Bug 4 (book review). Job::reject() was a literal alias for fail()
 * (`$this->fail($reason)`). ADR-0023 (Accepted) redefines reject: it is the
 * "this message is poison, do NOT retry it" path — the job goes straight to
 * the dead-letter store on this call, without burning the retry budget.
 *
 * Pins BOTH sides on the same maxRetries=3 file-backed queue:
 *   - reject() -> dead-lettered NOW (1 delivery), never re-queued
 *   - fail()   -> re-queued, still pending, NOT dead-lettered (control)
 *
 * File backend, real filesystem (no mock). Needs no external service.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

final class QueueRejectDeadLettersNowTest extends TestCase
{
    private string $path = '';
    private ?Queue $queue = null;

    protected function setUp(): void
    {
        // TINA4_QUEUE_BACKEND overrides the constructor arg — clear it so this
        // always runs on the file backend.
        putenv('TINA4_QUEUE_BACKEND');
        $this->path = sys_get_temp_dir() . '/tina4_reject_' . bin2hex(random_bytes(6));
        $this->queue = new Queue('file', ['path' => $this->path, 'maxRetries' => 3], 'reject_' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->path)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->path);
        }
    }

    public function testRejectDeadLettersImmediately(): void
    {
        $this->queue->push(['task' => 'poison']);
        $job = $this->queue->pop();
        self::assertNotNull($job);

        $job->reject('payload will never parse');

        $dead = $this->queue->deadLetters();
        self::assertCount(1, $dead, 'reject() must dead-letter on this call, not after maxRetries');
        self::assertSame(1, $this->queue->size('dead'), "size('dead') must count the rejected job");
        self::assertSame(0, $this->queue->size('pending'), 'a rejected job must NOT be re-queued');
    }

    public function testFailStillRetriesNotDeadLetters(): void
    {
        // Control: fail() with retries left re-queues; it does NOT dead-letter.
        // If reject were still an alias for fail this and the positive test
        // could not both pass.
        $this->queue->push(['task' => 'transient']);
        $job = $this->queue->pop();
        self::assertNotNull($job);

        $job->fail('temporary blip');

        self::assertSame(0, $this->queue->size('dead'), 'fail() under maxRetries must NOT dead-letter');
        self::assertSame(1, $this->queue->size('pending'), 'fail() under maxRetries must re-queue as pending');
        self::assertCount(0, $this->queue->deadLetters());
    }

    public function testRejectedJobNotRedelivered(): void
    {
        $this->queue->push(['task' => 'poison']);
        $job = $this->queue->pop();
        $job->reject('nope');
        self::assertNull($this->queue->pop(), 'a rejected job must never be redelivered to pop()');
    }
}
