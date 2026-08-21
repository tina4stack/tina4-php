<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

use Tina4\Ultipa\Client;
use Tina4\Ultipa\ConnectError;
use Tina4\Ultipa\Result;
use Tina4\Ultipa\UltipaError;

/**
 * Ultipa graph adapter — the portable core built in GQL over the tina4stack/ultipa
 * driver.
 *
 * The driver (`Tina4\Ultipa\Client`) is an OPTIONAL dependency, referenced only
 * inside this class, so referencing Tina4\Graph stays driver-free. The portable
 * node/edge/traverse surface is expressed in Ultipa GQL on top of the driver's
 * query()/execute(); the raw query()/execute() send GQL straight through.
 *
 * GQL note: the exact statements are verified against the live Ultipa community
 * edition on the lab (no mocks). Ultipa node ids are UUID strings and edge ids need
 * EDGE_ID enabled on the graph; we echo back whatever id(n)/id(e) returns without
 * assuming a type. READS go through query() (readOnly), WRITES through execute()
 * (readOnly=false) — Ultipa rejects a write issued over a read-only request.
 */
class UltipaGraphAdapter extends GraphAdapter
{
    private GraphUrl $url;

    private Client $client;

    /**
     * @param GraphUrl $graphUrl The parsed graph URL
     * @param string $username Fallback username when the URL omits one
     * @param string $password Fallback password when the URL omits one
     */
    public function __construct(GraphUrl $graphUrl, string $username = '', string $password = '')
    {
        $this->url = $graphUrl;

        $timeout = GraphConnectTimeout::resolveSeconds();

        $this->client = new Client(
            host: $graphUrl->host,
            port: $graphUrl->port ?? Client::DEFAULT_PORT,
            username: $graphUrl->username ?? ($username !== '' ? $username : null),
            password: $graphUrl->password ?? ($password !== '' ? $password : null),
            graph: $graphUrl->graph,
            // null (unbounded, <= 0) maps to the driver's own 0 == no bound, the
            // same as the Python master's `resolve_graph_connect_timeout() or 0`.
            connectTimeout: $timeout ?? 0.0,
            useTls: $graphUrl->useTls,
        );
    }

    // -- connection + raw pass-through -------------------------------------

