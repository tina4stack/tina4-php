<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


class RequestLogger
{
    public static function before($request, $response)
    {
        $request->startTime = microtime(true);
        \Tina4\Log::info("--> {$request->method} {$request->url}");
        return [$request, $response];
    }

    public static function after($request, $response)
    {
        $duration = (microtime(true) - ($request->startTime ?? microtime(true))) * 1000;
        \Tina4\Log::info(sprintf("<-- %s %s %d %.1fms", $request->method, $request->url, $response->statusCode ?? 200, $duration));
        return [$request, $response];
    }
}
