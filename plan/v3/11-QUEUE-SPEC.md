# Tina4 v3.0 — Queue System Specification

## Philosophy
The built-in database queue must be **production-grade** — capable of handling up to 1M visitors without needing RabbitMQ or Kafka. RabbitMQ and Kafka are optional extensions that developers install when they outgrow the core or need specific features (pub/sub, stream processing, cross-service messaging).

## Architecture

```
Queue System
├── Core (zero-dep, ships with framework)
│   └── DatabaseQueue — uses the connected database as queue storage
│
└── Extensions (optional, developer installs when needed)
    ├── RabbitMQQueue — AMQP adapter (requires client library)
    └── KafkaQueue — Kafka adapter (requires client library)
```

All three backends implement the same `QueueAdapter` interface, so switching from DatabaseQueue to RabbitMQ is a config change, not a code change.

## QueueAdapter Interface

```
interface QueueAdapter:
    push(queue_name, payload, options) → message_id
    get(message_id) → Message | null                   # Retrieve by ID
    find(queue_name, filter, options) → [Message]      # Search messages
    pop(queue_name, options) → Message | null
    complete(message) → void
    fail(message, reason) → void
    retry(message) → void
    requeue(message, target_queue) → void          # Move to different queue
    size(queue_name) → int
    purge(queue_name) → void
    dead_letter(queue_name) → [Message]
    subscribe(queue_name, handler) → void          # Long-poll / blocking pop
    set_fallback(queue_name, fallback_queue) → void # Failover chain
```

## Message Object

```json
{
  "id": "uuid-v7",
  "queue": "emails",
  "payload": { "to": "user@example.com", "subject": "Welcome" },
  "status": "pending",
  "attempts": 0,
  "max_attempts": 3,
  "original_queue": "emails",
  "created_at": "2026-03-19T10:00:00Z",
  "available_at": "2026-03-19T10:00:00Z",
  "reserved_at": null,
  "completed_at": null,
  "failed_at": null,
  "error": null
}
```

## Database Queue — Core Implementation

### Schema
```sql
CREATE TABLE tina4_queue (
    id TEXT PRIMARY KEY,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    created_at TEXT NOT NULL,
    available_at TEXT NOT NULL,
    reserved_at TEXT,
    completed_at TEXT,
    failed_at TEXT,
    error TEXT,
    priority INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_tina4_queue_pop ON tina4_queue (queue, status, available_at, priority);
CREATE INDEX idx_tina4_queue_status ON tina4_queue (status);
```

### Pop Algorithm (Atomic, No Double-Processing)

**SQLite (single process):**
```sql
-- Atomic pop using UPDATE ... RETURNING (SQLite 3.35+)
UPDATE tina4_queue
SET status = 'reserved', reserved_at = datetime('now')
WHERE id = (
    SELECT id FROM tina4_queue
    WHERE queue = ? AND status = 'pending' AND available_at <= datetime('now')
    ORDER BY priority DESC, created_at ASC
    LIMIT 1
)
RETURNING *;
```

**PostgreSQL (concurrent workers):**
```sql
-- Atomic pop with row locking using FOR UPDATE SKIP LOCKED
UPDATE tina4_queue
SET status = 'reserved', reserved_at = now()
WHERE id = (
    SELECT id FROM tina4_queue
    WHERE queue = $1 AND status = 'pending' AND available_at <= now()
    ORDER BY priority DESC, created_at ASC
    FOR UPDATE SKIP LOCKED
    LIMIT 1
)
RETURNING *;
```

**MySQL:**
```sql
-- Two-step with transaction (no RETURNING)
START TRANSACTION;
SELECT id INTO @msg_id FROM tina4_queue
WHERE queue = ? AND status = 'pending' AND available_at <= NOW()
ORDER BY priority DESC, created_at ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;

UPDATE tina4_queue SET status = 'reserved', reserved_at = NOW() WHERE id = @msg_id;
SELECT * FROM tina4_queue WHERE id = @msg_id;
COMMIT;
```

