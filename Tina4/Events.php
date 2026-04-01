<?php

/**
 * Tina4 - This is not a 4ramework.
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
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     * @return array List of return values from each listener
     */
    public static function emit(string $event, mixed ...$args): array
    {
        if (!isset(self::$listeners[$event])) {
            return [];
        }

        $results = [];
        $toRemove = [];

        foreach (self::$listeners[$event] as $index => $entry) {
            $results[] = call_user_func_array($entry['callback'], $args);

            if ($entry['once']) {
                $toRemove[] = $index;
            }
        }

        // Remove one-time listeners (reverse order to preserve indices)
        foreach (array_reverse($toRemove) as $index) {
            array_splice(self::$listeners[$event], $index, 1);
        }

        if (empty(self::$listeners[$event])) {
            unset(self::$listeners[$event]);
        }

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
     * Emit an event asynchronously — listeners are called but exceptions are silently caught.
     *
     * In PHP there is no true non-blocking async, so this fires listeners sequentially
     * but swallows any exceptions to avoid interrupting the caller.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments passed to each listener
     */
    public static function emitAsync(string $event, mixed ...$args): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }

        $toRemove = [];

        foreach (self::$listeners[$event] as $index => $entry) {
            try {
                call_user_func_array($entry['callback'], $args);
            } catch (\Exception $e) {
                // Async emit silently catches errors
            }

            if ($entry['once']) {
                $toRemove[] = $index;
            }
        }

        // Remove one-time listeners (reverse order to preserve indices)
        foreach (array_reverse($toRemove) as $index) {
            array_splice(self::$listeners[$event], $index, 1);
        }

        if (empty(self::$listeners[$event])) {
            unset(self::$listeners[$event]);
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
