<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


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