**MSSQL:**
```sql
UPDATE TOP(1) tina4_queue WITH (READPAST)
SET status = 'reserved', reserved_at = GETUTCDATE()
OUTPUT inserted.*
WHERE queue = @queue AND status = 'pending' AND available_at <= GETUTCDATE()
ORDER BY priority DESC, created_at ASC;
```

**Firebird:**
```sql
UPDATE tina4_queue
SET status = 'reserved', reserved_at = CURRENT_TIMESTAMP
WHERE id = (
    SELECT FIRST 1 id FROM tina4_queue
    WHERE queue = ? AND status = 'pending' AND available_at <= CURRENT_TIMESTAMP
    ORDER BY priority DESC, created_at ASC
    WITH LOCK
)
RETURNING id, queue, payload, status, attempts, max_attempts, created_at,
          available_at, reserved_at, completed_at, failed_at, error, priority;
```

### Features

#### Priority Queues
```python
queue.push("emails", payload, priority=10)   # Higher priority processed first
queue.push("emails", payload, priority=0)    # Default priority
```

#### Delayed Jobs
```python
queue.push("emails", payload, delay=300)     # Available in 5 minutes
queue.push("emails", payload, available_at="2026-03-20T09:00:00Z")  # Specific time
```

#### Retry with Exponential Backoff
```python
# On failure, automatically retries with backoff:
# Attempt 1 fails → retry in 30s
# Attempt 2 fails → retry in 120s
# Attempt 3 fails → moved to dead letter queue
```

Backoff formula: `delay = base_delay * (2 ^ (attempt - 1))`
Default base_delay: 30 seconds

#### Dead Letter Queue
Messages that exceed `max_attempts` are moved to status `dead_letter`. They can be:
- Inspected: `queue.dead_letter("emails")`
- Retried: `queue.retry(message)` — resets attempts, moves back to pending
- Purged: `queue.purge_dead_letter("emails")`

#### Stale Job Recovery
A background sweep (runs every 60s) finds messages stuck in `reserved` status for longer than a timeout (default: 5 minutes) and moves them back to `pending`. This handles worker crashes.

```sql
UPDATE tina4_queue
SET status = 'pending', reserved_at = NULL
WHERE status = 'reserved'
AND reserved_at < datetime('now', '-5 minutes');
```

#### Retrieve by ID
```python
# Get a specific message by its ID (any status)
message = queue.get("uuid-v7-here")
print(message.status)      # "pending" | "reserved" | "completed" | "failed" | "dead_letter"
print(message.attempts)    # How many times it's been tried
print(message.error)       # Last error if failed

# Search messages by filter
pending = queue.find("emails", status="pending")
failed = queue.find("emails", status="failed", limit=50)
recent = queue.find("emails", created_after="2026-03-19T00:00:00Z")
```

This is essential for:
- Tracking job status from an API (`GET /api/jobs/{id}`)
- Building queue dashboards
- Debugging failed messages
- Correlating queue messages with business logic (e.g. order ID → queue message ID)

#### Requeue Patterns
Move a message to a different queue for specialized processing:

```python
# Route to a different queue based on content
@queue.worker()
def process_order(message):
    if message.payload["total"] > 10000:
        queue.requeue(message, "high_value_orders")  # Specialized handling
        return
    fulfill_order(message.payload)

# Requeue with modified payload
queue.requeue(message, "retry_queue", payload={**message.payload, "retry_reason": "timeout"})

# Requeue back to same queue with delay (manual retry control)
queue.requeue(message, "emails", delay=60)
```

Requeue preserves `original_queue` so you can always trace where a message started.

#### Failover / Fallback Queues
Define a failover chain — when a queue's worker is down or the queue exceeds a depth threshold, messages automatically spill to the fallback:

```python
# Define failover chain
queue.set_fallback("emails", "emails_fallback")
queue.set_fallback("emails_fallback", "emails_dead_letter")

# Or define the full chain at once
queue.set_fallback_chain("emails", ["emails_fallback", "emails_dead_letter"])
```

