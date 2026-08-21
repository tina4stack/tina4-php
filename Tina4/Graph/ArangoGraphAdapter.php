<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Graph;

use ArangoDBClient\Collection;
use ArangoDBClient\CollectionHandler;
use ArangoDBClient\Connection;
use ArangoDBClient\ConnectionOptions;
use ArangoDBClient\Exception as ArangoException;
use ArangoDBClient\Statement;

/**
 * ArangoDB graph adapter — the document/AQL engine behind the same surface.
 *
 * Wraps the community `triagens/arangodb` driver (an OPTIONAL dependency,
 * referenced only inside this class so referencing Tina4\Graph stays driver-free).
 * The driver is pure PHP over HTTP, so it needs no C extension. Arango is a
 * document store, not a labelled-property graph, so the portable core maps onto
 * ONE vertex collection + ONE edge collection: a node's `labels` and an edge's
 * `type` are stored as document fields, ids are Arango `_id` handles (e.g.
 * `tina4_nodes/123`), and traversal uses AQL `FOR v IN 1..N OUTBOUND ...`. Raw
 * query()/execute() take AQL directly.
 *
 * The exact statements mirror the Python master
 * (tina4_python/graph/adapters/arango.py) and are verified live on the lab.
 */
class ArangoGraphAdapter extends GraphAdapter
{
    public const VERTEX_COLLECTION = 'tina4_nodes';
    public const EDGE_COLLECTION = 'tina4_edges';

    /** @var array<int, string> Internal keys dropped when returning node/edge props. */
    private const RESERVED = ['_id', '_key', '_rev', '_from', '_to', '_labels', '_type'];

    private GraphUrl $url;

    private Connection $connection;

    /**
     * @param GraphUrl $graphUrl The parsed graph URL
     * @param string $username Fallback username when the URL omits one
     * @param string $password Fallback password when the URL omits one
     */
    public function __construct(GraphUrl $graphUrl, string $username = '', string $password = '')
    {
        $this->url = $graphUrl;

        $user = $graphUrl->username ?? ($username !== '' ? $username : 'root');
        $pass = $graphUrl->password ?? ($password !== '' ? $password : '');
        $database = $graphUrl->graph ?? '_system';

        $scheme = $graphUrl->useTls ? 'ssl' : 'tcp';
        $endpoint = $scheme . '://' . $graphUrl->host . ':' . ($graphUrl->port ?? 8529);

        $options = [
            ConnectionOptions::OPTION_ENDPOINT => $endpoint,
            ConnectionOptions::OPTION_AUTH_TYPE => 'Basic',
            ConnectionOptions::OPTION_AUTH_USER => $user,
            ConnectionOptions::OPTION_AUTH_PASSWD => $pass,
            ConnectionOptions::OPTION_DATABASE => $database,
        ];

        // null (unbounded, <= 0) leaves the driver's own default timeout.
        $timeout = GraphConnectTimeout::resolveSeconds();
        if ($timeout !== null) {
            $options[ConnectionOptions::OPTION_CONNECT_TIMEOUT] = (int) ceil($timeout);
            $options[ConnectionOptions::OPTION_TIMEOUT] = $timeout;
        }

        try {
            $this->connection = new Connection($options);
            // Force the (lazy) connection and ensure the two collections exist.
            $handler = new CollectionHandler($this->connection);
            if (!$handler->has(self::VERTEX_COLLECTION)) {
                $handler->create(self::VERTEX_COLLECTION);
            }
            if (!$handler->has(self::EDGE_COLLECTION)) {
                $edge = new Collection(self::EDGE_COLLECTION);
                $edge->setType(Collection::TYPE_EDGE);
                $handler->create($edge);
            }
        } catch (ArangoException $exception) {
            $this->lastError = $exception->getMessage();
            throw $this->connectOrError($exception);
        }
    }

    // -- connection + raw pass-through -------------------------------------

