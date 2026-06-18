<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Result of a seed run — ``{seeded, failed, errors}``.
 *
 * Mirrors Python's ``SeedSummary``. Python subclasses ``int`` so the value IS
 * the seeded count for back-compat; PHP has no int-subclass, so this object
 * exposes the same struct three ways so old and new callers both work:
 *
 *   - Property access:   ``$summary->seeded`` / ``->failed`` / ``->errors``
 *   - Array access:      ``$summary['seeded']`` / ``$summary['failed']`` / ``$summary['errors']``
 *   - Int coercion:      ``(int) $summary === $summary->seeded`` (old "row count" contract)
 *   - ``count($summary)`` also returns the seeded count
 *   - ``$summary->toArray()`` / ``->toDict()`` → ``['seeded'=>, 'failed'=>, 'errors'=>]``
 *
 * ``errors`` is a list of ``['row' => <0-based int>, 'message' => <string>]``
 * entries describing every skipped row.
 */
final class SeedSummary implements \ArrayAccess, \Countable, \Stringable, \JsonSerializable
{
    /** @var int Number of rows successfully seeded. */
    public int $seeded;

    /** @var int Number of rows that failed and were skipped. */
    public int $failed;

    /** @var array<int, array{row: int, message: string}> Per-row failure details. */
    public array $errors;

    /**
     * @param array<int, array{row: int, message: string}> $errors
     */
    public function __construct(int $seeded = 0, int $failed = 0, array $errors = [])
    {
        $this->seeded = $seeded;
        $this->failed = $failed;
        $this->errors = $errors;
    }

    /**
     * @return array{seeded: int, failed: int, errors: array<int, array{row: int, message: string}>}
     */
    public function toArray(): array
    {
        return ['seeded' => $this->seeded, 'failed' => $this->failed, 'errors' => $this->errors];
    }

    /**
     * Alias for toArray() — parity with Python's to_dict().
     *
     * @return array{seeded: int, failed: int, errors: array<int, array{row: int, message: string}>}
     */
    public function toDict(): array
    {
        return $this->toArray();
    }

    // ── ArrayAccess ─────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['seeded', 'failed', 'errors'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'seeded' => $this->seeded,
            'failed' => $this->failed,
            'errors' => $this->errors,
            default  => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        match ($offset) {
            'seeded' => $this->seeded = (int) $value,
            'failed' => $this->failed = (int) $value,
            'errors' => $this->errors = (array) $value,
            default  => null,
        };
    }

    public function offsetUnset(mixed $offset): void
    {
        // No-op — the three keys are fixed.
    }

    // ── Countable / Stringable / JsonSerializable ───────────────────

    /**
     * count($summary) returns the seeded count — parity with Python's
     * int-subclass behaviour where the value IS the seeded count.
     */
    public function count(): int
    {
        return $this->seeded;
    }

    public function __toString(): string
    {
        return (string) $this->seeded;
    }

    /**
     * @return array{seeded: int, failed: int, errors: array<int, array{row: int, message: string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