**How failover triggers:**

| Trigger | Description | Configurable |
|---------|-------------|-------------|
| Worker down | No pop in last N seconds (default: 300s) | `QUEUE_FAILOVER_TIMEOUT=300` |
| Queue depth exceeded | Queue has >N pending messages (default: 10000) | `QUEUE_FAILOVER_DEPTH=10000` |
| Error rate exceeded | >N% of recent messages failed (default: 50%) | `QUEUE_FAILOVER_ERROR_RATE=50` |

**Failover sweep** (runs alongside stale job recovery):
```sql
-- Check if primary queue is stuck (no pops in 5 minutes)
-- and fallback is configured
-- Move pending messages to fallback queue
UPDATE tina4_queue
SET queue = :fallback_queue
WHERE queue = :primary_queue
AND status = 'pending'
AND :last_pop_time < datetime('now', '-5 minutes');
```

**Multi-backend failover** — when using RabbitMQ or Kafka as primary, automatically fall back to the database queue if the external service is unreachable:

```python
# Config-driven failover
# If RabbitMQ is down, queue operations transparently use DatabaseQueue
queue = Queue("emails", driver="rabbitmq", fallback_driver="database")
```

```env
QUEUE_DRIVER=rabbitmq
QUEUE_FALLBACK_DRIVER=database
RABBITMQ_URL=amqp://user:pass@localhost:5672
```

This means the application never stops processing — it degrades gracefully to the database queue and catches up when the primary comes back online.

#### Circuit Breaker
The queue includes a built-in circuit breaker for external backends (RabbitMQ, Kafka):

```
CLOSED (normal) → error rate > threshold → OPEN (use fallback)
OPEN → after cooldown period → HALF-OPEN (try one message)
HALF-OPEN → success → CLOSED / failure → OPEN
```

Defaults:
- Error threshold: 5 consecutive failures
- Cooldown: 30 seconds
- Configurable via `QUEUE_CIRCUIT_BREAKER_THRESHOLD` and `QUEUE_CIRCUIT_BREAKER_COOLDOWN`

#### Batch Processing
```python
messages = queue.pop_batch("emails", count=10)
for msg in messages:
    process(msg)
    queue.complete(msg)
```

### Worker Pattern

**Python:**
```python
from tina4_python import Queue

queue = Queue("emails")

# Simple loop worker
@queue.worker(concurrency=1, poll_interval=1)
def process_email(message):
    send_email(message.payload["to"], message.payload["subject"])
    # Returning without error = auto-complete
    # Raising exception = auto-fail with retry
```

**PHP:**
```php
use Tina4\Queue;

$queue = new Queue("emails");

$queue->worker(function($message) {
    sendEmail($message->payload["to"], $message->payload["subject"]);
}, concurrency: 1, pollInterval: 1);
```

**Ruby:**
```ruby
queue = Tina4::Queue.new("emails")

queue.worker(concurrency: 1, poll_interval: 1) do |message|
  send_email(message.payload["to"], message.payload["subject"])
end
```

**TypeScript:**
```typescript
import { Queue } from '@tina4/queue';

const queue = new Queue('emails');

queue.worker(async (message) => {
  await sendEmail(message.payload.to, message.payload.subject);
}, { concurrency: 1, pollInterval: 1 });
```

### Performance Targets (Database Queue)

| Metric | Target | Notes |
|--------|--------|-------|
| Push throughput | 5,000 msg/sec | SQLite WAL mode, batched inserts |
| Pop throughput | 2,000 msg/sec | Indexed query, atomic update |
| Concurrent workers | 10+ | FOR UPDATE SKIP LOCKED (PostgreSQL/MySQL) |
| Queue depth | 1M+ messages | Indexed table, periodic cleanup |
| Latency (push→pop) | <10ms | Direct SQL, no serialization overhead |
| Memory overhead | ~0 | All state in database, workers are stateless |

### Cleanup
Completed messages older than a configurable retention period (default: 7 days) are automatically purged:

