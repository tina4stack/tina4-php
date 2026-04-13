<?php

/**
 * SSE sales event queue — shared across notification service and SSE endpoint.
 */

$GLOBALS['salesQueue'] = $GLOBALS['salesQueue'] ?? [];

function pushSalesEvent(array $event): void
{
    $GLOBALS['salesQueue'][] = $event;
}

function popSalesEvents(): array
{
    $events = $GLOBALS['salesQueue'];
    $GLOBALS['salesQueue'] = [];
    return $events;
}

\Tina4\Router::get("/api/events/sales", function ($request, $response) {
    $generator = function () {
        $start = time();
        while (true) {
            $events = popSalesEvents();
            foreach ($events as $event) {
                yield "data: " . json_encode($event) . "\n\n";
            }
            if (time() - $start > 30) {
                break;
            }
            usleep(1000000); // 1 second
        }
    };
    return $response->stream($generator(), "text/event-stream");
})->middleware(["adminAuth"]);
