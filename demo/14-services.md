# Background Services

The `ServiceRunner` class manages long-running background processes with cron-based scheduling, daemon mode, auto-discovery, retry logic, and PID file management. Services can be registered programmatically or discovered from PHP files in a directory.

## Registering a Service

```php
use Tina4\ServiceRunner;

// Cron-scheduled service (runs every 5 minutes)
ServiceRunner::register('cleanup', function (object $context) {
    $context->logger->info('Running cleanup...');
    // Delete expired sessions, temp files, etc.
}, options: [
    'timing' => '*/5 * * * *',  // Cron expression
    'maxRetries' => 3,
]);

// Daemon service (handler manages its own loop)
ServiceRunner::register('websocket-relay', function (object $context) {
    while ($context->running) {
        // Custom event loop
        $context->logger->debug('Relay tick');
        usleep(100000); // 100ms
    }
}, options: [
    'daemon' => true,
]);

// Service with timeout
ServiceRunner::register('report-generator', function (object $context) {
    // Generate reports
    $context->logger->info('Generating daily report');
}, options: [
    'timing' => '0 2 * * *',  // Daily at 2:00 AM
    'timeout' => 300,          // 5 minute timeout
]);
```

## Service Options

| Option | Default | Description |
|--------|---------|-------------|
| `timing` | `''` | Cron expression (5 fields: minute hour day month weekday) |
| `daemon` | `false` | If true, handler manages its own loop |
| `maxRetries` | `3` | Number of restart attempts on crash |
| `timeout` | `0` | Execution timeout in seconds (0 = unlimited) |

## Cron Expression Syntax

The timing field uses standard 5-field cron format (evaluated in local timezone):

```
 ┌────── minute (0-59)
 │ ┌──── hour (0-23)
 │ │ ┌── day of month (1-31)
 │ │ │ ┌ month (1-12)
 │ │ │ │ ┌ day of week (0-6, Sunday=0)
 │ │ │ │ │
 * * * * *
```

Examples:
```
*/5 * * * *     Every 5 minutes
0 * * * *       Every hour on the hour
0 9 * * 1-5     Weekdays at 9:00 AM
30 2 * * *      Daily at 2:30 AM
0 0 1 * *       First of every month at midnight
0 8,12,18 * * * At 8am, noon, and 6pm
```

Supported patterns:
- `*` — wildcard (any value)
- `*/N` — every N units
- `N` — exact value
- `N-M` — range
- `N-M/S` — range with step
- `N,M,O` — list of values

## Service Discovery

Place service files in `src/services/` (or the `TINA4_SERVICE_DIR` directory). Each file must return an array with `name`, `handler`, and optional scheduling.

```php
// src/services/email_worker.php
return [
    'name' => 'email-worker',
    'handler' => function (object $context) {
        $context->logger->info('Processing email queue');
        $queue = new \Tina4\Queue();
        $queue->process('emails', function ($job) use ($context) {
            $context->logger->info('Sending email', ['to' => $job['payload']['to']]);
        }, ['maxJobs' => 50]);
    },
    'timing' => '* * * * *',  // Every minute
];
```

```php
// src/services/health_check.php
return [
    'name' => 'health-monitor',
    'handler' => function (object $context) {
        $context->logger->info('Health check OK');
    },
    'timing' => '*/10 * * * *',  // Every 10 minutes
];
```

Discover and start all services:

```php
$discovered = ServiceRunner::discover('src/services');
echo count($discovered) . " services discovered\n";

ServiceRunner::start(); // Starts all registered services
```

## Starting and Stopping

```php
// Start all services
ServiceRunner::start();

// Start a specific service
ServiceRunner::start('email-worker');

// Stop all services
ServiceRunner::stop();

// Stop a specific service
ServiceRunner::stop('email-worker');
```

When `pcntl_fork` is available, each service runs in its own child process. Without `pcntl`, services run in the foreground (blocking).

## Listing Services

```php
$services = ServiceRunner::list();

foreach ($services as $name => $info) {
    echo "{$info['name']}: ";
    echo $info['running'] ? "running (PID: {$info['pid']})" : "stopped";
    echo "\n";
}
```

## Checking Service Status

```php
if (ServiceRunner::isRunning('email-worker')) {
    echo "Email worker is alive\n";
}
```

## Service Context

The context object passed to handlers provides:

```php
ServiceRunner::register('demo', function (object $context) {
    $context->name;     // Service name
    $context->running;  // Whether the service should continue
    $context->lastRun;  // Timestamp of last execution

    // Logger methods
    $context->logger->debug('Debug message');
    $context->logger->info('Info message');
    $context->logger->warning('Warning message');
    $context->logger->error('Error message');
});
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_SERVICE_DIR` | `src/services` | Directory for service discovery |
| `TINA4_SERVICE_SLEEP` | `5` | Seconds between loop iterations |

## PID Files

PID files are stored in `data/services/` by default. They track which services are running and enable the stop mechanism.

```php
// Customize PID directory
ServiceRunner::setPidDir('/var/run/tina4');
```

## Tips

- Use cron-style timing for periodic tasks (cleanup, reports, health checks).
- Use daemon mode for event-driven services (WebSocket relay, queue workers).
- The `maxRetries` option prevents infinite restart loops on persistent errors.
- Services detect stop signals via stop files — call `ServiceRunner::stop()` to signal a graceful shutdown.
- In Docker containers without `pcntl_fork`, services run in the foreground — use one container per service.
- Always log within service handlers for observability.
- Call `ServiceRunner::reset()` in tests to clear all registered services.
