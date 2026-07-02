<?php

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Queue;
use Tina4\Job;
use Tina4\ORM;

/**
 * Real (no-mock) integration tests for the Tina4 Store demo's order queue.
 *
 * These exercise the FULL checkout -> queue push -> background worker
 * (processOrders) -> pop -> process -> complete path against a REAL on-disk
 * SQLite database and the REAL file queue backend. Nothing is mocked, stubbed
 * or faked: every assertion is made against the real store and the real queue
 * files. This locks in the queue-consumer contract for the demo.
 */
class StoreQueueTest extends TestCase
{
    private string $tmp;
    private string $dbFile;
    private string $queuePath;
    private Database $db;

    protected function setUp(): void
    {
        $exampleDir = dirname(__DIR__);

        // Load the demo's real service + ORM code once.
        require_once $exampleDir . '/src/app/services/order_service.php';
        require_once $exampleDir . '/src/seeds/seed_store.php';

        // Isolated temp DB + queue path per test — real files, no shared state.
        $this->tmp = sys_get_temp_dir() . '/tina4_store_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
        $this->dbFile = $this->tmp . '/store.db';
        $this->queuePath = $this->tmp . '/queue';

        // Point the framework's default queue path at our temp dir so a plain
        // `new Queue(topic: 'orders')` (exactly how the demo builds it) writes here.
        putenv('TINA4_QUEUE_PATH=' . $this->queuePath);
        $_ENV['TINA4_QUEUE_PATH'] = $this->queuePath;
        // Deterministic: disable the reservation reclaim window in these tests.
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT=0');
        $_ENV['TINA4_QUEUE_VISIBILITY_TIMEOUT'] = '0';

        // Boot the demo's real bootstrap: bind DB, migrate, seed.
        $this->db = Database::create('sqlite://' . $this->dbFile);
        ORM::bindDatabase($this->db);
        \Tina4\App::setDatabase($this->db);

        (new \Tina4\Migration($this->db, $exampleDir . '/migrations'))->migrate();
        seedStore($this->db);

        \Tina4\Events::clear();
    }

