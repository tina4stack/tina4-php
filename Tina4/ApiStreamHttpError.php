<?php

namespace Tina4;

/**
 * Raised when the streaming primitives receive a non-2xx response before
 * any body chunk. Carries the HTTP $status so a caller (or the AI client's
 * retry policy) can decide whether the status is retryable (429/5xx) or
 * permanent (4xx). Body is drained before the raise so the socket closes
 * cleanly.
 */
class ApiStreamHttpError extends ApiStreamError
{
    public readonly int $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status);
        $this->status = $status;
    }
}
