<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Contract suite for the graph data layer (Feature 139) — against REAL engines.
 *
 * No mocks. Every live case runs a real connection and real round-trips, and is
 * PARAMETERISED over every provisioned engine (provider substitutability, exactly
 * like the relational engine matrix): Ultipa, Neo4j, Memgraph, ArangoDB. Each
 * engine's URL comes from its own TINA4_TEST_<ENGINE>_URL; an engine whose URL is
 * unset/unreachable, or whose optional driver is not installed, is skipped. Case
 * names match fixtures/graph_contract.json and the Python master
 * (tina4-python/tests/test_graph.py).
 *
 * Only the raw-query dialect and the cleanup differ per engine (GQL/Cypher vs AQL)
 * — the portable node/edge/traverse surface is identical everywhere, which is the
 * whole point of the layer.
 *
 * Ultipa note: edge ids need EDGE_ID enabled on the graph
 * (`ALTER GRAPH <g> SET EDGE_ID ENABLED`) — a one-time per-graph setting the lab
 * provisions.
 */

namespace Tina4;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Graph\GraphAdapter;
use Tina4\Graph\GraphConnectTimeout;
use Tina4\Graph\GraphDatabase;
use Tina4\Graph\GraphEdge;
use Tina4\Graph\GraphError;
use Tina4\Graph\GraphNode;
use Tina4\Graph\GraphResult;
use Tina4\Graph\GraphUrl;

class GraphTest extends TestCase
{
    private const LABEL = 'T4GraphContractTestPhp';
    private const ULTIPA_DRIVER_CLASS = 'Tina4\\Ultipa\\Client';

    /** A Cypher/GQL read that finds a node by name, and its label cleanup. */
    private const CYPHER_RAW = 'MATCH (n:`' . self::LABEL . '`) WHERE n.name = $nm RETURN n.name AS name';
    private const CYPHER_CLEAN = 'MATCH (n:`' . self::LABEL . '`) DETACH DELETE n';

    /** The AQL equivalents for Arango's document/collection model. */
    private const AQL_RAW = 'FOR n IN tina4_nodes FILTER n.name == @nm RETURN {name: n.name}';
    private const AQL_CLEAN = "FOR n IN tina4_nodes FILTER '" . self::LABEL . "' IN n._labels REMOVE n IN tina4_nodes";

    private ?GraphAdapter $graph = null;
    private ?string $cleanStatement = null;

    /**
     * Per-engine wiring: the env var holding its URL, the optional driver class
     * whose presence gates the live cases, and the native raw-read + cleanup
     * statements (Cypher/GQL for ultipa/neo4j/memgraph, AQL for arango).
     *
     * @return array<string, array{env: string, driver: string, raw: string, clean: string}>
     */
    private static function engineConfig(): array
    {
        return [
            'ultipa' => [
                'env' => 'TINA4_TEST_ULTIPA_URL',
                'driver' => self::ULTIPA_DRIVER_CLASS,
                'raw' => self::CYPHER_RAW,
                'clean' => self::CYPHER_CLEAN,
            ],
            'neo4j' => [
                'env' => 'TINA4_TEST_NEO4J_URL',
                'driver' => 'Laudis\\Neo4j\\ClientBuilder',
                'raw' => self::CYPHER_RAW,
                'clean' => self::CYPHER_CLEAN,
            ],
            'memgraph' => [
                'env' => 'TINA4_TEST_MEMGRAPH_URL',
                'driver' => 'Laudis\\Neo4j\\ClientBuilder',
                'raw' => self::CYPHER_RAW,
                'clean' => self::CYPHER_CLEAN,
            ],
            'arango' => [
                'env' => 'TINA4_TEST_ARANGO_URL',
                'driver' => 'ArangoDBClient\\Connection',
                'raw' => self::AQL_RAW,
                'clean' => self::AQL_CLEAN,
            ],
        ];
    }

    /**
     * The engine names the live matrix runs over.
     *
     * @return array<int, array{0: string}>
     */
    public static function engines(): array
    {
        return array_map(static fn (string $name): array => [$name], array_keys(self::engineConfig()));
    }

    protected function tearDown(): void
    {
        if ($this->graph !== null) {
            try {
                if ($this->cleanStatement !== null) {
                    $this->graph->execute($this->cleanStatement);
                }
            } catch (\Throwable) {
                // best-effort cleanup
            }
            $this->graph->close();
            $this->graph = null;
            $this->cleanStatement = null;
        }
    }

    // ── driver-optional + URL selection run WITHOUT a live engine ────────────

