<?php

namespace Tina4\Tests;

use Fiber;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class PoolFiberTransactionIsolationTest extends TestCase
{
    public function testPoolTransactionLeasePreventsCrossContextDirtyReadsAndLostWrites(): void
    {
        $url = getenv('TINA4_TEST_PG_URL');
        if (!$url) {
            $this->markTestSkipped('[needs:postgres] TINA4_TEST_PG_URL not set');
        }
        $db = Database::create($url, pool: 2);
        $witness = Database::create($url);
        $table = 'fiber_pool_' . bin2hex(random_bytes(6));
        $witness->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        try {
            $owner = new Fiber(function () use ($db, $table): void {
                $db->startTransaction();
                $db->execute("INSERT INTO {$table} VALUES (1)");
                Fiber::suspend();
                $db->rollback();
            });
            $owner->start();
            $rows = [];
            $other = new Fiber(function () use ($db, $table, &$rows): void {
                $rows = $db->fetch("SELECT id FROM {$table}")->toArray();
                $db->execute("INSERT INTO {$table} VALUES (2)");
            });
            $other->start();
            $owner->resume();
            $this->assertSame([], $rows, 'Another Fiber read uncommitted rows');
            $this->assertSame([['id' => 2]], $witness->fetch("SELECT id FROM {$table}")->toArray());
        } finally {
            if (isset($owner) && $owner->isSuspended()) {
                $owner->resume();
            }
            $witness->execute("DROP TABLE IF EXISTS {$table}");
            $db->close();
            $witness->close();
        }
    }
    private function liveUrl(): string
    {
        $url = getenv('TINA4_TEST_PG_URL');
        if (!$url) { $this->markTestSkipped('[needs:postgres] TINA4_TEST_PG_URL not set'); }
        return $url;
    }

    public function testPoolExhaustionFailsWithoutSharingALiveTransaction(): void
    {
        $db = Database::create($this->liveUrl(), pool: 1);
        try {
            $db->startTransaction();
            (new Fiber(function () use ($db): void {
                foreach ([fn() => $db->fetchOne('SELECT 1'), fn() => $db->getAdapter()] as $action) {
                    try { $action(); $this->fail('Leased transaction was exposed'); }
                    catch (\RuntimeException $e) { $this->assertStringContainsString('pool exhausted', $e->getMessage()); }
                }
            }))->start();
            $db->rollback();
            $borrowed = $db->checkout();
            (new Fiber(function () use ($db, $borrowed): void {
                try { $db->checkin($borrowed); $this->fail('Foreign lease was released'); }
                catch (\LogicException $e) { $this->assertStringContainsString('not leased', $e->getMessage()); }
            }))->start();
            $db->checkin($borrowed);
            $this->assertSame(7, $db->fetchOne('SELECT 7 AS value')['value']);
        } finally { $db->close(); }
    }

    public function testPoolFailedCommitRetainsLeaseUntilRollback(): void
    {
        $db = Database::create($this->liveUrl(), pool: 1);
        $table = 'fiber_commit_' . bin2hex(random_bytes(6));
        try {
            $db->execute("CREATE TABLE {$table} (id INTEGER UNIQUE DEFERRABLE INITIALLY DEFERRED)");
            $db->startTransaction();
            $db->execute("INSERT INTO {$table} VALUES (1), (1)");
            try { $db->commit(); $this->fail('Deferred unique constraint must reject commit'); }
            catch (\Tina4\Database\DatabaseException $e) { $this->assertStringContainsString('duplicate', $e->getMessage()); }
            (new Fiber(function () use ($db): void {
                try { $db->checkout(); $this->fail('Failed commit released the transaction'); }
                catch (\RuntimeException $e) { $this->assertStringContainsString('pool exhausted', $e->getMessage()); }
            }))->start();
            $db->rollback();
            $this->assertSame([], $db->fetch("SELECT id FROM {$table}")->toArray());
            try { $db->execute("INSERT INTO {$table} (absent) VALUES (2)"); $this->fail('Missing column must fail'); }
            catch (\Tina4\Database\DatabaseException $e) { $this->assertStringContainsString('absent', $e->getMessage()); }
            $this->assertSame(8, $db->fetchOne('SELECT 8 AS value')['value']);
        } finally {
            $db->rollback();
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->close();
        }
    }

    public function testBrokenBeginAndRollbackConnectionsAreDiscarded(): void
    {
        foreach ([false, true] as $started) {
            $db = Database::create($this->liveUrl(), pool: 1);
            $witness = Database::create($this->liveUrl());
            try {
                if ($started) { $db->startTransaction(); }
                $pid = $db->fetchOne('SELECT pg_backend_pid() AS pid')['pid'];
                $this->assertTrue($witness->fetchOne('SELECT pg_terminate_backend(?) AS terminated', [$pid])['terminated']);
                try {
                    if ($started) { $db->rollback(); } else { $db->startTransaction(); }
                    $this->fail('Terminated connection must fail');
                } catch (\Tina4\Database\DatabaseException $e) { $this->assertNotSame('', $e->getMessage()); }
                $this->assertSame(9, $db->fetchOne('SELECT 9 AS value')['value']);
            } finally { $db->close(); $witness->close(); }
        }
    }

}
