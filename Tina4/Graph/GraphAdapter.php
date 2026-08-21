<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

/**
 * The one surface every graph engine implements — the GraphAdapter contract.
 *
 * Mirrors the relational DatabaseAdapter, but is an ABSTRACT CLASS rather than a
 * bare interface (matching the Python master): each method is a RAISING STUB, so
 * a driver that does not override one fails LOUDLY, naming itself and the method,
 * instead of silently doing nothing. Method names use PHP camelCase and map 1:1
 * to Python's snake_case (addNode -> add_node, getError -> get_error).
 *
 * The portable core (addNode/addEdge/getNode/updateNode/deleteNode/neighbors/
 * traverse) returns engine-neutral GraphNode/GraphEdge; the raw pass-through
 * (query/execute) sends the engine's native dialect and returns a GraphResult.
 */
abstract class GraphAdapter
{
    /** Cause of the last failed operation, or null. */
    protected ?string $lastError = null;

    // -- portable node/edge/traverse core ----------------------------------

    /**
     * Create a vertex and return the stored GraphNode.
     *
     * @param string $label The node label
     * @param array<string, mixed>|null $properties The node properties
     * @return GraphNode|null The created node, or null if nothing was returned
     */
    public function addNode(string $label, ?array $properties = null): ?GraphNode
    {
        throw new \BadMethodCallException(static::class . ' does not implement addNode');
    }

    /**
     * Link two existing nodes and return the stored GraphEdge.
     *
     * @param mixed $fromId The source node id
     * @param mixed $toId The target node id
     * @param string $type The edge type/label
     * @param array<string, mixed>|null $properties The edge properties
     * @return GraphEdge|null The created edge, or null if nothing was returned
     */
    public function addEdge(mixed $fromId, mixed $toId, string $type, ?array $properties = null): ?GraphEdge
    {
        throw new \BadMethodCallException(static::class . ' does not implement addEdge');
    }

    /**
     * Return the stored GraphNode for an id, or null for a miss (not an error).
     *
     * @param mixed $nodeId The node id
     * @return GraphNode|null
     */
    public function getNode(mixed $nodeId): ?GraphNode
    {
        throw new \BadMethodCallException(static::class . ' does not implement getNode');
    }

    /**
     * Merge properties into a node and return the updated GraphNode.
     *
     * @param mixed $nodeId The node id
     * @param array<string, mixed> $properties The properties to merge
     * @return GraphNode|null
     */
    public function updateNode(mixed $nodeId, array $properties): ?GraphNode
    {
        throw new \BadMethodCallException(static::class . ' does not implement updateNode');
    }

    /**
     * Remove a node (and its edges).
     *
     * @param mixed $nodeId The node id
     * @return bool True on success
     */
    public function deleteNode(mixed $nodeId): bool
    {
        throw new \BadMethodCallException(static::class . ' does not implement deleteNode');
    }

    /**
     * Return the directly-connected nodes for a direction and optional edge type.
     *
     * @param mixed $nodeId The node id
     * @param string $direction One of "out", "in", "both"
     * @param string|null $edgeType Optional edge type filter
     * @param int $limit Max nodes to return
     * @return array<int, GraphNode>
     */
    public function neighbors(mixed $nodeId, string $direction = 'both', ?string $edgeType = null, int $limit = 100): array
    {
        throw new \BadMethodCallException(static::class . ' does not implement neighbors');
    }

    /**
     * Return the nodes reachable within `depth` hops from the start.
     *
     * @param mixed $startId The start node id
     * @param int $depth Max hops
     * @param string $direction One of "out", "in", "both"
     * @param string|null $edgeType Optional edge type filter
     * @param int $limit Max nodes to return
     * @return array<int, GraphNode>
     */
    public function traverse(mixed $startId, int $depth = 1, string $direction = 'both', ?string $edgeType = null, int $limit = 1000): array
    {
        throw new \BadMethodCallException(static::class . ' does not implement traverse');
    }

    // -- raw pass-through (engine-native dialect) --------------------------

    /**
     * Run a read in the engine's native language with bound params.
     *
     * @param string $text The engine's native read statement (GQL/Cypher/AQL)
     * @param array<string, mixed>|null $params Bound parameters
     * @return GraphResult
     */
    public function query(string $text, ?array $params = null): GraphResult
    {
        throw new \BadMethodCallException(static::class . ' does not implement query');
    }

    /**
     * Run a write in the engine's native language with bound params.
     *
     * @param string $text The engine's native write statement (GQL/Cypher/AQL)
     * @param array<string, mixed>|null $params Bound parameters
     * @return GraphResult
     */
    public function execute(string $text, ?array $params = null): GraphResult
    {
        throw new \BadMethodCallException(static::class . ' does not implement execute');
    }

    // -- lifecycle ---------------------------------------------------------

    /**
     * Release the connection.
     */
    public function close(): void
    {
        throw new \BadMethodCallException(static::class . ' does not implement close');
    }

    /**
     * Cause of the last failed operation, or null.
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }
}