    protected function tearDown(): void
    {
        \Tina4\Events::clear();
        $this->removeDir($this->tmp);
        putenv('TINA4_QUEUE_PATH');
        unset($_ENV['TINA4_QUEUE_PATH']);
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        unset($_ENV['TINA4_QUEUE_VISIBILITY_TIMEOUT']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                @unlink($dir);
            }
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $this->removeDir($dir . '/' . $item);
        }
        @rmdir($dir);
    }

    /** Create an order + one order item just like checkout.php does. */
    private function placeOrder(int $customerId, int $productId, int $qty): int
    {
        $product = (new Product())->findById($productId);
        $order = Order::create([
            "customer_id" => $customerId,
            "total" => $product->price * $qty,
            "status" => "pending",
            "created_at" => date('c'),
        ]);
        $this->assertNotFalse($order, "Order::create must return a saved order, not false");
        $this->assertNotEmpty($order->id, "a saved order must have an id");

        $item = OrderItem::create([
            "order_id" => $order->id,
            "product_id" => $productId,
            "quantity" => $qty,
            "unit_price" => $product->price,
        ]);
        $this->assertNotFalse($item, "OrderItem::create must return a saved item, not false");

        return (int) $order->id;
    }

    // ── Bootstrap sanity (real seeded SQLite) ───────────────────

    public function testBootstrapSeedsRealData(): void
    {
        $this->assertSame(5, (new Category())->count());
        $this->assertSame(50, (new Product())->count());
        $this->assertGreaterThanOrEqual(11, (new Customer())->count());
    }

    // ── pop() job shape contract ────────────────────────────────

    public function testPoppedJobPayloadIsNested(): void
    {
        // Locks in the framework contract the demo worker depends on: the pushed
        // payload lives under $job['payload'], NOT at the top level.
        $q = new Queue(topic: "orders");
        $q->push(["order_id" => 123, "customer_id" => 2]);
        $job = $q->pop();

        $this->assertIsArray($job);
        $this->assertArrayNotHasKey("order_id", $job);
        $this->assertSame(123, $job["payload"]["order_id"]);
    }

    // ── Full checkout -> queue -> worker happy path ─────────────

    public function testCheckoutQueueWorkerProcessesOrder(): void
    {
        // Deterministic stock so we can assert the decrement.
        $this->db->execute("UPDATE products SET stock = 10 WHERE id = 1");
        $this->db->commit();

        $orderId = $this->placeOrder(customerId: 2, productId: 1, qty: 2);

        // Push to the queue exactly as checkout.php does.
        $checkoutQueue = new Queue(topic: "orders");
        $jobId = $checkoutQueue->push(["order_id" => $orderId, "customer_id" => 2]);
        $this->assertNotEmpty($jobId);
        $this->assertSame(1, $checkoutQueue->size("pending"));

        $emitted = [];
        \Tina4\Events::on("order.processing", function ($d) use (&$emitted) {
            $emitted[] = $d["order_id"];
        });

        // Run the worker exactly as $app->background(fn) => processOrders($queue).
        $workerQueue = new Queue(topic: "orders");
        processOrders($workerQueue);

        $order = (new Order())->findById($orderId);
        $stock = (int) (new Product())->findById(1)->stock;

        $this->assertSame("processing", $order->status, "worker must move the order to 'processing'");
        $this->assertSame(8, $stock, "worker must decrement stock 10 -> 8");
        $this->assertContains($orderId, $emitted, "order.processing event must fire");
        // The job was acknowledged (completed) — the topic is drained.
        $this->assertSame(0, $workerQueue->size("pending"));
    }

    public function testWorkerFailsOrderWhenOutOfStock(): void
    {
        // Only 1 in stock but the order wants 5 -> insufficient.
        $this->db->execute("UPDATE products SET stock = 1 WHERE id = 2");
        $this->db->commit();

        $orderId = $this->placeOrder(customerId: 2, productId: 2, qty: 5);

        $q = new Queue(topic: "orders");
        $q->push(["order_id" => $orderId, "customer_id" => 2]);

        $failed = [];
        \Tina4\Events::on("order.failed", function ($d) use (&$failed) {
            $failed[] = $d["order_id"];
        });

        processOrders(new Queue(topic: "orders"));

        $order = (new Order())->findById($orderId);
        $stock = (int) (new Product())->findById(2)->stock;

        $this->assertSame("failed", $order->status, "insufficient stock must fail the order");
        $this->assertSame(1, $stock, "stock must NOT be decremented on a failed order");
        $this->assertContains($orderId, $failed, "order.failed event must fire");
    }

    public function testWorkerNoopOnEmptyQueue(): void
    {
        // No jobs pushed — processOrders must be a clean no-op.
        processOrders(new Queue(topic: "orders"));
        $this->assertSame(0, (new Queue(topic: "orders"))->size("pending"));
    }

    // ── fail() auto-retry + dead-letter contract (behaviour change) ──
    //
    // The queue's fail() AUTO-RETRIES: a failed job with retries remaining is
    // re-queued to pending (size stays 1) and only dead-letters once maxRetries
    // is exhausted. These lock in BOTH sides of that contract for the demo's
    // 'orders' topic against the real file backend.

    public function testFailRequeuesOrderWhileRetriesRemain(): void
    {
        $q = new Queue('file', [
            'path' => $this->queuePath,
            'maxRetries' => 3,
            'visibilityTimeout' => 0,
        ], topic: 'orders');
        $q->push(["order_id" => 4242, "customer_id" => 2]);

        // Consume once and fail — under the retry limit this re-enqueues.
        foreach ($q->consume('orders', null, 0, 1) as $job) {
            $job->fail('transient downstream error');
        }

        // Not dead yet; still pending with attempts incremented + error carried.
        $this->assertEmpty($q->deadLetters());
        $this->assertSame(1, $q->size('pending'));
        $requeued = $q->pop();
        $this->assertSame(1, $requeued['attempts']);
        $this->assertSame('transient downstream error', $requeued['error']);
        $this->assertSame(4242, $requeued['payload']['order_id']);
    }

    public function testFailDeadLettersOrderAfterRetriesExhausted(): void
    {
        $q = new Queue('file', [
            'path' => $this->queuePath,
            'maxRetries' => 3,
            'visibilityTimeout' => 0,
        ], topic: 'orders');
        $q->push(["order_id" => 5150, "customer_id" => 2]);

        // Fail on every attempt via a plain consume loop; auto-retry drives it to
        // the dead-letter store after exactly maxRetries attempts — no manual
        // retryFailed().
        $attempts = 0;
        foreach ($q->consume('orders', null, 0) as $job) {
            $attempts++;
            $job->fail("permanent failure {$attempts}");
        }

        $this->assertSame(3, $attempts, "job must be attempted exactly maxRetries times");
        $this->assertSame(0, $q->size('pending'));

        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertSame(3, $dead[0]['attempts']);
        $this->assertSame(5150, $dead[0]['payload']['order_id']);
        $this->assertSame('permanent failure 3', $dead[0]['error']);
    }
}