    /**
     * Run one AQL statement with bind vars, translating driver errors.
     *
     * @param string $query The AQL statement
     * @param array<string, mixed>|null $bind Bind variables
     * @return array<int, mixed> The flat result rows
     * @throws GraphConnectTimeout When the host is unreachable within the bound
     * @throws GraphError When the statement fails
     */
    private function aql(string $query, ?array $bind = null): array
    {
        try {
            $statement = new Statement($this->connection, [
                'query' => $query,
                'bindVars' => $bind ?? [],
                '_flat' => true,
            ]);

            return $statement->execute()->getAll();
        } catch (ArangoException $exception) {
            $this->lastError = $exception->getMessage();
            throw $this->connectOrError($exception);
        }
    }

    /**
     * Run a raw AQL read with bind vars and return a GraphResult.
     *
     * @param string $text The AQL read statement
     * @param array<string, mixed>|null $params Bind variables
     */
    public function query(string $text, ?array $params = null): GraphResult
    {
        $rows = $this->aql($text, $params);
        $columns = ($rows !== [] && is_array($rows[0])) ? array_keys($rows[0]) : [];

        /** @var array<int, array<string, mixed>> $records */
        $records = array_map(static fn (mixed $row): array => is_array($row) ? $row : ['value' => $row], $rows);

        return new GraphResult($records, $columns);
    }

    /**
     * Run a raw AQL write with bind vars and return a GraphResult.
     *
     * @param string $text The AQL write statement
     * @param array<string, mixed>|null $params Bind variables
     */
    public function execute(string $text, ?array $params = null): GraphResult
    {
        return $this->query($text, $params);
    }

    // -- portable node/edge/traverse core (AQL) ----------------------------

    public function addNode(string $label, ?array $properties = null): ?GraphNode
    {
        $doc = $properties ?? [];
        $doc['_labels'] = [$label];
        $rows = $this->aql('INSERT @doc INTO ' . self::VERTEX_COLLECTION . ' RETURN NEW', ['doc' => $doc]);

        return isset($rows[0]) ? $this->nodeFromDoc($rows[0]) : null;
    }

