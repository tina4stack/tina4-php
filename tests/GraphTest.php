<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Contract suite for the graph data layer (Feature 139) — against a REAL engine.
 *
 * No mocks. Every live case runs a real connection and real round-trips against a
 * provisioned graph engine. Ultipa community edition is the first engine; the URL
 * comes from TINA4_TEST_ULTIPA_URL (the live cases skip when it is unset/
 * unreachable, exactly like the relational live-database tests). Case names match
 * fixtures/graph_contract.json and the Python master (tests/test_graph.py).
 *
 * Ultipa note: edge ids need EDGE_ID enabled on the graph
 * (`ALTER GRAPH <g> SET EDGE_ID ENABLED`) — a one-time per-graph setting the lab
 * provisions.
 */

namespace Tina4;

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
    private const DRIVER_CLASS = 'Tina4\\Ultipa\\Client';

    private ?string $url = null;
    private ?GraphAdapter $graph = null;

    protected function setUp(): void
    {
        $env = getenv('TINA4_TEST_ULTIPA_URL');
        $this->url = ($env === false || $env === '') ? null : $env;
    }

    protected function tearDown(): void
    {
        if ($this->graph !== null) {
            try {
                $this->graph->execute('MATCH (n:`' . self::LABEL . '`) DETACH DELETE n');
            } catch (\Throwable) {
                // best-effort cleanup
            }
            $this->graph->close();
            $this->graph = null;
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
     * rejected.
     */
    public function testGraphConnectByUrlSelectsAdapter(): void
    {
        $this->assertSame('ultipa', (new GraphUrl('ultipa://h:60061/g'))->engine);
        $this->assertSame('bolt', (new GraphUrl('neo4j://h/db'))->engine);
        $this->assertSame('arango', (new GraphUrl('arango://h/db'))->engine);

        $this->expectException(\InvalidArgumentException::class);
        new GraphUrl('mysql://h/db');
    }

    // ── the portable core + raw pass-through, against LIVE Ultipa ─────────────

    public function testGraphConnectByUrlLive(): void
    {
        $graph = $this->requireLiveGraph();
        $this->assertSame('UltipaGraphAdapter', (new \ReflectionClass($graph))->getShortName());
    }

    public function testGraphAddNode(): void
    {
        $graph = $this->requireLiveGraph();
        $node = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $this->assertInstanceOf(GraphNode::class, $node);
        $this->assertNotEmpty($node->id);
        $this->assertContains(self::LABEL, $node->labels);
        $this->assertSame('Ada', $node->properties['name']);
    }

    public function testGraphAddEdge(): void
    {
        $graph = $this->requireLiveGraph();
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada']);
        $b = $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $edge = $graph->addEdge($a->id, $b->id, 'KNOWS', ['since' => 2020]);
        $this->assertInstanceOf(GraphEdge::class, $edge);
        $this->assertNotEmpty($edge->id);
        $this->assertSame('KNOWS', $edge->type);
        $this->assertSame($a->id, $edge->fromId);
        $this->assertSame($b->id, $edge->toId);
        $this->assertSame(2020, $edge->properties['since']);
    }

    public function testGraphGetNodeRoundtripAndMiss(): void
    {
        $graph = $this->requireLiveGraph();
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $got = $graph->getNode($a->id);
        $this->assertSame('Ada', $got->properties['name']);
        $this->assertSame(36, $got->properties['age']);
        // A miss is not an error.
        $this->assertNull($graph->getNode('no-such-id'));
    }

    public function testGraphUpdateDeleteNode(): void
    {
        $graph = $this->requireLiveGraph();
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada', 'age' => 36]);
        $graph->updateNode($a->id, ['name' => 'Ada Lovelace', 'city' => 'London']);
        $updated = $graph->getNode($a->id);
        $this->assertSame('Ada Lovelace', $updated->properties['name']);
        $this->assertSame('London', $updated->properties['city']);
        $this->assertSame(36, $updated->properties['age']);   // merge, not replace
        $graph->deleteNode($a->id);
        $this->assertNull($graph->getNode($a->id));
    }

    public function testGraphNeighbors(): void
    {
        $graph = $this->requireLiveGraph();
        $a = $graph->addNode(self::LABEL, ['name' => 'Ada']);
        $b = $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $graph->addEdge($a->id, $b->id, 'KNOWS', []);
        $out = array_map(fn (GraphNode $n) => $n->id, $graph->neighbors($a->id, 'out', 'KNOWS'));
        $this->assertContains($b->id, $out);
        $this->assertNotContains($a->id, $out);
        // An unmatched filter returns empty, not an error.
        $this->assertSame([], $graph->neighbors($a->id, 'both', 'NOPE'));
    }

    public function testGraphTraverseDepth(): void
    {
        $graph = $this->requireLiveGraph();
        $a = $graph->addNode(self::LABEL, ['name' => 'A']);
        $b = $graph->addNode(self::LABEL, ['name' => 'B']);
        $c = $graph->addNode(self::LABEL, ['name' => 'C']);
        $graph->addEdge($a->id, $b->id, 'KNOWS', []);
        $graph->addEdge($b->id, $c->id, 'KNOWS', []);
        $reached = array_map(fn (GraphNode $n) => $n->id, $graph->traverse($a->id, 2, 'out', 'KNOWS'));
        $this->assertContains($b->id, $reached);   // 2 hops reach both
        $this->assertContains($c->id, $reached);
    }

    public function testGraphRawQueryBoundParams(): void
    {
        $graph = $this->requireLiveGraph();
        $graph->addNode(self::LABEL, ['name' => 'Bob']);
        $result = $graph->query(
            'MATCH (n:`' . self::LABEL . '`) WHERE n.name = $nm RETURN n.name AS name',
            ['nm' => 'Bob']
        );
        $this->assertInstanceOf(GraphResult::class, $result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertSame('Bob', $result->records[0]['name']);
    }

    public function testGraphWriteFailsLoud(): void
    {
        $graph = $this->requireLiveGraph();
        try {
            $graph->execute('THIS IS NOT GQL');
            $this->fail('a bad raw statement must raise GraphError, never return');
        } catch (GraphError) {
            $this->assertNotNull($graph->getError());
        }
    }

    /**
     * graph-connect-timeout: an unreachable host throws GraphConnectTimeout within
     * the bound, naming host and port (mirrors the relational connect-timeout
     * contract). Uses a black-hole IP — no live server needed — but needs the
     * driver + ext-grpc, so it skips when they are absent.
     */
    public function testGraphConnectTimeout(): void
    {
        if (!class_exists(self::DRIVER_CLASS)) {
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
     * A connected Ultipa adapter on a clean slate for the test label, or a skip
     * when the live engine is not configured/reachable or the driver is absent.
     */
    private function requireLiveGraph(): GraphAdapter
    {
        if ($this->url === null || !$this->reachable($this->url)) {
            $this->markTestSkipped('live Ultipa not configured/reachable (set TINA4_TEST_ULTIPA_URL)');
        }
        if (!class_exists(self::DRIVER_CLASS)) {
            $this->markTestSkipped('tina4stack/ultipa driver not installed');
        }

        $graph = GraphDatabase::create($this->url);
        $graph->execute('MATCH (n:`' . self::LABEL . '`) DETACH DELETE n');
        $this->graph = $graph;

        return $graph;
    }

    private function reachable(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? 60061;
        if ($host === null) {
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
