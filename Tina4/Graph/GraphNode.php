<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * Engine-neutral vertex: id, labels, properties.
 *
 * The graph analogue of a relational row — the same shape on every engine, so an
 * app never sees engine-specific node objects. Node ids are echoed back exactly
 * as the engine returns them (a UUID string on Ultipa); no type is assumed.
 */
class GraphNode implements \JsonSerializable
{
    /** @var mixed The engine's own node id. */
    public mixed $id;

    /** @var array<int, string> */
    public array $labels;

    /** @var array<string, mixed> */
    public array $properties;

    /**
     * @param mixed $id The engine's own node id
     * @param array<int, string>|null $labels Node labels
     * @param array<string, mixed>|null $properties Node properties
     */
    public function __construct(mixed $id, ?array $labels = null, ?array $properties = null)
    {
        $this->id = $id;
        $this->labels = array_values($labels ?? []);
        $this->properties = $properties ?? [];
    }

    /**
     * @return array{id: mixed, labels: array<int, string>, properties: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'labels' => $this->labels, 'properties' => $this->properties];
    }

    /**
     * @return array{id: mixed, labels: array<int, string>, properties: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
