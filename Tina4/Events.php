<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Simple event/observer system for decoupled communication.
 * Zero dependencies — uses only PHP built-in functions.
 *
 *     Events::on('user.created', function ($user) {
 *         echo "Welcome {$user['name']}!";
 *     });
 *
 *     Events::emit('user.created', ['name' => 'Alice']);
 */
class Events
{
    /** @var array<string, array<array{callback: callable, priority: int, once: bool}>> */
    private static array $listeners = [];

    /**
     * Register an event listener.
     *
     * @param string $event Event name (e.g. 'user.created')
     * @param callable $callback Handler to invoke when the event fires
     * @param int $priority Higher priority runs first (default 0)
     */
    public static function on(string $event, callable $callback, int $priority = 0): void
    {
        self::$listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
            'once' => false,
        ];

        self::sortListeners($event);
    }

    /**
     * Register a one-time event listener (auto-removed after first call).
     *
     * @param string $event Event name
     * @param callable $callback Handler to invoke once
     * @param int $priority Higher priority runs first (default 0)
     */
    public static function once(string $event, callable $callback, int $priority = 0): void
    {
        self::$listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
            'once' => true,
        ];

        self::sortListeners($event);
    }

    /**
     * Remove a specific listener or all listeners for an event.
     *
     * @param string $event Event name
     * @param callable|null $callback Specific listener to remove, or null to remove all
     */
    public static function off(string $event, ?callable $callback = null): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }

        if ($callback === null) {
            unset(self::$listeners[$event]);
            return;
        }

        self::$listeners[$event] = array_values(
            array_filter(
                self::$listeners[$event],
                fn(array $entry) => $entry['callback'] !== $callback,
            )
        );

        if (empty(self::$listeners[$event])) {
            unset(self::$listeners[$event]);
        }
    }

    /**
     * Emit an event, calling all registered listeners in priority order.
     * Returns a list of listener return values.
     *
     * Each listener is isolated: if one throws, the error is LOGGED
     * (``Log::warning`` with the event name + error) and the remaining
     * listeners still run. A failed listener contributes a ``null`` slot to
     * the results list, so N listeners always yield N results in priority
     * order. Pass ``$strict = true`` to RE-RAISE on the first listener error
     * instead of isolating it — later listeners do NOT run. Errors are never
     * silently swallowed.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     * @return array List of return values from each listener (null for a failed slot)
     */
    public static function emit(string $event, mixed ...$args): array
    {
        return self::dispatch($event, $args, false);
    }

    /**
     * Emit an event in STRICT mode — re-raise on the first listener error
     * instead of isolating it. Listeners after the throwing one do NOT run.
     *
     * Separate method (not a keyword arg) because emit() is variadic on
     * ``...$args`` and PHP has no keyword-only parameters; this keeps the
     * existing emit() signature/contract intact while exposing the strict
     * behaviour the Python master gained via ``strict=True``.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     * @return array List of return values from each listener
     */
    public static function emitStrict(string $event, mixed ...$args): array
    {
        return self::dispatch($event, $args, true);
    }

    /**
     * Shared sync emit loop. Isolates each listener unless $strict is true.
     *
     * @param array<int, mixed> $args
     */
    private static function dispatch(string $event, array $args, bool $strict): array
    {
        if (!isset(self::$listeners[$event])) {
            return [];
        }

        $results = [];
        $toRemove = [];

        foreach (self::$listeners[$event] as $index => $entry) {
            try {
                $results[] = call_user_func_array($entry['callback'], $args);
            } catch (\Throwable $error) {
                if ($strict) {
                    // Re-raise on the first error — but only after one-time
                    // listeners fired so far are cleaned up, matching the
                    // non-strict path's once() semantics.
                    if ($entry['once']) {
                        $toRemove[] = $index;
                    }
                    self::removeOnce($event, $toRemove);
                    throw $error;
                }
                self::logListenerError($event, $error);
                $results[] = null;
            }

            if ($entry['once']) {
                $toRemove[] = $index;
            }
        }

        self::removeOnce($event, $toRemove);

        return $results;
    }

    /**
     * Get all registered listeners for an event (callbacks only, in priority order).
     *
     * @param string $event Event name
     * @return array<callable>
     */
    public static function listeners(string $event): array
    {
        if (!isset(self::$listeners[$event])) {
            return [];
        }

        return array_map(
            fn(array $entry) => $entry['callback'],
            self::$listeners[$event],
        );
    }

    /**
     * Get all event names that have listeners registered.
     *
     * @return array<string>
     */
    public static function events(): array
    {
        return array_keys(self::$listeners);
    }

    /**
     * Emit an event "asynchronously" — listeners run sequentially (PHP has no
     * true non-blocking async here), each isolated.
     *
     * Mirrors {@see emit()}: a throwing listener does NOT abort the others.
     * On a listener error the failure is LOGGED (never silently swallowed) and
     * a ``null`` slot is appended, so N listeners always yield N results.
     * Pass ``$strict = true`` (or call {@see emitAsyncStrict()}) to re-raise on
     * the first error.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     * @return array Listener return values (null for a failed slot)
     */
    public static function emitAsync(string $event, mixed ...$args): array
    {
        return self::dispatch($event, $args, false);
    }

    /**
     * Async emit in STRICT mode — re-raise on the first listener error.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     * @return array List of return values from each listener
     */
    public static function emitAsyncStrict(string $event, mixed ...$args): array
    {
        return self::dispatch($event, $args, true);
    }

    /**
     * Remove the queued one-time listeners (reverse order to preserve indices),
     * then drop the event key if no listeners remain.
     *
     * @param array<int, int> $toRemove
     */
    private static function removeOnce(string $event, array $toRemove): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }
        foreach (array_reverse($toRemove) as $index) {
            array_splice(self::$listeners[$event], $index, 1);
        }
        if (empty(self::$listeners[$event])) {
            unset(self::$listeners[$event]);
        }
    }

    /**
     * Log a listener failure without ever raising. Visible, never silent.
     *
     * Mirrors Python's ``_log_listener_error``: routes through Tina4's Log
     * (Log::warning with the event name + error type+message). The whole call
     * is itself wrapped so a broken logger can't break the event bus — on any
     * logger failure it falls back to STDERR so the error is still surfaced.
     */
    private static function logListenerError(string $event, \Throwable $error): void
    {
        $detail = sprintf(
            "Event listener for '%s' raised %s: %s",
            $event,
            (new \ReflectionClass($error))->getShortName(),
            $error->getMessage(),
        );
        try {
            Log::warning($detail);
        } catch (\Throwable) {
            // The logger itself must never break the event bus. Fall back to
            // stderr so the failure is still surfaced, never swallowed.
            $stderr = defined('STDERR') ? \STDERR : @fopen('php://stderr', 'w');
            if ($stderr) {
                @fwrite($stderr, '[tina4.events] ' . $detail . "\n");
            }
        }
    }

    /**
     * Remove all listeners for all events.
     */
    public static function clear(): void
    {
        self::$listeners = [];
    }

    /**
     * Sort listeners for an event by priority (highest first).
     */
    private static function sortListeners(string $event): void
    {
        usort(
            self::$listeners[$event],
            fn(array $a, array $b) => $b['priority'] <=> $a['priority'],
        );
    }
}
