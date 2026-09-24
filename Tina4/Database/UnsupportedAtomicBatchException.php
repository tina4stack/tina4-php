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

namespace Tina4\Database;

/**
 * ADR-0044 / DBA-P02: a provider/deployment cannot guarantee an atomic
 * multi-row batch. Raised by Database::executeMany() before any row is
 * written when the adapter's own supportsAtomicBatch() is false and the
 * batch has more than one row — never a silent partial-durability write.
 */
class UnsupportedAtomicBatchException extends \RuntimeException
{
}
