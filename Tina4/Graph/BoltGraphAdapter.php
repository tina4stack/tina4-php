<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SessionConfiguration;
use Laudis\Neo4j\Exception\Neo4jException;
use Laudis\Neo4j\Types\CypherMap;

/**
 * Bolt graph adapter — Neo4j AND Memgraph (both speak Bolt + Cypher).
 *
 * Wraps the community `laudis/neo4j-php-client` driver (an OPTIONAL dependency,
 * referenced only inside this class so referencing Tina4\Graph stays driver-free).
 * Neo4j and Memgraph share this ONE adapter; the URL scheme only picks the engine
 * label and the default port. The driver is pure PHP over sockets (needs no C
 * extension), so it serves both engines from the same wire protocol.
 *
 * Cypher note (verified live against Neo4j + Memgraph on the lab): `id(n)` is the
 * portable node/edge id (an integer on both — Neo4j's `elementId` is Neo4j-only,
 * Memgraph has no `elementId`); variable-length traversal is Cypher's `[*1..N]`
 * (the OPPOSITE of Ultipa's GQL `{1,N}`); `SET n += $props` merges. The exact
 * statements mirror the Python master (tina4_python/graph/adapters/bolt.py).
 */
class BoltGraphAdapter extends GraphAdapter
{
    private GraphUrl $url;

    private ClientInterface $client;

    private ?string $database;

    /**
     * @param GraphUrl $graphUrl The parsed graph URL
     * @param string $username Fallback username when the URL omits one
     * @param string $password Fallback password when the URL omits one
     */
    public function __construct(GraphUrl $graphUrl, string $username = '', string $password = '')
    {
        $this->url = $graphUrl;
        $this->database = $graphUrl->graph;

        $user = $graphUrl->username ?? ($username !== '' ? $username : 'neo4j');
        $pass = $graphUrl->password ?? ($password !== '' ? $password : '');

        $scheme = $graphUrl->useTls ? 'bolt+s' : 'bolt';
        $uri = $scheme . '://' . $graphUrl->host . ':' . ($graphUrl->port ?? 7687);

        // null (unbounded, <= 0) leaves the driver's own default socket timeout.
        $timeout = GraphConnectTimeout::resolveSeconds();

        $this->client = ClientBuilder::create()
            ->withDriver('bolt', $uri, Authenticate::basic($user, $pass), $timeout)
            ->withDefaultDriver('bolt')
            ->build();
    }

    // -- connection + raw pass-through -------------------------------------

