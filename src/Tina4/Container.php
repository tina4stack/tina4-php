<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lightweight dependency injection container.
 * Matches the Ruby Tina4::Container implementation.
 *
 * Usage:
 *   $container = new Container();
 *   $container->register('mailer', fn() => new MailService());
 *   $container->singleton('db', fn() => new Database(getenv('DB_URL')));
 *   $mailer = $container->get('mailer');  // new instance each time
 *   $db = $container->get('db');          // same instance always
 */

namespace Tina4;

class Container
{
    /** @var array<string, array{factory: callable, singleton: bool, instance: mixed}> */
    private array $registry = [];

    /**
     * Register a factory callable. Each call to get() will invoke
     * the factory and return a new instance.
     *
     * @param string   $name    Service name
     * @param callable $factory Factory function returning the service
     */
    public function register(string $name, callable $factory): void
    {
        $this->registry[$name] = [
            'factory' => $factory,
            'singleton' => false,
            'instance' => null,
        ];
    }

    /**
     * Register a singleton factory. The factory is called once (lazily)
     * and the result is cached for all subsequent get() calls.
     *
     * @param string   $name    Service name
     * @param callable $factory Factory function returning the service
     */
    public function singleton(string $name, callable $factory): void
    {
        $this->registry[$name] = [
            'factory' => $factory,
            'singleton' => true,
            'instance' => null,
        ];
    }

    /**
     * Resolve a dependency by name.
     *
     * @param string $name Service name
     * @return mixed The resolved service
     * @throws \RuntimeException If the service is not registered
     */
    public function get(string $name): mixed
    {
        if (!isset($this->registry[$name])) {
            throw new \RuntimeException("Service not registered: {$name}");
        }

        $entry = &$this->registry[$name];

        if ($entry['singleton']) {
            if ($entry['instance'] === null) {
                $entry['instance'] = ($entry['factory'])();
            }
            return $entry['instance'];
        }

        // Non-singleton: call factory each time
        return ($entry['factory'])();
    }

    /**
     * Check if a service is registered.
     *
     * @param string $name Service name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->registry[$name]);
    }

    /**
     * Clear all registrations (useful in tests).
     */
    public function reset(): void
    {
        $this->registry = [];
    }
}
