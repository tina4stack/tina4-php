<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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
