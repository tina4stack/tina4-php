# Task: Port the graph data layer (Feature 139) to PHP

Outcome: `Tina4\Graph` mirrors `Tina4\Database` — a URL-selected graph factory,
neutral node/edge/result shapes, a raising adapter contract, a connect-timeout
resolver, and an Ultipa adapter wrapping the `tina4stack/ultipa` driver. Identical
external behaviour to the PROVEN Python reference
(`tina4-python/tina4_python/graph/`). Real no-mock tests against live Ultipa on the
lab.

## Scope
- [x] Read Python reference + tests + ADR-0059/feature 139 + fixture
- [x] Read PHP `Tina4/Database/` idiom + ultipa-php driver API
- [x] `GraphUrl` (scheme->engine, default ports, TLS, creds, fromEnv)
- [x] Neutral shapes `GraphNode` / `GraphEdge` / `GraphResult`
- [x] `GraphAdapter` interface (raising stubs) + `GraphError` + `GraphConnectTimeout`
- [x] `TINA4_GRAPH_CONNECT_TIMEOUT` resolver (default 10; <=0 unbounded)
- [x] `GraphDatabase::create` / `::fromEnv` (lazy driver, actionable install error)
- [x] `UltipaGraphAdapter` wrapping `Tina4\Ultipa\Client` (GQL core, VERBATIM statements)
- [x] `BoltGraphAdapter` wrapping `laudis/neo4j-php-client` (Cypher core, `[*1..N]`) — Neo4j AND Memgraph
- [x] `ArangoGraphAdapter` wrapping `triagens/arangodb` (AQL core, tina4_nodes/tina4_edges collections)
- [x] Register `bolt` + `arango` engines in `GraphDatabase::DEFAULT_ENGINE_ADAPTERS`
- [x] `tests/GraphTest.php` — provider matrix (ultipa/neo4j/memgraph/arango), real/no-mock, per-engine raw+clean
- [x] composer.json: `Tina4\Graph\` autoload + suggest `tina4stack/ultipa`, `laudis/neo4j-php-client`, `triagens/arangodb`

## Parity
| Feature | Python | PHP | Ruby | Node |
|---------|--------|-----|------|------|
| graph layer (ultipa) | done | done | owed | owed |
| graph layer (bolt: neo4j+memgraph) | done | done | owed | owed |
| graph layer (arango) | done | done | owed | owed |

## Tests (real, no mocks, positive + negative — 11 cases matching Python)
- [x] graph-connect-by-url (scheme->adapter, unknown scheme rejected)
- [x] graph-add-node
- [x] graph-add-edge
- [x] graph-get-node (roundtrip + miss->null)
- [x] graph-update-delete-node (merge, then remove)
- [x] graph-neighbors (direction/edge-type filter, unmatched->empty)
- [x] graph-traverse-depth (GQL quantified path {1,N})
- [x] graph-raw-query (bound params)
- [x] graph-write-fails-loud (bad GQL throws, getError set)
- [x] graph-driver-optional (core loads driver-free; missing driver -> install error)
- [x] graph-connect-timeout (black-hole host throws GraphConnectTimeout naming host/port)

## Bugs
- [x] Bolt: empty `$props` PHP array serialised as a Bolt List, not a Map — Neo4j/
  Memgraph rejected `CREATE (…) $props` with "expected Map … but was List". Fixed by
  binding `new Laudis\Neo4j\Types\CypherMap($props ?? [])` in addNode/addEdge/updateNode.
- [x] Test: `assertNotEmpty($edge->id)` failed on Neo4j edge id `0` (a valid id). Changed
  the node/edge id assertions to `assertNotNull` (matches the Python master's `is not None`).

## Commits
- (prior 58ade52d  Tina4/Graph core + UltipaGraphAdapter + tests + composer autoload/suggest)
- (this commit  BoltGraphAdapter + ArangoGraphAdapter + GraphDatabase engine registration +
  provider-matrix GraphTest + composer suggest + plan note)

## Proof — Ultipa (prior)
- Lab PHP 8.3.6 + ext-grpc, live Ultipa; 12 tests, 45 assertions, 0 failures, 0 skipped.

## Proof — Bolt + Arango (this commit)
- Lab PHP 8.3.6, staged work dir, `composer require laudis/neo4j-php-client:3.6.0
  triagens/arangodb:3.8.0` (both pure-PHP over sockets/HTTP, no C extension).
- Live: Neo4j `bolt://neo4j:***@192.168.88.99:7687`, Memgraph `bolt://192.168.88.99:7688`,
  ArangoDB 3.12.10 `arango://root:***@192.168.88.99:8529/_system`.
- `vendor/bin/phpunit tests/GraphTest.php` => 39 tests, 103 assertions, 0 failures.
  Per engine: Neo4j 9/9, Memgraph 9/9, Arango 9/9 (1 ⚠ carrying the arango driver's own
  PHP 8.3 deprecations — vendor code, not Tina4). 10 skips = the Ultipa engine (driver not
  installed in this bolt/arango work dir) + the ultipa-only connect-timeout case.
- Cypher/AQL statements are VERBATIM from the Python master; the List→Map bug proves the
  matrix is a real gate (red before the CypherMap fix, green after). 0 residual nodes on
  all three engines after the run (unique label `T4GraphContractTestPhp`, cleaned each test).
- Windows PHP 8.4.8 (drivers absent): 2 driver-free cases pass, 37 skip cleanly, 0 deprecations.

## Status: Complete (PHP + engines Ultipa, Neo4j, Memgraph, ArangoDB). Ruby/Node still owed.
