<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Standardized middleware orchestrator.
 *
 * Middleware classes follow a simple convention:
 *   - Static methods named `before*` run BEFORE the route handler
 *   - Static methods named `after*` run AFTER the route handler
 *   - Each method receives ($request, $response) and returns [$request, $response]
 *   - If a before method returns a response with status >= 400, the handler is skipped (short-circuit)
 *
 * Ordering rule (deterministic — never alphabetical):
 *
 *   - Across middleware CLASSES: REGISTRATION order — the order they were
 *     attached via Middleware::use() / ->middleware() / Router::group(). This
 *     is just the natural iteration of the middleware list (unchanged).
 *   - Within a single CLASS: DEFINITION order — before* / after* methods run in
 *     the order they appear in the class body. PHP's get_class_methods()
 *     already returns methods in declaration order, so discoverMethods() keeps
 *     that order and does NOT sort (pre-3.13.38 it sort()ed alphabetically).
 *
 * before* always runs before the route handler, after* after.
 *
 * Usage:
 *   // Register global middleware (applied to every request)
 *   Middleware::use(CorsMiddleware::class);
 *   Middleware::use(RateLimiter::class);
 *
 *   // Or via Router shorthand
 *   Router::use(CorsMiddleware::class);
 *
 *   // Run middleware programmatically
 *   [$request, $response] = Middleware::runBefore([CorsMiddleware::class], $request, $response);
 *   [$request, $response] = Middleware::runAfter([RequestLogger::class], $request, $response);
 */
class Middleware
{
    /** @var array<string> Fully-qualified class names of global middleware */
    private static array $globalMiddleware = [];

    /**
     * Register a middleware class to run on every request.
     *
     * @param string $class Fully-qualified class name (e.g. \Tina4\Middleware\CorsMiddleware::class)
     */
    public static function use(string $class): void
    {
        if (!in_array($class, self::$globalMiddleware, true)) {
            self::$globalMiddleware[] = $class;
        }
    }

    /**
     * Run all `before*` methods from the given middleware classes.
     *
     * Methods run in DEFINITION order within a class and REGISTRATION order
     * across classes (see {@see discoverMethods()}); before* always runs
     * before the route handler.
     *
     * If any before method sets the response status to >= 400, execution
     * stops immediately (short-circuit) and the current state is returned.
     *
     * A before* method that THROWS is caught, LOGGED, and converted to a
     * clean 500 (see {@see middleware500()}) — it never crashes the worker /
     * leaks an unhandled exception. The throw also short-circuits (handler
     * skipped), exactly like a 4xx short-circuit.
     *
     * @param array<string> $middlewareClasses Fully-qualified class names
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response} The (possibly modified) request and response
     */
    public static function runBefore(array $middlewareClasses, Request $request, Response $response): array
    {
        foreach ($middlewareClasses as $class) {
            $methods = self::discoverMethods($class, 'before');

            foreach ($methods as $method) {
                try {
                    $result = $class::$method($request, $response);
                } catch (\Throwable $error) {
                    // Throwing before* → logged clean 500, short-circuit.
                    $response = self::middleware500($response, $class, $method, $error);
                    return [$request, $response];
                }

                if (is_array($result) && count($result) >= 2) {
                    [$request, $response] = $result;
                }

                // Short-circuit: if response has an error status, stop processing
                if ($response->getStatusCode() >= 400) {
                    return [$request, $response];
                }
            }
        }

        return [$request, $response];
    }

    /**
     * Run all `after*` methods from the given middleware classes.
     *
     * Methods run in DEFINITION order within a class and REGISTRATION order
     * across classes; after* always runs after the route handler.
     *
     * AFTER-ON-4xx RULE (documented + consistent across all 4 frameworks):
     * after* methods ALWAYS run, even when a before* short-circuited with
     * status >= 400 and the handler was skipped — so after-middleware can
     * still add headers / logging on error responses. The dispatcher calls
     * runAfter() unconditionally after the before/handler block.
     *
     * An after* method that THROWS is caught, LOGGED, and converted to a
     * clean 500; the remaining after* methods STILL run (they may add
     * headers/logging), so one broken after* never silences the others.
     *
     * @param array<string> $middlewareClasses Fully-qualified class names
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response} The (possibly modified) request and response
     */
    public static function runAfter(array $middlewareClasses, Request $request, Response $response): array
    {
        foreach ($middlewareClasses as $class) {
            $methods = self::discoverMethods($class, 'after');

            foreach ($methods as $method) {
                try {
                    $result = $class::$method($request, $response);
                } catch (\Throwable $error) {
                    // Throwing after* → logged clean 500, keep running the rest.
                    $response = self::middleware500($response, $class, $method, $error);
                    continue;
                }

                if (is_array($result) && count($result) >= 2) {
                    [$request, $response] = $result;
                }
            }
        }

        return [$request, $response];
    }

    /**
     * Produce the deterministic, logged clean 500 for a middleware that threw.
     *
     * Mirrors Python's _middleware_500: logs via Log::error with the class +
     * method + error type/message, then returns a 500 JSON response with the
     * canonical body shape ``{"error":"Internal Server Error","status":500}``
     * so all 4 frameworks emit identical output. Public so the Router's
     * per-route middleware dispatch shares the exact same 500 contract.
     *
     * @param string $class Middleware class name (or a short label like "Closure")
     * @param string $method The before* / after* method that threw
     */
    public static function middleware500(Response $response, string $class, string $method, \Throwable $error): Response
    {
        $shortClass = class_exists($class)
            ? (new \ReflectionClass($class))->getShortName()
            : $class;
        $shortError = (new \ReflectionClass($error))->getShortName();
        try {
            Log::error(sprintf(
                'Middleware %s.%s raised %s: %s',
                $shortClass,
                $method,
                $shortError,
                $error->getMessage(),
            ));
        } catch (\Throwable) {
            // Logging must never block the 500 we owe the client.
        }
        return $response->json(['error' => 'Internal Server Error', 'status' => 500], 500);
    }

    /**
     * Get all globally registered middleware class names.
     *
     * @return array<string>
     */
    public static function getGlobal(): array
    {
        return self::$globalMiddleware;
    }

    /**
     * Reset all global middleware (for testing).
     */
    public static function reset(): void
    {
        self::$globalMiddleware = [];
    }

    /**
     * Discover static methods on a class that match the given prefix, in
     * source DEFINITION order (NOT alphabetical).
     *
     * PHP's get_class_methods() returns method names in declaration order
     * (parent methods first, then the class's own, each in source order), so
     * iterating it preserves the order the developer wrote the before* / after*
     * methods in. We deliberately do NOT sort — pre-3.13.38 this sort()ed
     * alphabetically, which made cross-method order surprising and diverged
     * from registration intent. A reflection check keeps only public static
     * methods matching the prefix.
     *
     * Public so the live dispatcher, the orchestrator, and the regression
     * tests all share ONE ordering rule (parity with Python's
     * Middleware._discover_methods).
     *
     * @param string $class Fully-qualified class name
     * @param string $prefix Method name prefix ("before" or "after")
     * @return array<string> Method names in definition order
     */
    public static function discoverMethods(string $class, string $prefix): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new \ReflectionClass($class);
        $methods = [];

        // get_class_methods() preserves declaration order (base -> derived,
        // source order within each). Filter to public static prefixed methods.
        foreach (get_class_methods($class) as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $method = $reflection->getMethod($name);
            if ($method->isPublic() && $method->isStatic()) {
                $methods[] = $name;
            }
        }

        return $methods;
    }
}