    /**
     * Run one Cypher statement with bound params, translating driver errors.
     *
     * @param string $cypher The Cypher statement
     * @param array<string, mixed>|null $params Bound parameters
     * @return array<int, array<string, mixed>> The plain associative result rows
     * @throws GraphConnectTimeout When the host is unreachable within the bound
     * @throws GraphError When the statement fails
     */
    private function run(string $cypher, ?array $params = null): array
    {
        try {
            $result = $this->session()->run($cypher, $params ?? []);
        } catch (Neo4jException $exception) {
            $this->lastError = $exception->getMessage();
            throw new GraphError($exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            // An unreachable host surfaces here as a socket/connection failure.
            $this->lastError = $exception->getMessage();
            if ($this->looksLikeConnectFailure($exception)) {
                throw new GraphConnectTimeout(
                    'Graph connect to ' . $this->url->host . ':' . $this->url->port . ' timed out '
                    . '(TINA4_GRAPH_CONNECT_TIMEOUT). Raise it if the server is simply slow, or set '
                    . 'it to 0 to wait indefinitely.',
                    0,
                    $exception
                );
            }
            throw new GraphError($exception->getMessage(), 0, $exception);
        }

        $rows = [];
        foreach ($result as $record) {
            $rows[] = $this->toPlain($record);
        }

        return $rows;
    }

    /**
     * Run a raw Cypher read with bound params and return a GraphResult.
     *
     * @param string $text The Cypher read statement
     * @param array<string, mixed>|null $params Bound parameters
     */
    public function query(string $text, ?array $params = null): GraphResult
    {
        $rows = $this->run($text, $params);
        $columns = $rows !== [] ? array_keys($rows[0]) : [];

        return new GraphResult($rows, $columns);
    }

    /**
     * Run a raw Cypher write with bound params and return a GraphResult.
     *
     * @param string $text The Cypher write statement
     * @param array<string, mixed>|null $params Bound parameters
     */
    public function execute(string $text, ?array $params = null): GraphResult
    {
        return $this->query($text, $params);
    }

    // -- portable node/edge/traverse core (Cypher) -------------------------

    public function addNode(string $label, ?array $properties = null): ?GraphNode
    {
        $cypher = 'CREATE (n:`' . $label . '` $props) '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($cypher, ['props' => new CypherMap($properties ?? [])]);

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function addEdge(mixed $fromId, mixed $toId, string $type, ?array $properties = null): ?GraphEdge
    {
        $cypher = 'MATCH (a), (b) WHERE id(a) = $from_id AND id(b) = $to_id '
            . 'CREATE (a)-[e:`' . $type . '` $props]->(b) '
            . 'RETURN id(e) AS id, type(e) AS type, id(a) AS f, id(b) AS t, properties(e) AS props';
        $rows = $this->run($cypher, [
            'from_id' => $fromId,
            'to_id' => $toId,
            'props' => new CypherMap($properties ?? []),
        ]);

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
        $cypher = 'MATCH (n) WHERE id(n) = $id '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($cypher, ['id' => $nodeId]);

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function updateNode(mixed $nodeId, array $properties): ?GraphNode
    {
        $cypher = 'MATCH (n) WHERE id(n) = $id SET n += $props '
            . 'RETURN id(n) AS id, labels(n) AS labels, properties(n) AS props';
        $rows = $this->run($cypher, ['id' => $nodeId, 'props' => new CypherMap($properties)]);

        return isset($rows[0]) ? $this->nodeFromRow($rows[0]) : null;
    }

    public function deleteNode(mixed $nodeId): bool
    {
        $this->run('MATCH (n) WHERE id(n) = $id DETACH DELETE n', ['id' => $nodeId]);

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
        $cypher = 'MATCH ' . $pattern . ' WHERE id(n) = $id '
            . 'RETURN DISTINCT id(m) AS id, labels(m) AS labels, properties(m) AS props '
            . 'LIMIT ' . (int) $limit;
        $rows = $this->run($cypher, ['id' => $nodeId]);

        return array_map(fn (array $row): ?GraphNode => $this->nodeFromRow($row), $rows);
    }

    public function traverse(mixed $startId, int $depth = 1, string $direction = 'both', ?string $edgeType = null, int $limit = 1000): array
    {
        // Cypher variable-length path `[*1..N]` — Neo4j AND Memgraph.
        $edge = $edgeType !== null ? ':`' . $edgeType . '`' : '';
        $quant = '*1..' . (int) $depth;
        $pattern = match ($direction) {
            'out' => '(n)-[' . $edge . $quant . ']->(m)',
            'in' => '(n)<-[' . $edge . $quant . ']-(m)',
            default => '(n)-[' . $edge . $quant . ']-(m)',
        };
        $cypher = 'MATCH ' . $pattern . ' WHERE id(n) = $start '
            . 'RETURN DISTINCT id(m) AS id, labels(m) AS labels, properties(m) AS props '
            . 'LIMIT ' . (int) $limit;
        $rows = $this->run($cypher, ['start' => $startId]);

        return array_map(fn (array $row): ?GraphNode => $this->nodeFromRow($row), $rows);
    }

    public function close(): void
    {
        // The laudis client holds pooled sockets closed on destruction; there is no
        // explicit close on the client surface, so dropping the reference suffices.
    }

    // -- helpers -----------------------------------------------------------

    /**
     * A Bolt session bound to the target database (default when the URL omits one).
     */
    private function session(): object
    {
        $config = SessionConfiguration::default();
        if ($this->database !== null && $this->database !== '') {
            $config = $config->withDatabase($this->database);
        }

        return $this->client->getDriver('bolt')->createSession($config);
    }

    /**
     * Recursively convert a laudis CypherMap/CypherList (or nested structure) into
     * plain PHP arrays/scalars, so a GraphNode/GraphResult never holds driver types.
     *
     * @param mixed $value A driver value (CypherMap, CypherList, scalar, or array)
     * @return mixed The plain PHP equivalent
     */
    private function toPlain(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->toPlain($item), $value);
        }

        return $value;
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

    /**
     * Whether a thrown error looks like an unreachable-host/connect failure rather
     * than a query error, so it maps to GraphConnectTimeout.
     *
     * @param \Throwable $exception The caught error
     */
    private function looksLikeConnectFailure(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        foreach (['timed out', 'timeout', 'connection', 'could not connect', 'no connection', 'unreachable', 'refused'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
