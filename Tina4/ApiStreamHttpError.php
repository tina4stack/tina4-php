<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


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
