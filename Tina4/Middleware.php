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
 *   - Each method receives ($request, $response)
 *
 * RETURN-VALUE TABLE — every before* / after* hook, at every scope (global and
 * per-route), and both entry points (this orchestrator and Router::dispatch)
 * read a hook's return value the same way:
 *
 *   | a Response object              | SHORT-CIRCUIT. That object IS the response, at ANY
 *   |                                | status - the only rule that can express a 302.
 *   | the [$request, $response] pair | rebind both, continue
 *   | false                          | SHORT-CIRCUIT. Send the response as set; a response
 *   |                                | still at its default 200/empty becomes a 403.
 *   | null (or anything else)        | continue
 *
 * Plus a retained legacy compatibility path on the BEFORE pass only: a status
 * >= 400 left on the response short-circuits even when the hook returned null.
 * It cannot express a 3xx redirect, which is why the Response rule above is the
 * primary mechanism and this one is kept only so pre-existing middleware that
 * signals by status alone keeps working. It is deliberately NOT applied to the
 * after pass, where a 4xx response is the normal case an after* hook exists to
 * decorate.
 *
 * Ordering rule (deterministic — never alphabetical):
 *
 *   - Across middleware CLASSES: REGISTRATION order — the order they were
 *     attached via Middleware::use() / ->middleware() / Router::group(). This
 *     is just the natural iteration of the middleware list (unchanged).
 *   - Within a single CLASS: DEFINITION order — before* / after* methods run in
 *     the order they appear in the class body.
 *   - Across a class HIERARCHY: BASE FIRST, then the subclass's own hooks, so a
 *     base class's setup cannot be preceded by the subclass code that depends
 *     on it (see {@see discoverMethods()}).
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
     * Methods run in DEFINITION order within a class, BASE class first across a
     * hierarchy, and REGISTRATION order across classes (see
     * {@see discoverMethods()}); before* always runs before the route handler.
     *
     * Return values are read per the table on this class: a Response ends the
     * chain at any status, a [$request, $response] pair rebinds and continues,
     * `false` ends it with the response as set (403 when still default), and
     * null continues. A status >= 400 left behind by a hook that returned null
     * also ends the chain — the legacy compatibility path.
     *
     * A before* method that THROWS is caught, LOGGED, and converted to a
     * clean 500 (see {@see middleware500()}) — it never crashes the worker /
     * leaks an unhandled exception. The throw also short-circuits (handler
     * skipped), exactly like a 4xx short-circuit.
     *
     * @param array<string> $middlewareClasses Fully-qualified class names
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response, 2: Response|null} The (possibly
     *         replaced) request and response, plus the response that ended the
     *         chain — null when every hook ran. A caller destructuring
     *         `[$request, $response]` is unaffected; the dispatcher reads the
     *         third element because a 302 or a plain 200 Response is a
     *         short-circuit no status check could recognise.
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
                    return [$request, $response, $response];
                }

                $shortCircuit = self::applyHookResult($result, $request, $response);
                if ($shortCircuit !== null) {
                    return [$request, $shortCircuit, $shortCircuit];
                }

                // LEGACY COMPATIBILITY PATH, not the main mechanism: a hook that
                // returned null but left an error status still ends the chain,
                // so middleware written before the Response rule existed keeps
                // working. It cannot express a 3xx — return the Response for that.
                if ($response->getStatusCode() >= 400) {
                    return [$request, $response, $response];
                }
            }
        }

        return [$request, $response, null];
    }

    /**
     * Run all `after*` methods from the given middleware classes.
     *
     * Methods run in DEFINITION order within a class, BASE class first across a
     * hierarchy, and REGISTRATION order across classes; after* always runs
     * after the route handler.
     *
     * AFTER-ON-4xx RULE (documented + consistent across all 4 frameworks):
     * after* methods ALWAYS run, even when a before* short-circuited with
     * status >= 400 and the handler was skipped — so after-middleware can
     * still add headers / logging on error responses. Every dispatch path that
     * matched a route finishes through the after pass, short-circuited or not.
     * The legacy ">= 400 ends the chain" path is deliberately NOT applied here:
     * a 4xx response is precisely what an after* hook exists to decorate.
     *
     * Return values are read per the table on this class, so an after* hook can
     * still replace the response outright by returning one — that ends the
     * after pass, because the returned object IS the response.
     *
     * An after* method that THROWS is caught, LOGGED, and converted to a
     * clean 500; the remaining after* methods STILL run (they may add
     * headers/logging), so one broken after* never silences the others.
     *
     * @param array<string> $middlewareClasses Fully-qualified class names
     * @param Request $request
     * @param Response $response
     * @return array{0: Request, 1: Response, 2: Response|null} The (possibly
     *         replaced) request and response, plus the response that ended the
     *         pass — null when every hook ran.
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

                $shortCircuit = self::applyHookResult($result, $request, $response);
                if ($shortCircuit !== null) {
                    return [$request, $shortCircuit, $shortCircuit];
                }
            }
        }

        return [$request, $response, null];
    }

    /**
     * Apply the return-value table to one hook's result.
     *
     * The single place the table on this class is implemented, so the
     * orchestrator above and the Router's own middleware dispatch can never
     * drift into reading a hook's return value two different ways.
     *
     * $request and $response are BY REFERENCE because the pair form rebinds
     * both and the caller must see the replacement.
     *
     * @param mixed         $result          Whatever the hook returned
     * @param Request       $request         Rebound by the [$request, $response] form
     * @param Response      $response        Rebound by the [$request, $response] form
     * @param callable|null $renderForbidden Builds the fallback 403 as
     *        fn(Request, Response): Response. The Router passes its own error
     *        renderer so a middleware 403 looks like every other error page;
     *        the default is the canonical JSON envelope.
     * @return Response|null The response that ends the chain, or null to continue
     */
    public static function applyHookResult(
        mixed $result,
        Request &$request,
        Response &$response,
        ?callable $renderForbidden = null
    ): ?Response {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === false) {
            // "Send the response as set." A hook that only said no, without
            // saying what to send, gets the 403 it meant.
            if ($response->getStatusCode() === 200 && $response->getBody() === '') {
                return $renderForbidden !== null
                    ? $renderForbidden($request, $response)
                    : $response->json(['error' => 'Forbidden', 'status' => 403], 403);
            }
            return $response;
        }

        if (is_array($result) && count($result) >= 2) {
            [$request, $response] = $result;
        }

        return null;
    }

    /**
     * Produce the deterministic, logged clean 500 for a middleware that threw.
     *
     * Logs via Log::error with the class + method + error type/message, then
     * returns a 500 JSON response with the canonical body shape
     * ``{"error":"Internal Server Error","status":500}`` so all 4 frameworks
     * emit identical output (Python's Middleware.middleware_500 is the twin).
     * Public so the Router's per-route middleware dispatch shares the exact
     * same 500 contract.
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
     * Global middleware that runs BEFORE route matching.
     *
     * A middleware opts in with `public static bool $preMatch = true`.
     * Everything else runs AFTER matching, where the matched route's metadata
     * is readable.
     *
     * This REPLACES a hardcoded `is_a(..., CorsMiddleware::class)` check in the
     * dispatcher, which ran the whole global set before matching and singled
     * CORS out by class name. That only ever worked here because PHP's
     * CsrfMiddleware is attached per-route rather than globally - a placement
     * difference nobody had written down, and one the other three do not share.
     *
     * NOT named `$beforeMatch` - hook discovery treats every `before*` member
     * as a middleware hook.
     *
     * @return array<int, class-string> Middleware to run before matching
     */
    public static function getPreMatch(): array
    {
        return array_values(array_filter(
            self::$globalMiddleware,
            static fn($c) => (bool) (new \ReflectionClass($c))->getStaticPropertyValue('preMatch', false)
        ));
    }

    /**
     * Global middleware that runs AFTER matching. This is the default.
     *
     * @return array<int, class-string> Middleware to run after matching
     */
    public static function getPostMatch(): array
    {
        return array_values(array_filter(
            self::$globalMiddleware,
            static fn($c) => !(bool) (new \ReflectionClass($c))->getStaticPropertyValue('preMatch', false)
        ));
    }

    /**
     * Reset all global middleware (for testing).
     */
    public static function reset(): void
    {
        self::$globalMiddleware = [];
    }

    /**
     * Discover public static methods matching the prefix, BASE class first and
     * in source DEFINITION order within each class (never alphabetical).
     *
     * A hook a class inherits runs BEFORE the hooks that class adds, so a base
     * class can establish what its subclasses build on. `get_class_methods()`
     * alone cannot give that order: it lists the class's OWN methods first and
     * the inherited ones after (verified on PHP 8.5.7), which is derived-first
     * — the reverse. The class hierarchy is therefore walked from the most
     * distant ancestor down to the class itself, taking only the methods each
     * class DECLARES, so declaration order survives within a class and the
     * inheritance order is explicit rather than assumed. An override keeps the
     * position of the base declaration it replaces.
     *
     * Sorting is deliberately absent — pre-3.13.38 this sort()ed alphabetically,
     * which made cross-method order surprising and diverged from the order the
     * developer wrote.
     *
     * Public so the live dispatcher, the orchestrator, and the regression
     * tests all share ONE ordering rule (parity with Python's
     * Middleware._discover_methods, which walks a reversed MRO for the same
     * reason).
     *
     * @param string $class Fully-qualified class name
     * @param string $prefix Method name prefix ("before" or "after")
     * @return array<string> Method names, base class first, definition order within each
     */
    public static function discoverMethods(string $class, string $prefix): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $hierarchy = [];
        for ($ancestor = new \ReflectionClass($class); $ancestor !== false; $ancestor = $ancestor->getParentClass()) {
            array_unshift($hierarchy, $ancestor);
        }

        $methods = [];
        foreach ($hierarchy as $ancestor) {
            foreach (get_class_methods($ancestor->getName()) as $name) {
                if (!str_starts_with($name, $prefix) || in_array($name, $methods, true)) {
                    continue;
                }
                $method = $ancestor->getMethod($name);
                if (
                    $method->isPublic()
                    && $method->isStatic()
                    && $method->getDeclaringClass()->getName() === $ancestor->getName()
                ) {
                    $methods[] = $name;
                }
            }
        }

        return $methods;
    }
}
