<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ModelCollection — a page of ORM models that also carries the query total.
 */

namespace Tina4;

/**
 * ModelCollection — a page of ORM models that ALSO carries the query total.
 *
 * What the ORM read queries (`where` / `select` / `find` (filter form) / `all` /
 * `withTrashed`) return. It IS the page of models — iterate it, index it,
 * `count()` it, `json_encode()` it — so every existing caller keeps working
 * unchanged. It adds one thing: the TOTAL number of rows matching the query's
 * filter, independent of `limit` / `offset`.
 *
 * ARRAY-COMPATIBLE ON PURPOSE (ADR-0064, following the `Job` precedent of
 * ADR-0024). A PHP `array` cannot carry a property, so PHP is the one framework
 * whose ORM read return type changes from `array` to an object. That change is
 * contained by the four interfaces below:
 *
 *   - `Countable`         — `count($rows)` returns the number of models ON THE
 *                            PAGE (like Python `len()`), NOT the total. The total
 *                            is `getTotalRecords()`, a METHOD, on purpose: a
 *                            `->count` property would read as "how many rows" and
 *                            silently disagree with `count()`.
 *   - `IteratorAggregate` — `foreach ($rows as $r)` yields the models.
 *   - `ArrayAccess`       — `$rows[0]` is a model; the page is a mutable list.
 *   - `JsonSerializable`  — `json_encode($rows)` is the array of model dicts
 *                            (each model via `toDict()`), so the JSON matches
 *                            `DatabaseResult` exactly.
 *
 * `toArray()` returns the bare `array` of models for callers that need native
 * `array_map` / `array_filter` / `array_column`.
 *
 * The total is FREE: every one of those methods already runs `db->fetch()`,
 * which computes a `SELECT COUNT(*)` probe and hands it back (on the array
 * result's `total` key, or a `DatabaseResult`'s `count` property); the ORM used
 * to hydrate the page of models and throw that count away. This class carries it
 * instead, so a caller with 20 models on the page can still learn there are 250
 * rows in the set — with ZERO extra queries.
 *
 * Uniform across all four Tina4 frameworks. Same concept, language-idiomatic
 * accessor name:
 *
 *     Python / Ruby : get_total_records()   to_paginate()
 *     PHP / Node    : getTotalRecords()     toPaginate()
 *
 * @implements \ArrayAccess<int, mixed>
 * @implements \IteratorAggregate<int, mixed>
 */
class ModelCollection implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * The page of hydrated model instances.
     *
     * @var array<int, mixed>
     */
    private array $models;

    /** Total rows matching the query's filter, ignoring limit/offset. */
    private int $total;

    /** The SQL limit that produced this page. */
    private int $limit;

    /** The SQL offset that produced this page. */
    private int $offset;

    /**
     * @param array<int, mixed> $models The page of hydrated model instances.
     * @param int               $total  Total rows matching the query's filter (ignores limit/offset).
     * @param int               $limit  The SQL limit that produced this page.
     * @param int               $offset The SQL offset that produced this page.
     */
    public function __construct(array $models = [], int $total = 0, int $limit = 0, int $offset = 0)
    {
        // array_values so integer indexing ($rows[0]) is stable even when the
        // caller passed a map — the same normalisation a real list guarantees.
        $this->models = array_values($models);
        $this->total = $total;
        $this->limit = $limit;
        $this->offset = $offset;
    }

    /**
     * Total rows matching the query's filter, ignoring limit/offset.
     *
     * This is the whole point of the collection: the page you are iterating is
     * capped by `limit`, but this number is the full count of matching rows —
     * what a pager needs to render "page 3 of 13". Sourced from the fetch COUNT
     * probe the query already computed; it fires NO second COUNT.
     *
     * @return int
     */
    public function getTotalRecords(): int
    {
        return $this->total;
    }

    /**
     * The bare array of model instances, for native `array_map` / `array_filter`
     * / `array_column` and anything that requires a real `array`.
     *
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return $this->models;
    }

    /**
     * The canonical pagination envelope — EXACTLY seven snake_case keys,
     * identical to `DatabaseResult::toPaginate()` (ADR-0043) and to the other
     * three frameworks' `toPaginate()`:
     *
     *     records     the page's rows as dicts (never re-sliced)
     *     total       getTotalRecords() — the true total for the filter
     *     page        floor(offset / per_page) + 1
     *     per_page    the query's limit
     *     total_pages ceil(total / per_page)
     *     limit       the SQL limit actually applied
     *     offset      the SQL offset actually applied
     *
     * `records` are model dicts (via `toDict()`) so the JSON a client sees
     * matches `DatabaseResult` exactly — the result is uniform whether the route
     * returned a raw `$db->fetch(...)` or an ORM `Model::where(...)`.
     *
     * @return array{records: array<int, mixed>, total: int, page: int, per_page: int, total_pages: int, limit: int, offset: int}
     */
    public function toPaginate(): array
    {
        $perPage    = $this->limit > 0 ? $this->limit : count($this->models);
        $page       = $perPage > 0 ? (int) floor($this->offset / $perPage) + 1 : 1;
        $totalPages = $perPage > 0 ? max(1, (int) ceil($this->total / $perPage)) : 1;

        $records = array_map(
            static fn ($m) => (is_object($m) && method_exists($m, 'toDict')) ? $m->toDict() : $m,
            $this->models
        );

        return [
            'records'     => $records,
            'total'       => $this->total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'limit'       => $perPage,
            'offset'      => $this->offset,
        ];
    }

    // ── Countable ───────────────────────────────────────────────────

    /**
     * The number of models ON THIS PAGE (like Python `len()`), NOT the total.
     * The total is `getTotalRecords()`.
     */
    public function count(): int
    {
        return count($this->models);
    }

    // ── IteratorAggregate ───────────────────────────────────────────

    /**
     * @return \ArrayIterator<int, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->models);
    }

    // ── ArrayAccess ─────────────────────────────────────────────────

    /**
     * @param mixed $offset List index
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->models[$offset]);
    }

    /**
     * @param mixed $offset List index
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->models[$offset] ?? null;
    }

    /**
     * @param mixed $offset List index (null appends)
     * @param mixed $value  Model to store
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->models[] = $value;
        } else {
            $this->models[$offset] = $value;
        }
    }

    /**
     * @param mixed $offset List index
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->models[$offset]);
    }

    // ── JsonSerializable ────────────────────────────────────────────

    /**
     * Serialise to the array of model dicts, so `json_encode($collection)`
     * matches the array a route returning `db->fetch(...)` would emit. ORM does
     * not implement `JsonSerializable`, so each model is routed through
     * `toDict()` here (the same rule `Response::jsonable()` applies).
     *
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            static fn ($m) => (is_object($m) && method_exists($m, 'toDict')) ? $m->toDict() : $m,
            $this->models
        );
    }
}