    /**
     * graph-driver-optional: the core loads with NO engine driver; opening a
     * connection whose driver is absent raises an actionable install error naming
     * the package and command.
     */
    public function testGraphDriverOptional(): void
    {
        // The core imports with no engine driver present.
        foreach ([
            GraphUrl::class, GraphNode::class, GraphEdge::class, GraphResult::class,
            GraphAdapter::class, GraphDatabase::class, GraphConnectTimeout::class, GraphError::class,
        ] as $coreClass) {
            $this->assertTrue(class_exists($coreClass), "{$coreClass} must load driver-free");
        }

        // Point the ultipa engine at an adapter whose driver class is absent — the
        // same seam the Python master uses by mutating _ENGINE_ADAPTERS. create()
        // must surface the install error, not a bare "class not found".
        GraphDatabase::registerEngine(
            'ultipa',
            AbsentDriverGraphAdapter::class,
            'tina4stack/ultipa',
            'composer require tina4stack/ultipa'
        );
        try {
            $this->expectException(GraphError::class);
            $this->expectExceptionMessage('tina4stack/ultipa');
            GraphDatabase::create('ultipa://h:60061/g');
        } finally {
            GraphDatabase::registerEngine('ultipa');   // restore the real adapter
        }
    }

    /**
     * graph-connect-by-url: the URL scheme picks the adapter; an unknown scheme is
     * rejected. neo4j/memgraph/bolt all resolve to the single "bolt" engine.
     */
    public function testGraphConnectByUrlSelectsAdapter(): void
    {
        $this->assertSame('ultipa', (new GraphUrl('ultipa://h:60061/g'))->engine);
        $this->assertSame('bolt', (new GraphUrl('neo4j://h/db'))->engine);
        $this->assertSame('bolt', (new GraphUrl('memgraph://h/db'))->engine);
        $this->assertSame('bolt', (new GraphUrl('bolt://h/db'))->engine);
        $this->assertSame('arango', (new GraphUrl('arango://h/db'))->engine);

        $this->expectException(\InvalidArgumentException::class);
        new GraphUrl('mysql://h/db');
    }

    // ── the portable core + raw pass-through, per LIVE engine ────────────────

    #[DataProvider('engines')]
    public function testGraphConnectByUrlLive(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $this->assertInstanceOf(GraphAdapter::class, $graph);
    }

    #[DataProvider('engines')]
    public function testGraphAddNode(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $node = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $this->assertInstanceOf(GraphNode::class, $node);
        $this->assertNotNull($node->id);   // an integer 0 is a valid id (Neo4j/Memgraph)
        $this->assertContains(self::LABEL, $node->labels);
        $this->assertSame('Ada', $node->properties['name']);
        $this->assertSame(36, $node->properties['age']);
    }

    #[DataProvider('engines')]
    public function testGraphAddEdge(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada']);
        $b = $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $edge = $graph->addEdge($a->id, $b->id, 'KNOWS', ['since' => 2020]);
        $this->assertInstanceOf(GraphEdge::class, $edge);
        $this->assertNotNull($edge->id);   // an integer 0 is a valid id (Neo4j/Memgraph)
        $this->assertSame('KNOWS', $edge->type);
        $this->assertSame($a->id, $edge->fromId);
        $this->assertSame($b->id, $edge->toId);
        $this->assertSame(2020, $edge->properties['since']);
    }

    #[DataProvider('engines')]
    public function testGraphGetNodeRoundtripAndMiss(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $got = $graph->getNode($a->id);
        $this->assertSame('Ada', $got->properties['name']);
        $this->assertSame(36, $got->properties['age']);
        // A miss (a deleted id) is not an error.
        $tmp = $graph->addNode(self::LABEL, ['x' => 1]);
        $graph->deleteNode($tmp->id);
        $this->assertNull($graph->getNode($tmp->id));
    }

    #[DataProvider('engines')]
    public function testGraphUpdateDeleteNode(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $graph->updateNode($a->id, ['name' => 'Ada Lovelace', 'city' => 'London']);
        $updated = $graph->getNode($a->id);
        $this->assertSame('Ada Lovelace', $updated->properties['name']);
        $this->assertSame('London', $updated->properties['city']);
        $this->assertSame(36, $updated->properties['age']);   // merge, not replace
        $graph->deleteNode($a->id);
        $this->assertNull($graph->getNode($a->id));
    }

    #[DataProvider('engines')]
    public function testGraphNeighbors(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada']);
        $b = $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $graph->addEdge($a->id, $b->id, 'KNOWS', []);
        $out = array_map(fn (GraphNode $n) => $n->id, $graph->neighbors($a->id, 'out', 'KNOWS'));
        $this->assertContains($b->id, $out);
        $this->assertNotContains($a->id, $out);
        // An unmatched filter returns empty, not an error.
        $this->assertSame([], $graph->neighbors($a->id, 'both', 'NOPE'));
    }

