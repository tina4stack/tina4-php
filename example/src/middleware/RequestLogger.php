<?php

class RequestLogger
{
    public static function before($request, $response)
    {
        $request->startTime = microtime(true);
        \Tina4\Debug::message("--> {$request->method} {$request->url}");
        return [$request, $response];
    }

    public static function after($request, $response)
    {
        $duration = (microtime(true) - ($request->startTime ?? microtime(true))) * 1000;
        \Tina4\Debug::message(sprintf("<-- %s %s %d %.1fms", $request->method, $request->url, $response->statusCode ?? 200, $duration));
        return [$request, $response];
    }
}
