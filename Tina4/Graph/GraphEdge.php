<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * Engine-neutral edge: id, type, from/to node ids, properties.
 *
 * `$fromId`/`$toId` are the ids of the endpoint nodes; `toArray()` emits them
 * under the neutral `from`/`to` keys, the same shape on every engine.
 */
class GraphEdge implements \JsonSerializable
{
    /** @var mixed The engine's own edge id. */
    public mixed $id;

    public string $type;

    /** @var mixed The id of the source node. */
    public mixed $fromId;

    /** @var mixed The id of the target node. */
    public mixed $toId;

    /** @var array<string, mixed> */
    public array $properties;

    /**
     * @param mixed $id The engine's own edge id
     * @param string $type The edge type/label
     * @param mixed $fromId The source node id
     * @param mixed $toId The target node id
     * @param array<string, mixed>|null $properties Edge properties
     */
    public function __construct(mixed $id, string $type, mixed $fromId, mixed $toId, ?array $properties = null)
    {
        $this->id = $id;
        $this->type = $type;
        $this->fromId = $fromId;
        $this->toId = $toId;
        $this->properties = $properties ?? [];
    }

    /**
     * @return array{id: mixed, type: string, from: mixed, to: mixed, properties: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'from' => $this->fromId,
            'to' => $this->toId,
            'properties' => $this->properties,
        ];
    }

    /**
     * @return array{id: mixed, type: string, from: mixed, to: mixed, properties: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
