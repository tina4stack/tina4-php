<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Handle for one registered background task.
 *
 * Returned by {@see App::background()}. Call {@see stop()} to end the task AND
 * deregister it — a stopped task never stays in the registry, so
 * {@see App::backgroundTaskCount()} always reports what is actually registered.
 * Works before {@see App::run()} (drops the pending registration) and while the
 * server is ticking (also stops the live tick on the event loop).
 *
 * This is the ONE background surface, identical across the four frameworks: a
 * handle with a boolean `stop()` plus a count. Mirrors Python's `BackgroundTask`
 * (`handle.stop()`), Ruby's `Tina4::Background::Task` (`task.stop`) and Node's
 * `background()` handle (`handle.stop()`).
 */
class BackgroundTask
{
    private App $app;

    /** @var callable The exact callable registered — stop() matches on it. */
    private $callback;

    private bool $stopped = false;

    public function __construct(App $app, callable $callback)
    {
        $this->app = $app;
        $this->callback = $callback;
    }

    /**
     * Stop this task and deregister it.
     *
     * Idempotent — a second call is a safe no-op that returns false. Delegates to
     * {@see App::stopBackground()}, which removes the pending registration and,
     * once the server is running, the live tick as well.
     *
     * @return bool True if this call removed the task, false if it was already gone.
     */
    public function stop(): bool
    {
        if ($this->stopped) {
            return false;
        }
        $this->stopped = true;

        return $this->app->stopBackground($this->callback);
    }

    /**
     * Whether stop() has been called on this handle.
     *
     * @return bool
     */
    public function stopped(): bool
    {
        return $this->stopped;
    }
}
