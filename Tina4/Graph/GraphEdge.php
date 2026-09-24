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
