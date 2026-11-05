<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Tina4;

/**
 * Raw TCP service registry and the server-driven lifecycle hooks (feature 142,
 * ADR-0102). A Tina4 app exposes its own non-HTTP, long-lived binary TCP service
 * — a GPS/GPRS car-tracker gateway is the driving case — in the SAME process as
 * its HTTP dashboard, reusing config, logging, ORM and queue.
 *
 *   $svc = \Tina4\Tcp::service(5023, [
 *       'on_data' => function (\Tina4\TcpConnection $conn, string $data) {
 *           $conn->send(ackFor($data));
 *       },
 *   ]);
 *
 *   // server -> device push from an HTTP route:
 *   \Tina4\Tcp::get()->sendTo($imei, $command);
 *
 * The listener folds into Server's single stream_select accept loop (D1), so
 * there is no second loop and no forked worker per connection.
 */
final class Tcp
{
    /** @var array<string, TcpService> */
    private static array $services = [];

    /**
     * Register a named raw TCP service; the server binds it on boot (D1).
     * Options: name, on_connect, on_data, on_close, on_line, idle_timeout,
     * max_connections, max_backlog. Options are read once here.
     */
    public static function service(int $port, array $options = []): TcpService
    {
        $service = new TcpService($port, $options);
        self::$services[$service->name()] = $service;
        return $service;
    }

    /**
     * The registry for a named service, for server -> device push. Throws for
     * an unknown service name — a programming error, unlike a send to an
     * unknown device id, which is a runtime no-op.
     */
    public static function get(string $name = 'default'): TcpService
    {
        if (!isset(self::$services[$name])) {
            $known = self::$services === [] ? '(none registered)' : implode(', ', array_keys(self::$services));
            throw new \RuntimeException(
                "no tcp_service named '{$name}' is registered; known services: {$known}"
            );
        }
        return self::$services[$name];
    }

    /** @return TcpService[] */
    public static function registered(): array
    {
        return array_values(self::$services);
    }

    /** Drop every registered service (test isolation; not used in production). */
    public static function clearRegistry(): void
    {
        self::$services = [];
    }

    /** Start every registered service on boot (called by Server). */
    public static function startAll(string $host = '0.0.0.0'): void
    {
        foreach (self::$services as $service) {
            try {
                $service->start($host);
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$service->name()}' failed to start: " . $e->getMessage());
            }
        }
    }

    /** @return array<int, resource> Every service's listen + client sockets. */
    public static function readResources(): array
    {
        $resources = [];
        foreach (self::$services as $service) {
            foreach ($service->readResources() as $id => $socket) {
                $resources[$id] = $socket;
            }
        }
        return $resources;
    }

    /** The service that owns $socket, or null — routes a readable socket. */
    public static function serviceForSocket($socket): ?TcpService
    {
        foreach (self::$services as $service) {
            if ($service->ownsSocket($socket)) {
                return $service;
            }
        }
        return null;
    }

    /** Idle work for every service (flush pending + reap idle). */
    public static function onIdleAll(): void
    {
        foreach (self::$services as $service) {
            $service->onIdle();
        }
    }

    /** Stop every service accepting (shutdown step 1). */
    public static function stopAcceptingAll(): void
    {
        foreach (self::$services as $service) {
            try {
                $service->stopAccepting();
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$service->name()}' stop-accepting failed: " . $e->getMessage());
            }
        }
    }

    /** Drain and release every service's connections (shutdown drain step). */
    public static function drainAll(): void
    {
        foreach (self::$services as $service) {
            try {
                $service->drain();
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$service->name()}' drain failed: " . $e->getMessage());
            }
        }
    }
}
