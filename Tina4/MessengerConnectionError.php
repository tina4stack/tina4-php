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
