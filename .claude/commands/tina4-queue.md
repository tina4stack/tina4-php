# Set Up Tina4 Background Queue

Set up a DB-backed job queue for background processing. Use this for any operation that takes more than ~1 second.

## Instructions

1. Create a route that pushes work to the queue
2. Create a worker that processes jobs
3. The queue table (`tina4_queue`) is auto-created

## Producer (in route)

```php
<?php

use Tina4\Router;
use Tina4\Queue;
use Tina4\Producer;

Router::post("/api/reports/generate", function ($request, $response) {
    $queue = new Queue("reports");
    $producer = new Producer($queue);
    $producer->produce([
        "userId" => $request->body["userId"],
        "type" => "monthly",
    ]);
    return $response->json(["status" => "queued"]);
});
```

## Consumer (separate worker)

Create `worker.php`:
```php
<?php

require_once "vendor/autoload.php";

use Tina4\Queue;
use Tina4\Consumer;

$queue = new Queue("reports", function ($job) {
    $data = $job->data;
    // Do slow work here...
    $report = generatePdf($data["userId"], $data["type"]);
    sendEmail($data["userId"], $report);
    $job->complete();
});

$consumer = new Consumer($queue);
$consumer->runForever();
```

Run: `php worker.php`

## Queue Features

```php
<?php

use Tina4\Queue;
use Tina4\Producer;
use Tina4\Consumer;

$queue = new Queue("emails");

// Push with priority (lower = higher priority)
$producer = new Producer($queue);
$producer->produce(["to" => "user@test.com"]);                       // default priority
$producer->produce(["to" => "vip@test.com"], 1);                     // high priority

// Delayed jobs
$producer->produce(["action" => "reminder"], 0, 3600);               // run in 1 hour

// Pop a single job
$job = $queue->pop();
if ($job) {
    process($job->data);
    $job->complete();        // mark done
    // or: $job->fail("reason");
    // or: $job->retry();
}

// Queue management
$queue->size();                     // pending job count
$queue->retryFailed();              // retry all failed jobs
$queue->deadLetters();              // list permanently failed jobs
$queue->purge();                    // delete all completed jobs
```

## When to Use Queues

- Sending emails or SMS
- Generating PDFs/reports
- Calling slow external APIs
- Processing uploaded files (image resize, CSV import)
- Any operation the user should not wait for