    #[DataProvider('engines')]
    public function testGraphTraverseDepth(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $a = $graph->addNode(self::LABEL, ['name' => 'A']);
        $b = $graph->addNode(self::LABEL, ['name' => 'B']);
        $c = $graph->addNode(self::LABEL, ['name' => 'C']);
        $graph->addEdge($a->id, $b->id, 'KNOWS', []);
        $graph->addEdge($b->id, $c->id, 'KNOWS', []);
        $reached = array_map(fn (GraphNode $n) => $n->id, $graph->traverse($a->id, 2, 'out', 'KNOWS'));
        $this->assertContains($b->id, $reached);   // 2 hops reach both
        $this->assertContains($c->id, $reached);
    }

    #[DataProvider('engines')]
    public function testGraphRawQueryBoundParams(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $result = $graph->query(self::engineConfig()[$engine]['raw'], ['nm' => 'Bob']);
        $this->assertInstanceOf(GraphResult::class, $result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertSame('Bob', $result->records[0]['name']);
    }

    #[DataProvider('engines')]
    public function testGraphWriteFailsLoud(string $engine): void
    {
        $graph = $this->liveGraph($engine);
        try {
            $graph->execute('THIS IS NOT A VALID STATEMENT');
            $this->fail('a bad raw statement must raise GraphError, never return');
        } catch (GraphError) {
            $this->assertNotNull($graph->getError());
        }
    }

    /**
     * graph-connect-timeout: an unreachable host throws GraphConnectTimeout within
     * the bound, naming host and port (mirrors the relational connect-timeout
     * contract). Uses a black-hole IP — no live server needed — but needs the
     * Ultipa driver + ext-grpc, so it skips when they are absent.
     */
    public function testGraphConnectTimeout(): void
    {
        if (!class_exists(self::ULTIPA_DRIVER_CLASS)) {
            $this->markTestSkipped('tina4stack/ultipa driver not installed');
        }

        putenv('TINA4_GRAPH_CONNECT_TIMEOUT=2');
        $_ENV['TINA4_GRAPH_CONNECT_TIMEOUT'] = '2';
        try {
            $started = microtime(true);
            try {
                // 10.255.255.1 completes no handshake — a real black hole, not a refusal.
                GraphDatabase::create('ultipa://admin:x@10.255.255.1:60071/default')->getNode('x');
                $this->fail('an unreachable host must throw GraphConnectTimeout');
            } catch (GraphConnectTimeout $exception) {
                $elapsed = microtime(true) - $started;
                $this->assertLessThan(6, $elapsed);
                $this->assertStringContainsString('10.255.255.1', $exception->getMessage());
                $this->assertStringContainsString('60071', $exception->getMessage());
            }
        } finally {
            putenv('TINA4_GRAPH_CONNECT_TIMEOUT');
            unset($_ENV['TINA4_GRAPH_CONNECT_TIMEOUT']);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A connected adapter for the named engine on a clean slate for the test
     * label, or a skip when its URL is unset/unreachable or its driver is absent.
     *
     * @param string $engine One of ultipa, neo4j, memgraph, arango
     */
    private function liveGraph(string $engine): GraphAdapter
    {
        $cfg = self::engineConfig()[$engine];

        $env = getenv($cfg['env']);
        $url = ($env === false || $env === '') ? null : $env;
        if ($url === null || !$this->reachable($url)) {
            $this->markTestSkipped("live {$engine} not configured/reachable (set {$cfg['env']})");
        }
        if (!class_exists($cfg['driver'])) {
            $this->markTestSkipped("{$engine} driver not installed ({$cfg['driver']})");
        }

        $graph = GraphDatabase::create($url);
        $this->graph = $graph;
        $this->cleanStatement = $cfg['clean'];
        $graph->execute($cfg['clean']);   // start on a clean slate for this label

        return $graph;
    }

    private function reachable(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if ($host === null || $port === null) {
            return false;
        }
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 2.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}

/**
 * A GraphAdapter whose constructor references a driver class that does not exist,
 * so GraphDatabase::create() must translate the resulting "class not found" Error
 * into the actionable install error. The graph-driver-optional simulation.
 */
class AbsentDriverGraphAdapter extends GraphAdapter
{
    public function __construct(GraphUrl $graphUrl, string $username = '', string $password = '')
    {
        // This class is intentionally absent — instantiating it raises the same
        // "Class ... not found" Error a genuinely-missing driver would.
        new \Tina4\Ultipa\_DefinitelyAbsent\Driver();
    }
}