    public function addEdge(mixed $fromId, mixed $toId, string $type, ?array $properties = null): ?GraphEdge
    {
        $doc = $properties ?? [];
        $doc['_from'] = $fromId;
        $doc['_to'] = $toId;
        $doc['_type'] = $type;
        $rows = $this->aql('INSERT @doc INTO ' . self::EDGE_COLLECTION . ' RETURN NEW', ['doc' => $doc]);

        if (!isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        $row = $rows[0];

        return new GraphEdge(
            $row['_id'] ?? null,
            (string) ($row['_type'] ?? $type),
            $row['_from'] ?? null,
            $row['_to'] ?? null,
            $this->cleanProps($row)
        );
    }

    public function getNode(mixed $nodeId): ?GraphNode
    {
        $rows = $this->aql('RETURN DOCUMENT(@id)', ['id' => $nodeId]);

        return (isset($rows[0]) && is_array($rows[0])) ? $this->nodeFromDoc($rows[0]) : null;
    }

    public function updateNode(mixed $nodeId, array $properties): ?GraphNode
    {
        $rows = $this->aql(
            'UPDATE PARSE_IDENTIFIER(@id).key WITH @props IN ' . self::VERTEX_COLLECTION . ' RETURN NEW',
            ['id' => $nodeId, 'props' => $properties]
        );

        return (isset($rows[0]) && is_array($rows[0])) ? $this->nodeFromDoc($rows[0]) : null;
    }

    public function deleteNode(mixed $nodeId): bool
    {
        // Remove any edges touching the node first, so a re-read is a clean miss.
        $this->aql(
            'FOR e IN ' . self::EDGE_COLLECTION . ' FILTER e._from == @id OR e._to == @id '
            . 'REMOVE e IN ' . self::EDGE_COLLECTION,
            ['id' => $nodeId]
        );
        $this->aql(
            'REMOVE PARSE_IDENTIFIER(@id).key IN ' . self::VERTEX_COLLECTION,
            ['id' => $nodeId]
        );

        return true;
    }

    public function neighbors(mixed $nodeId, string $direction = 'both', ?string $edgeType = null, int $limit = 100): array
    {
        $dir = $this->arangoDirection($direction);
        $typeFilter = $edgeType !== null ? 'FILTER e._type == @etype ' : '';
        $bind = ['start' => $nodeId, 'limit' => $limit];
        if ($edgeType !== null) {
            $bind['etype'] = $edgeType;
        }
        $rows = $this->aql(
            'FOR v, e IN 1..1 ' . $dir . ' @start ' . self::EDGE_COLLECTION . ' '
            . $typeFilter . 'LIMIT @limit RETURN DISTINCT v',
            $bind
        );

        return array_map(fn (mixed $doc): ?GraphNode => is_array($doc) ? $this->nodeFromDoc($doc) : null, $rows);
    }

    public function traverse(mixed $startId, int $depth = 1, string $direction = 'both', ?string $edgeType = null, int $limit = 1000): array
    {
        $dir = $this->arangoDirection($direction);
        $typeFilter = $edgeType !== null ? 'FILTER e._type == @etype ' : '';
        $bind = ['start' => $startId, 'limit' => $limit];
        if ($edgeType !== null) {
            $bind['etype'] = $edgeType;
        }
        $rows = $this->aql(
            'FOR v, e IN 1..' . (int) $depth . ' ' . $dir . ' @start ' . self::EDGE_COLLECTION . ' '
            . $typeFilter . 'LIMIT @limit RETURN DISTINCT v',
            $bind
        );

        return array_map(fn (mixed $doc): ?GraphNode => is_array($doc) ? $this->nodeFromDoc($doc) : null, $rows);
    }

    public function close(): void
    {
        // The driver's HTTP connection is closed on destruction; dropping the
        // reference releases it. Nothing explicit to do.
    }

    // -- helpers -----------------------------------------------------------

    /**
     * Map a portable direction to Arango's AQL keyword.
     *
     * @param string $direction One of "out", "in", "both"
     */
    private function arangoDirection(string $direction): string
    {
        return match ($direction) {
            'out' => 'OUTBOUND',
            'in' => 'INBOUND',
            default => 'ANY',
        };
    }

    /**
     * Build a GraphNode from an Arango vertex document array.
     *
     * @param array<string, mixed> $doc
     */
    private function nodeFromDoc(array $doc): GraphNode
    {
        /** @var array<int, string> $labels */
        $labels = $doc['_labels'] ?? [];

        return new GraphNode($doc['_id'] ?? null, $labels, $this->cleanProps($doc));
    }

    /**
     * Drop Arango's internal keys, leaving only user properties.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function cleanProps(array $doc): array
    {
        return array_diff_key($doc, array_flip(self::RESERVED));
    }

    /**
     * Map a driver connect/timeout error to GraphConnectTimeout, others to GraphError.
     *
     * @param ArangoException $exception The driver exception
     */
    private function connectOrError(ArangoException $exception): GraphError|GraphConnectTimeout
    {
        $text = strtolower($exception->getMessage());
        foreach (['timed out', 'timeout', 'connection', 'could not connect', 'max retries', 'refused', 'unreachable'] as $needle) {
            if (str_contains($text, $needle)) {
                return new GraphConnectTimeout(
                    'Graph connect to ' . $this->url->host . ':' . $this->url->port . ' timed out '
                    . '(TINA4_GRAPH_CONNECT_TIMEOUT). Raise it if the server is simply slow, or set '
                    . 'it to 0 to wait indefinitely.',
                    0,
                    $exception
                );
            }
        }

        return new GraphError($exception->getMessage(), 0, $exception);
    }
}
