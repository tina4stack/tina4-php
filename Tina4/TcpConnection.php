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
 * One live raw TCP connection — the uniform connection object (ADR-0102 D5,
 * feature 142). The SAME surface exists in all four frameworks so the primitive
 * ports: raw bytes in through the service callbacks, raw bytes out through
 * send(), a registry id set by identify(), the raw socket peer in $remoteAddr,
 * and a per-connection $context bag for the app's parse buffer and auth state.
 */
final class TcpConnection
{
    /** The registry id (the IMEI), or null until identify() is called. */
    public ?string $id = null;

    /** Per-connection mutable state bag — the app's parse buffer, auth flag. */
    public array $context = [];

    /** The raw TCP peer address ("127.0.0.1:54321"), never a forwarded header. */
    public string $remoteAddr = '';

    /** @var resource|null The client stream. */
    private $socket;

    private TcpService $service;

    private bool $closed = false;
    private bool $closeFired = false;
    private float $lastActivity;

    /** Unsent outbound bytes — bounded by the service backlog limit. */
    private string $pending = '';

    /** Accumulated bytes for on_line splitting (never seen by on_data). */
    private string $lineBuffer = '';

    /**
     * @param resource $socket
     */
    public function __construct($socket, TcpService $service, string $remoteAddr)
    {
        $this->socket = $socket;
        $this->service = $service;
        $this->remoteAddr = $remoteAddr;
        $this->lastActivity = microtime(true);
    }

    /** @return resource|null */
    public function socket()
    {
        return $this->socket;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function lastActivity(): float
    {
        return $this->lastActivity;
    }

    public function touch(): void
    {
        $this->lastActivity = microtime(true);
    }

    public function &lineBuffer(): string
    {
        return $this->lineBuffer;
    }

    /**
     * Register this live connection under an app-chosen id (the IMEI).
     *
     * A second connection identifying with the same id SUPERSEDES the first in
     * the registry — exactly a device that dropped and reconnected.
     */
    public function identify(string|int $appId): self
    {
        $appId = (string)$appId;
        $this->id = $appId;
        $this->service->registerId($appId, $this);
        return $this;
    }

    /**
     * Write raw bytes to THIS socket. Returns true when queued/sent, false when
     * the connection is closed or dropped for backpressure.
     *
     * A stalled reader (never draining) is bounded: once more than the
     * service's max_backlog bytes are outstanding, the connection is dropped
     * rather than buffered without end.
     */
    public function send(string $data): bool
    {
        if ($this->closed) {
            return false;
        }
        $this->pending .= $data;
        $this->flush();
        if ($this->closed) {
            return false;
        }
        if (strlen($this->pending) > $this->service->maxBacklog()) {
            Log::warning(sprintf(
                "tcp_service '%s': dropping a stalled reader — %d bytes queued "
                . "exceeds the %d-byte backlog limit",
                $this->service->name(),
                strlen($this->pending),
                $this->service->maxBacklog()
            ));
            $this->close();
            return false;
        }
        return true;
    }

    /**
     * Try to push the pending buffer out on a non-blocking socket. Writes what
     * the OS will take and keeps the rest for the next flush.
     */
    public function flush(): void
    {
        if ($this->closed || $this->pending === '' || !is_resource($this->socket)) {
            return;
        }
        while ($this->pending !== '') {
            $written = @fwrite($this->socket, $this->pending);
            if ($written === false) {
                $this->close();
                return;
            }
            if ($written === 0) {
                break; // socket buffer full — would block; keep the rest
            }
            $this->pending = substr($this->pending, $written);
        }
    }

    /**
     * Close this connection. on_close fires exactly once (from the service's
     * removal path) and the registry entry is removed.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->service->remove($this);
    }

    /**
     * Fire on_close exactly once. Called by the service when the connection
     * leaves (peer close, server close, reap, or drain).
     */
    public function fireClose(): void
    {
        if ($this->closeFired) {
            return;
        }
        $this->closeFired = true;
        $this->closed = true;
        $onClose = $this->service->callback('on_close');
        if ($onClose !== null) {
            try {
                $onClose($this);
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$this->service->name()}' on_close error: " . $e->getMessage());
            }
        }
    }
}