```sql
DELETE FROM tina4_queue
WHERE status = 'completed'
AND completed_at < datetime('now', '-7 days');
```

---

## RabbitMQ Extension (Optional)

Install when needed:
```
# Python
pip install pika

# PHP
composer require php-amqplib/php-amqplib

# Ruby
gem install bunny

# Node.js
npm install amqplib
```

Configuration:
```env
QUEUE_DRIVER=rabbitmq
RABBITMQ_URL=amqp://user:pass@localhost:5672
```

The RabbitMQ adapter implements the same `QueueAdapter` interface. Code doesn't change:
```python
queue = Queue("emails")   # Automatically uses RabbitMQ when configured
queue.push("emails", payload)
```

---

## Kafka Extension (Optional)

Install when needed:
```
# Python
pip install confluent-kafka

# PHP
composer require confluent/confluent-kafka-php (ext-rdkafka)

# Ruby
gem install rdkafka

# Node.js
npm install kafkajs
```

Configuration:
```env
QUEUE_DRIVER=kafka
KAFKA_BROKERS=localhost:9092
KAFKA_GROUP_ID=tina4-workers
```

---

## Testing Requirements

### Positive Tests
1. `test_push_and_pop` — push message, pop returns it
2. `test_fifo_order` — messages come out in insertion order
3. `test_priority_order` — higher priority messages come first
4. `test_delayed_job` — delayed message not available until delay expires
5. `test_complete` — completed message not re-popped
6. `test_fail_and_retry` — failed message retried with backoff
7. `test_max_attempts_to_dead_letter` — exhausted retries → dead letter
8. `test_dead_letter_retry` — dead letter message can be manually retried
9. `test_batch_pop` — pop_batch returns correct count
10. `test_queue_size` — size() returns accurate count
11. `test_purge` — purge() removes all messages from queue
12. `test_multiple_queues` — messages in different queues are independent
13. `test_worker_auto_complete` — worker auto-completes on success
14. `test_worker_auto_fail` — worker auto-fails on exception
15. `test_concurrent_pop_no_double` — two workers never get same message

### Requeue & Failover Tests
16. `test_requeue_to_different_queue` — message moves to target queue, original_queue preserved
17. `test_requeue_with_modified_payload` — payload updated, message lands in target queue
18. `test_requeue_with_delay` — requeued message not available until delay expires
19. `test_fallback_on_worker_down` — messages spill to fallback after timeout
20. `test_fallback_on_depth_exceeded` — messages spill when queue depth > threshold
21. `test_fallback_on_error_rate` — messages spill when error rate > threshold
22. `test_fallback_chain` — messages flow through multi-step fallback chain
23. `test_multi_backend_failover` — RabbitMQ down → transparent fallback to database queue
24. `test_circuit_breaker_opens` — consecutive failures trip the breaker
25. `test_circuit_breaker_half_open` — after cooldown, tries one message
26. `test_circuit_breaker_closes` — successful half-open attempt restores normal flow
27. `test_requeue_preserves_original_queue` — original_queue field tracks origin through multiple requeues
28. `test_get_by_id` — retrieve message by ID returns correct message with all fields
29. `test_get_by_id_any_status` — get works for pending, reserved, completed, failed, dead_letter
30. `test_find_by_status` — find returns only messages matching status filter
31. `test_find_with_limit` — find respects limit parameter
32. `test_find_by_date_range` — find filters by created_after/created_before

### Negative Tests
1. `test_pop_empty_queue` — pop on empty queue returns null
2. `test_complete_already_completed` — completing twice is safe (idempotent)
3. `test_fail_already_failed` — failing twice is safe
4. `test_invalid_queue_name` — empty or null queue name → error
5. `test_stale_recovery` — reserved but abandoned message returns to pending
6. `test_requeue_nonexistent_message` — requeue a message that doesn't exist → error
7. `test_fallback_to_self` — setting fallback to same queue → error (prevent loop)
8. `test_circular_fallback_chain` — A→B→A → error on set_fallback
9. `test_get_nonexistent_id` — get with unknown ID returns null, no error
