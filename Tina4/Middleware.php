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
     * Methods are discovered via reflection and sorted alphabetically.
     * If any before method sets the response status to >= 400, execution
     * stops immediately (short-circuit) and the current state is returned.
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
                $result = $class::$method($request, $response);

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
     * Methods are discovered via reflection and sorted alphabetically.
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
                $result = $class::$method($request, $response);

                if (is_array($result) && count($result) >= 2) {
                    [$request, $response] = $result;
                }
            }
        }

        return [$request, $response];
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
     * Discover static methods on a class that match the given prefix.
     *
     * Uses reflection to find public static methods whose names start with
     * the prefix (e.g. "before" or "after"). Results are sorted alphabetically
     * for deterministic execution order.
     *
     * @param string $class Fully-qualified class name
     * @param string $prefix Method name prefix ("before" or "after")
     * @return array<string> Sorted list of method names
     */
    private static function discoverMethods(string $class, string $prefix): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new \ReflectionClass($class);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            if ($method->isPublic() && $method->isStatic() && str_starts_with($method->getName(), $prefix)) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }
}
