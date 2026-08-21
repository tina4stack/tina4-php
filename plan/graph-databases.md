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
- [x] `tests/GraphTest.php` — 11 contract cases, real/no-mock, gated on TINA4_TEST_ULTIPA_URL
- [x] composer.json: `Tina4\Graph\` autoload + suggest `tina4stack/ultipa`

## Parity
| Feature | Python | PHP | Ruby | Node |
|---------|--------|-----|------|------|
| graph layer | done | done | owed | owed |

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
- (none logged)

## Commits
- (this commit  Tina4/Graph + tests/GraphTest.php + composer autoload/suggest + plan note)

## Proof
- Lab: PHP 8.3.6 + ext-grpc 1.82, published `tina4stack/ultipa` v0.1.0, live Ultipa
  `ultipa://admin:***@192.168.88.99:60071/default` (graph `default`, EDGE_ID on).
- `vendor/bin/phpunit tests/GraphTest.php` => OK (12 tests, 45 assertions, 0 failures,
  0 skipped). Unique label `T4GraphContractTestPhp`, cleaned before+after each test
  (0 residual nodes verified).
- Windows PHP 8.4.8 (driver absent): 2 driver-free cases pass, 10 skip cleanly.

## Status: Complete (PHP + engine Ultipa). Ruby/Node still owed (parity feature).
