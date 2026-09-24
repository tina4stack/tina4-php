<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4;

/**
 * Base class for class-based background services managed by ServiceRunner.
 *
 * Chapter 27 of the documentation has long taught the class-based pattern:
 *
 *     class EmailQueueWorker extends Service
 *     {
 *         public function run(): void
 *         {
 *             while (!$this->shouldStop()) {
 *                 // process work
 *             }
 *         }
 *     }
 *
 *     ServiceRunner::registerService('emails', new EmailQueueWorker());
 *     ServiceRunner::start();
 *
 * Until 3.13.1 this base class didn't exist. The real ServiceRunner only
 * accepted bare callables. This class bridges the gap: subclasses
 * implement `run()` (the work loop) and optionally override `stop()`;
 * the base provides a `shouldStop()` helper backed by an internal flag.
 *
 * For function-style services without state, you can still register
 * callables directly via `ServiceRunner::register('name', $callable, $options)`.
 * The two registration styles coexist.
 */
abstract class Service
{
    /** When true, subclasses' `run()` loops should exit cleanly. */
    protected bool $running = true;

    /**
     * Main work loop. Subclasses MUST override this.
     *
     * Typical shape:
     *
     *     public function run(): void
     *     {
     *         while ($this->shouldStop() === false) {
     *             // do work, sleep, etc.
     *         }
     *     }
     */
    abstract public function run(): void;

    /**
     * Signal this service to stop. Sets the internal flag the `run()`
     * loop reads via `shouldStop()`. Subclasses may override for custom
     * shutdown behaviour but should always call `parent::stop()`.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Returns true once `stop()` has been called. Use inside `run()`
     * loops as the exit condition.
     *
     *     while (!$this->shouldStop()) { ... }
     */
    public function shouldStop(): bool
    {
        return !$this->running;
    }

    /**
     * Return a callable that ServiceRunner can register. The callable
     * just invokes `$this->run()`. Used by
     * ServiceRunner::registerService() under the hood.
     */
    public function asCallable(): callable
    {
        return [$this, 'run'];
    }
}