    /**
     * Connect (bounded) then run one GQL statement, translating driver errors.
     *
     * @param string $gql The GQL statement
     * @param array<string, mixed>|null $params Bound parameters
     * @param bool $readOnly True for a read (query), false for a write (execute)
     * @return Result The driver result
     * @throws GraphConnectTimeout When the connect exceeds the bound
     * @throws GraphError When the statement fails
     */
    private function run(string $gql, ?array $params, bool $readOnly): Result
    {
        try {
            $this->client->connect();
        } catch (ConnectError $exception) {
            $this->lastError = $exception->getMessage();
            throw new GraphConnectTimeout(
                'Graph connect to ' . $this->url->host . ':' . $this->url->port . ' timed out after '
                . sprintf('%.1f', $exception->elapsed) . 's (TINA4_GRAPH_CONNECT_TIMEOUT). '
                . 'Raise TINA4_GRAPH_CONNECT_TIMEOUT if the server is simply slow, or set it to 0 '
                . 'to wait indefinitely.',
                0,
                $exception
            );
        }

        try {
            return $this->client->query($gql, $params, null, $readOnly);
        } catch (UltipaError $exception) {
            $this->lastError = $exception->getMessage();
            throw new GraphError($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Run a raw GQL read with bound params and return a GraphResult.
     *
     * @param string $text The GQL read statement
     * @param array<string, mixed>|null $params Bound parameters
     */
    public function query(string $text, ?array $params = null): GraphResult
    {
        $result = $this->run($text, $params, true);

        return new GraphResult($result->dicts(), $result->columns);
    }

    /**
     * Run a raw GQL write with bound params and return a GraphResult.
     *
     * @param string $text The GQL write statement
     * @param array<string, mixed>|null $params Bound parameters
     */
    public function execute(string $text, ?array $params = null): GraphResult
    {
        $result = $this->run($text, $params, false);

        return new GraphResult($result->dicts(), $result->columns);
    }

    // -- portable node/edge/traverse core (GQL) ----------------------------

    public function addNode(string $label, ?array $properties = null): ?GraphNode
    {
        [$propMap, $params] = $this->propClause($properties);
        $gql = 'INSERT (n:`' . $label . '` ' . $propMap . ') '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($gql, $params, false)->dicts();

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function addEdge(mixed $fromId, mixed $toId, string $type, ?array $properties = null): ?GraphEdge
    {
        // id(e) requires EDGE_ID enabled on the Ultipa graph
        // (`ALTER GRAPH <g> SET EDGE_ID ENABLED`), a one-time per-graph setting.
        [$propMap, $params] = $this->propClause($properties);
        $params['from_id'] = $fromId;
        $params['to_id'] = $toId;
        $gql = 'MATCH (a), (b) WHERE id(a) = $from_id AND id(b) = $to_id '
            . 'INSERT (a)-[e:`' . $type . '` ' . $propMap . ']->(b) '
            . 'RETURN id(e) AS id, type(e) AS type, id(a) AS f, id(b) AS t, properties(e) AS props';
        $rows = $this->run($gql, $params, false)->dicts();

        if (!isset($rows[0])) {
            return null;
        }
        $row = $rows[0];

        return new GraphEdge(
            $row['id'] ?? null,
            (string) ($row['type'] ?? $type),
            $row['f'] ?? null,
            $row['t'] ?? null,
            $row['props'] ?? []
        );
    }

    public function getNode(mixed $nodeId): ?GraphNode
    {
        $gql = 'MATCH (n) WHERE id(n) = $id '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($gql, ['id' => $nodeId], true)->dicts();

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function updateNode(mixed $nodeId, array $properties): ?GraphNode
    {
        $sets = [];
        $params = [];
        foreach ($properties as $key => $value) {
            $sets[] = 'n.' . $key . ' = $p_' . $key;
            $params['p_' . $key] = $value;
        }
        $params['id'] = $nodeId;
        $gql = 'MATCH (n) WHERE id(n) = $id SET ' . implode(', ', $sets) . ' '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($gql, $params, false)->dicts();

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function deleteNode(mixed $nodeId): bool
    {
        $gql = 'MATCH (n) WHERE id(n) = $id DETACH DELETE n';
        $this->run($gql, ['id' => $nodeId], false);

        return true;
    }

    public function neighbors(mixed $nodeId, string $direction = 'both', ?string $edgeType = null, int $limit = 100): array
    {
        $edge = $edgeType !== null ? ':`' . $edgeType . '`' : '';
        $pattern = match ($direction) {
            'out' => '(n)-[' . $edge . ']->(m)',
            'in' => '(n)<-[' . $edge . ']-(m)',
            default => '(n)-[' . $edge . ']-(m)',
        };
        $gql = 'MATCH ' . $pattern . ' WHERE id(n) = $id '
            . 'RETURN DISTINCT id(m) AS id, labels(m) AS labels, properties(m) AS props '
            . 'LIMIT ' . (int) $limit;
        $rows = $this->run($gql, ['id' => $nodeId], true)->dicts();

        return array_map(fn (array $row): ?GraphNode => $this->nodeFromRow($row), $rows);
    }

    public function traverse(mixed $startId, int $depth = 1, string $direction = 'both', ?string $edgeType = null, int $limit = 1000): array
    {
        // Ultipa GQL uses the ISO quantified-path form `-[]->{1,N}`, NOT Cypher's
        // `-[*1..N]->` (a parse error on gqldb).
        $edge = $edgeType !== null ? ':`' . $edgeType . '`' : '';
        $quant = '{1,' . (int) $depth . '}';
        $pattern = match ($direction) {
            'out' => '(n)-[' . $edge . ']->' . $quant . '(m)',
            'in' => '(n)<-[' . $edge . ']-' . $quant . '(m)',
            default => '(n)-[' . $edge . ']-' . $quant . '(m)',
        };
        $gql = 'MATCH ' . $pattern . ' WHERE id(n) = $start '
            . 'RETURN DISTINCT id(m) AS id, labels(m) AS labels, properties(m) AS props '
            . 'LIMIT ' . (int) $limit;
        $rows = $this->run($gql, ['start' => $startId], true)->dicts();

        return array_map(fn (array $row): ?GraphNode => $this->nodeFromRow($row), $rows);
    }

    public function close(): void
    {
        $this->client->close();
    }

    // -- helpers -----------------------------------------------------------

    /**
     * Build a GQL property map `{k1: $p_k1, ...}` plus the bound param dict.
     *
     * Params are BOUND (never interpolated), matching the relational ?-placeholder
     * rule. Keys are namespaced (`p_`) so they never collide with an id param.
     *
     * @param array<string, mixed>|null $properties
     * @return array{0: string, 1: array<string, mixed>} The map clause and its params
     */
    private function propClause(?array $properties): array
    {
        $properties = $properties ?? [];
        if ($properties === []) {
            return ['{}', []];
        }

        $pairs = [];
        $params = [];
        foreach ($properties as $key => $value) {
            $pairs[] = $key . ': $p_' . $key;
            $params['p_' . $key] = $value;
        }

        return ['{' . implode(', ', $pairs) . '}', $params];
    }

    /**
     * Build a GraphNode from an id/labels/props result row.
     *
     * @param array<string, mixed>|null $row
     */
    private function nodeFromRow(?array $row): ?GraphNode
    {
        if ($row === null) {
            return null;
        }

        /** @var array<int, string> $labels */
        $labels = $row['labels'] ?? [];
        /** @var array<string, mixed> $props */
        $props = $row['props'] ?? [];

        return new GraphNode($row['id'] ?? null, $labels, $props);
    }
}
