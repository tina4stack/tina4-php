# Queue

The `Queue` class provides a file-backed job queue for processing tasks asynchronously. It supports delayed jobs, retries, failed job tracking, and a simple process loop. The file backend stores each job as a JSON file, making it zero-dependency and easy to inspect.

## Basic Push and Pop

```php
use Tina4\Queue;

$queue = new Queue();

// Push a job
$jobId = $queue->push('emails', [
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
    'template' => 'welcome',
]);
echo "Job ID: {$jobId}\n";

// Pop the next available job
$job = $queue->pop('emails');
if ($job !== null) {
    echo "Processing: " . $job['payload']['subject'] . "\n";
    echo "Job ID: " . $job['id'] . "\n";
}
```

## Delayed Jobs

Jobs can be delayed so they become available after a specified number of seconds.

```php
// Available after 5 minutes
$queue->push('reminders', [
    'user_id' => 42,
    'message' => 'Your trial expires tomorrow',
], delay: 300);

// Available after 1 hour
$queue->push('reports', [
    'type' => 'daily',
    'date' => date('Y-m-d'),
], delay: 3600);
```

## Processing Jobs

The `process()` method pops and handles jobs in a loop using a callback function.

```php
$queue->process('emails', function (array $job) {
    $payload = $job['payload'];

    // Send the email
    echo "Sending email to {$payload['to']}\n";

    // If the handler throws an exception, the job is moved to the failed queue
}, options: ['maxJobs' => 10]); // Process up to 10 jobs
```

### Continuous Worker

```php
// Process all available jobs (no limit)
$queue->process('emails', function (array $job) {
    // Handle job
    echo "Processing job: {$job['id']}\n";
});
```

## Queue Size

```php
$pending = $queue->size('emails');
echo "{$pending} emails waiting to be sent\n";
```

## Failed Jobs

Jobs that throw an exception during processing are moved to a `failed/` subdirectory.

```php
// Get all failed jobs
$failed = $queue->failed('emails');

foreach ($failed as $job) {
    echo "Failed: {$job['id']}\n";
    echo "Error: {$job['error']}\n";
    echo "Attempts: {$job['attempts']}\n";
}
```

## Retrying Failed Jobs

```php
$success = $queue->retry($jobId);

if ($success) {
    echo "Job re-queued for processing\n";
} else {
    echo "Job not found or max retries exceeded\n";
}
```

Jobs are limited to a configurable number of retries (default: 3). Once exceeded, `retry()` returns `false`.

## Clearing a Queue

```php
$queue->clear('emails'); // Removes all pending jobs
```

## Configuration

```php
// Using environment variables
// TINA4_QUEUE_BACKEND=file
// TINA4_QUEUE_PATH=data/queue

$queue = new Queue();

// Or with explicit configuration
$queue = new Queue('file', [
    'path' => 'data/queue',
    'maxRetries' => 5,
]);
```

## File Storage Structure

```
data/queue/
  emails/
    a1b2c3d4-1234abcd.json          # Pending job
    b2c3d4e5-5678efgh.json          # Pending job
    failed/
      c3d4e5f6-9012ijkl.json       # Failed job
  reports/
    d4e5f6g7-3456mnop.json          # Pending job
```

Each job file contains:
```json
{
    "id": "a1b2c3d4e5f6a7b8-1a2b3c4d5e",
    "payload": {"to": "user@example.com", "subject": "Welcome!"},
    "status": "pending",
    "created_at": "1711000000.123456",
    "attempts": 0,
    "delay_until": null,
    "error": null
}
```

## Complete Worker Example

```php
<?php
require_once 'vendor/autoload.php';

use Tina4\Queue;
use Tina4\Log;

$queue = new Queue();

// Enqueue some work
$queue->push('notifications', ['type' => 'sms', 'phone' => '+1234567890', 'message' => 'Hello']);
$queue->push('notifications', ['type' => 'email', 'to' => 'user@example.com', 'body' => 'Hi']);

// Process the queue
$queue->process('notifications', function (array $job) {
    $p = $job['payload'];

    match ($p['type']) {
        'sms' => sendSms($p['phone'], $p['message']),
        'email' => sendEmail($p['to'], $p['body']),
        default => throw new \RuntimeException("Unknown notification type: {$p['type']}"),
    };

    Log::info('Notification sent', ['job_id' => $job['id'], 'type' => $p['type']]);
});

echo "Pending: " . $queue->size('notifications') . "\n";
echo "Failed: " . count($queue->failed('notifications')) . "\n";
```

## Tips

- Use named queues to separate concerns: `emails`, `reports`, `notifications`, `imports`.
- Set `maxJobs` in `process()` to avoid processing indefinitely in a single run.
- Delayed jobs are great for retry-after patterns and scheduled notifications.
- Failed jobs preserve the error message and attempt count for debugging.
- The file backend works well for moderate workloads. For high-throughput production queues, implement a Redis or database-backed adapter.
- Jobs are sorted by `created_at` — FIFO order is guaranteed.
