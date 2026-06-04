<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Case-insensitive associative array — used for HTTP headers.
 *
 * HTTP header field-names are case-insensitive per RFC 7230 §3.2.
 * With this class, ``$request->headers['Content-Type']``,
 * ``$request->headers['content-type']`` and
 * ``$request->headers['CONTENT-TYPE']`` all return the same value.
 *
 * Keys are stored lowercase internally. The original-case key handed in is
 * preserved via {@see ::originalKeys()} for callers that need it (e.g. to
 * re-emit a header with its incoming case), but lookups are always
 * case-insensitive.
 *
 * Cross-framework parity: same behaviour ships in
 * tina4_python (CaseInsensitiveDict in tina4_python/core/request.py),
 * tina4-ruby (Tina4::Request) and tina4-nodejs (Tina4Request) so the
 * chapter 10 documented examples (``headers['Content-Type']``) finally do
 * what they read like. tina4-book#141 PY-10-03.
 */
class CaseInsensitiveArray implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<string, mixed> Lowercase-keyed storage */
    private array $store = [];

    /** @var array<string, string> Map of lowercase-key → original-case key */
    private array $originalCase = [];

    /**
     * @param iterable<string, mixed> $items
     */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $key => $value) {
            $this->offsetSet($key, $value);
        }
    }

    private function normalise(mixed $key): string
    {
        return is_string($key) ? strtolower($key) : (string) $key;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($this->normalise($offset), $this->store);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->store[$this->normalise($offset)] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            // CaseInsensitiveArray is associative; appending is meaningless.
            // Treat as no-op rather than allowing a numeric index that
            // would never be reachable via case-insensitive lookup.
            return;
        }
        $key = $this->normalise($offset);
        $this->store[$key] = $value;
        if (is_string($offset)) {
            $this->originalCase[$key] = $offset;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $key = $this->normalise($offset);
        unset($this->store[$key], $this->originalCase[$key]);
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->store);
    }

    public function count(): int
    {
        return count($this->store);
    }

    /**
     * Return a plain associative array snapshot — keys are lowercase.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->store;
    }

    /**
     * Map of lowercase key → the original-case spelling supplied by the
     * caller. Useful when a downstream serialiser wants to round-trip the
     * exact header name the client sent.
     *
     * @return array<string, string>
     */
    public function originalKeys(): array
    {
        return $this->originalCase;
    }
}
