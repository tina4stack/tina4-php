<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Raised when an IMAP read fails to connect, authenticate, or speak the
 * protocol. Distinct from a successful fetch that simply has no messages —
 * that still returns an empty result ([] / null / 0), NOT an error.
 *
 * Extends \RuntimeException so existing `catch (\RuntimeException)` and
 * `catch (\Throwable)` handlers around the SMTP/IMAP code keep catching it.
 */
class MessengerConnectionError extends \RuntimeException
{
}
