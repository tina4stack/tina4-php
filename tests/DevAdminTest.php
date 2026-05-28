<?php

// MessageLog and RequestInspector are defined in DevAdmin.php alongside DevAdmin
// but PSR-4 cannot autoload them individually, so we force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\MessageLog;
use Tina4\RequestInspector;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

class DevAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        MessageLog::reset();
        RequestInspector::reset();
    }

    protected function tearDown(): void
    {
        ErrorTracker::reset();
        Router::clear();
        MessageLog::reset();
        RequestInspector::reset();
        // Supervisor transport is a class-level static — tests that stub
        // it MUST reset, but tearDown belt-and-braces in case one forgets.
        DevAdmin::resetSupervisorTransport();
        putenv('TINA4_SUPERVISOR_URL');
    }

    // ── Registration ────────────────────────────────────────────

    public function testRegisterAddsDevRoutes(): void
    {
        DevAdmin::register();
        $routes = Router::getRoutes();
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/__dev', $patterns);
    }

    public function testRegisterAddsStatusEndpoint(): void
    {
        DevAdmin::register();
        $routes = Router::getRoutes();
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/__dev/api/status', $patterns);
    }

    public function testRegisterAddsRoutesEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/routes', $patterns);
    }

    public function testRegisterAddsMessagesEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/messages', $patterns);
    }

    public function testRegisterAddsRequestsEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/requests', $patterns);
    }

    public function testRegisterAddsMailboxEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/mailbox', $patterns);
    }

    public function testRegisterAddsSystemEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        $this->assertContains('/__dev/api/system', $patterns);
    }

    public function testRegisterAddsDuplicateDevRoute(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::getRoutes(), 'pattern');
        // Both /__dev and /__dev/ are registered but Router normalizes trailing slash
        // so both show up as /__dev. Verify at least two GET routes for /__dev exist.
        $devRoutes = array_filter(Router::getRoutes(), fn($r) => $r['pattern'] === '/__dev' && $r['method'] === 'GET');
        $this->assertGreaterThanOrEqual(2, count($devRoutes));
    }

    public function testRegisterRouteCount(): void
    {
        DevAdmin::register();
        // DevAdmin registers many routes -- at least 15
        $this->assertGreaterThanOrEqual(15, Router::count());
    }

    // ── MessageLog ──────────────────────────────────────────────

    public function testMessageLogEmpty(): void
    {
        $msgs = MessageLog::get();
        $this->assertIsArray($msgs);
        $this->assertCount(0, $msgs);
    }

    public function testMessageLogSingleEntry(): void
    {
        MessageLog::log('auth', 'info', 'User logged in');
        $msgs = MessageLog::get();
        $this->assertCount(1, $msgs);
        $this->assertSame('auth', $msgs[0]['category']);
        $this->assertSame('info', $msgs[0]['level']);
        $this->assertSame('User logged in', $msgs[0]['message']);
    }

    public function testMessageLogHasTimestamp(): void
    {
        MessageLog::log('route', 'debug', 'Test');
        $msgs = MessageLog::get();
        $this->assertArrayHasKey('timestamp', $msgs[0]);
        $this->assertNotEmpty($msgs[0]['timestamp']);
    }

    public function testMessageLogHasId(): void
    {
        MessageLog::log('route', 'debug', 'Test');
        $msgs = MessageLog::get();
        $this->assertArrayHasKey('id', $msgs[0]);
        $this->assertNotEmpty($msgs[0]['id']);
    }

    public function testMessageLogFilterByCategory(): void
    {
        MessageLog::log('auth', 'info', 'Login');
        MessageLog::log('queue', 'info', 'Job done');
        MessageLog::log('auth', 'error', 'Failed');
        $authMsgs = MessageLog::get('auth');
        $this->assertCount(2, $authMsgs);
        foreach ($authMsgs as $m) {
            $this->assertSame('auth', $m['category']);
        }
    }

    public function testMessageLogNewestFirst(): void
    {
        MessageLog::log('test', 'info', 'First');
        MessageLog::log('test', 'info', 'Second');
        $msgs = MessageLog::get();
        $this->assertSame('Second', $msgs[0]['message']);
        $this->assertSame('First', $msgs[1]['message']);
    }

    public function testMessageLogClearAll(): void
    {
        MessageLog::log('auth', 'info', 'Msg 1');
        MessageLog::log('queue', 'info', 'Msg 2');
        MessageLog::clear();
        $this->assertCount(0, MessageLog::get());
    }

    public function testMessageLogClearByCategory(): void
    {
        MessageLog::log('auth', 'info', 'Keep');
        MessageLog::log('queue', 'info', 'Remove');
        MessageLog::clear('queue');
        $msgs = MessageLog::get();
        $this->assertCount(1, $msgs);
        $this->assertSame('auth', $msgs[0]['category']);
    }

    public function testMessageLogCount(): void
    {
        MessageLog::log('auth', 'info', 'Msg 1');
        MessageLog::log('auth', 'error', 'Msg 2');
        MessageLog::log('queue', 'info', 'Msg 3');
        $counts = MessageLog::count();
        $this->assertSame(3, $counts['total']);
        $this->assertSame(2, $counts['auth']);
        $this->assertSame(1, $counts['queue']);
    }

    public function testMessageLogCountEmpty(): void
    {
        $counts = MessageLog::count();
        $this->assertSame(0, $counts['total']);
    }

    public function testMessageLogReset(): void
    {
        MessageLog::log('test', 'info', 'Before reset');
        MessageLog::reset();
        $this->assertCount(0, MessageLog::get());
    }

    public function testMessageLogMaxMessages(): void
    {
        for ($i = 0; $i < 510; $i++) {
            MessageLog::log('test', 'info', "Message {$i}");
        }
        $msgs = MessageLog::get();
        $this->assertLessThanOrEqual(500, count($msgs));
    }

    // ── RequestInspector ────────────────────────────────────────

    public function testRequestInspectorEmpty(): void
    {
        $requests = RequestInspector::get();
        $this->assertIsArray($requests);
        $this->assertCount(0, $requests);
    }

    public function testRequestInspectorCapture(): void
    {
        RequestInspector::capture('GET', '/api/users', 200, 12.5);
        $requests = RequestInspector::get();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('/api/users', $requests[0]['path']);
        $this->assertSame(200, $requests[0]['status']);
        $this->assertSame(12.5, $requests[0]['duration_ms']);
    }

    public function testRequestInspectorNewestFirst(): void
    {
        RequestInspector::capture('GET', '/first', 200, 10);
        RequestInspector::capture('POST', '/second', 201, 20);
        $requests = RequestInspector::get();
        $this->assertSame('/second', $requests[0]['path']);
        $this->assertSame('/first', $requests[1]['path']);
    }

    public function testRequestInspectorLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            RequestInspector::capture('GET', "/path/{$i}", 200, 5);
        }
        $requests = RequestInspector::get(3);
        $this->assertCount(3, $requests);
    }

    public function testRequestInspectorHasTimestamp(): void
    {
        RequestInspector::capture('GET', '/test', 200, 5);
        $requests = RequestInspector::get();
        $this->assertArrayHasKey('timestamp', $requests[0]);
    }

    public function testRequestInspectorHasId(): void
    {
        RequestInspector::capture('GET', '/test', 200, 5);
        $requests = RequestInspector::get();
        $this->assertArrayHasKey('id', $requests[0]);
    }

    public function testRequestInspectorUppercasesMethod(): void
    {
        RequestInspector::capture('post', '/test', 200, 5);
        $requests = RequestInspector::get();
        $this->assertSame('POST', $requests[0]['method']);
    }

    public function testRequestInspectorStats(): void
    {
        RequestInspector::capture('GET', '/a', 200, 10);
        RequestInspector::capture('GET', '/b', 200, 20);
        RequestInspector::capture('GET', '/c', 500, 30);
        $stats = RequestInspector::stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(20.0, $stats['avg_ms']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(30.0, $stats['slowest_ms']);
    }

    public function testRequestInspectorStatsEmpty(): void
    {
        $stats = RequestInspector::stats();
        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['avg_ms']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(0, $stats['slowest_ms']);
    }

    public function testRequestInspectorClear(): void
    {
        RequestInspector::capture('GET', '/test', 200, 5);
        RequestInspector::clear();
        $this->assertCount(0, RequestInspector::get());
    }

    public function testRequestInspectorReset(): void
    {
        RequestInspector::capture('GET', '/test', 200, 5);
        RequestInspector::reset();
        $this->assertCount(0, RequestInspector::get());
    }

    public function testRequestInspectorMaxRequests(): void
    {
        for ($i = 0; $i < 210; $i++) {
            RequestInspector::capture('GET', "/p/{$i}", 200, 1);
        }
        $all = RequestInspector::get(300);
        $this->assertLessThanOrEqual(200, count($all));
    }

    public function testRequestInspectorStatsCountsErrors(): void
    {
        RequestInspector::capture('GET', '/ok', 200, 5);
        RequestInspector::capture('GET', '/bad', 400, 5);
        RequestInspector::capture('GET', '/err', 500, 5);
        RequestInspector::capture('GET', '/not', 404, 5);
        $stats = RequestInspector::stats();
        $this->assertSame(3, $stats['errors']); // 400, 500, 404
    }

    // ── Dev Toolbar ───────────────────────────────────────────

    public function testToolbarContainsElements(): void
    {
        $html = DevAdmin::renderToolbar('GET', '/hello', '/hello', 'abc123', 5);
        $this->assertStringContainsString('tina4-dev-toolbar', $html);
        $this->assertStringContainsString('Tina4 v', $html);
        $this->assertStringContainsString('GET', $html);
        $this->assertStringContainsString('/hello', $html);
        $this->assertStringContainsString('abc123', $html);
        $this->assertStringContainsString('5 routes', $html);
        $this->assertStringContainsString('PHP ' . PHP_VERSION, $html);
    }

    public function testToolbarContainsDevLink(): void
    {
        $html = DevAdmin::renderToolbar();
        $this->assertStringContainsString('/__dev', $html);
        $this->assertStringContainsString('Dashboard', $html);
    }

    public function testToolbarNoExternalDeps(): void
    {
        $html = strtolower(DevAdmin::renderToolbar());
        $this->assertStringNotContainsString('cdn.', $html);
    }

    public function testToolbarWithContext(): void
    {
        $html = DevAdmin::renderToolbar('POST', '/api/users', '/api/users/{id}', 'req-456', 10);
        $this->assertStringContainsString('POST', $html);
        $this->assertStringContainsString('/api/users', $html);
        $this->assertStringContainsString('req-456', $html);
        $this->assertStringContainsString('10 routes', $html);
    }

    public function testToolbarShowsPhpVersion(): void
    {
        $html = DevAdmin::renderToolbar();
        $this->assertStringContainsString('PHP', $html);
    }

    // ── Status API: db_tables field ────────────────────────────────

    public function testStatusIncludesDbTables(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE test_status_a (id INTEGER PRIMARY KEY)");
        $db->exec("CREATE TABLE test_status_b (id INTEGER PRIMARY KEY)");
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        // Find the status route handler and call it
        $callback = $this->findRouteCallback('GET', '/__dev/api/status');
        $this->assertNotNull($callback, 'Status route should be registered');

        $request = Request::create('GET', '/__dev/api/status');
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        // db_tables should be an integer >= 2 (we created 2 tables)
        $this->assertArrayHasKey('db_tables', $json);
        $this->assertIsInt($json['db_tables']);
        $this->assertGreaterThanOrEqual(2, $json['db_tables']);

        $db->close();
    }

    // ── Multi-statement SQL execution ──────────────────────────────

    public function testQueryMultiStatement(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/query');
        $this->assertNotNull($callback, 'Query route should be registered');

        // Multi-statement: CREATE + INSERT + INSERT
        $request = Request::create('POST', '/__dev/api/query', body: [
            'query' => "CREATE TABLE test_multi (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO test_multi (id, name) VALUES (1, 'Alice'); INSERT INTO test_multi (id, name) VALUES (2, 'Bob')",
            'type' => 'sql',
        ]);
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);
        $this->assertTrue($json['success'] ?? false, 'Multi-statement should succeed');

        // Verify data was inserted by running a SELECT
        $request2 = Request::create('POST', '/__dev/api/query', body: [
            'query' => 'SELECT * FROM test_multi',
            'type' => 'sql',
        ]);
        $response2 = new Response(true);

        /** @var Response $result2 */
        $result2 = $callback($request2, $response2);
        $json2 = json_decode($result2->getBody(), true);
        $this->assertCount(2, $json2['rows']);

        $db->close();
    }

    // ── Multi-statement rollback on error ──────────────────────────

    public function testQueryMultiStatementRollback(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE test_rb (id INTEGER PRIMARY KEY, name TEXT)");
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/query');
        $this->assertNotNull($callback);

        // Batch with a bad statement — should rollback
        $request = Request::create('POST', '/__dev/api/query', body: [
            'query' => "INSERT INTO test_rb (id, name) VALUES (1, 'Alice'); INSERT INTO nonexistent_table (x) VALUES (1)",
            'type' => 'sql',
        ]);
        $response = new Response(true);

        /** @var Response $result */
        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);
        $this->assertArrayHasKey('error', $json, 'Failed batch should return error');

        $db->close();
    }


    // ── API Handler: routes ──────────────────────────────────────

    public function testRoutesEndpointReturnsRoutes(): void
    {
        DevAdmin::register();
        Router::get('/test/hello', function ($req, $resp) { return $resp('ok'); });

        $callback = $this->findRouteCallback('GET', '/__dev/api/routes');
        $this->assertNotNull($callback, 'Routes API should exist');

        $request = Request::create('GET', '/__dev/api/routes');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertIsArray($json);
        $this->assertNotEmpty($json);
    }

    // ── API Handler: messages ──────────────────────────────────────

    public function testMessagesEndpointReturnsMessages(): void
    {
        MessageLog::log('test', 'info', 'From test');
        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/messages');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/messages');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('messages', $json);
        $this->assertGreaterThanOrEqual(1, count($json['messages']));
    }

    // ── API Handler: requests ──────────────────────────────────────

    public function testRequestsEndpointReturnsData(): void
    {
        RequestInspector::capture('GET', '/test', 200, 5.0);
        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/requests');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/requests');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('requests', $json);
        $this->assertArrayHasKey('stats', $json);
        $this->assertSame(1, $json['stats']['total']);
    }

    // ── API Handler: system ──────────────────────────────────────

    public function testSystemEndpointReturnsPhpVersion(): void
    {
        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/system');
        $this->assertNotNull($callback);

        $request = Request::create('GET', '/__dev/api/system');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('php_version', $json);
    }

    // ── MessageLog: limit parameter ──────────────────────────────

    public function testMessageLogLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            MessageLog::log('test', 'info', "Message {$i}");
        }
        $msgs = MessageLog::get();
        // get() returns all by default — at least 20
        $this->assertGreaterThanOrEqual(20, count($msgs));
    }

    public function testMessageLogStructure(): void
    {
        MessageLog::log('test', 'warn', 'Hello');
        $msg = MessageLog::get()[0];
        $this->assertArrayHasKey('id', $msg);
        $this->assertArrayHasKey('timestamp', $msg);
        $this->assertSame('test', $msg['category']);
        $this->assertSame('warn', $msg['level']);
        $this->assertSame('Hello', $msg['message']);
    }

    // ── ErrorTracker ────────────────────────────────────────────

    public function testErrorTrackerCaptureAndGet(): void
    {
        ErrorTracker::capture('ValueError', 'bad input');
        $entries = ErrorTracker::get();
        $this->assertGreaterThanOrEqual(1, count($entries));
        $this->assertSame('ValueError', $entries[0]['error_type']);
        $this->assertSame('bad input', $entries[0]['message']);
        $this->assertSame(1, $entries[0]['count']);
    }

    public function testErrorTrackerDedupIncrementsCount(): void
    {
        ErrorTracker::capture('ValueError', 'dup error', '', 'test.php', 10);
        ErrorTracker::capture('ValueError', 'dup error', '', 'test.php', 10);
        $entries = ErrorTracker::get();
        // Find the entry with 'dup error'
        $found = array_values(array_filter($entries, fn($e) => $e['message'] === 'dup error'));
        $this->assertCount(1, $found);
        $this->assertSame(2, $found[0]['count']);
    }

    public function testErrorTrackerDifferentErrorsSeparate(): void
    {
        ErrorTracker::capture('ValueError', 'error A', '', 'a.php', 1);
        ErrorTracker::capture('TypeError', 'error B', '', 'b.php', 2);
        $entries = ErrorTracker::get();
        $types = array_column($entries, 'error_type');
        $this->assertContains('ValueError', $types);
        $this->assertContains('TypeError', $types);
    }

    public function testErrorTrackerResolve(): void
    {
        ErrorTracker::capture('RuntimeError', 'crash', '', 'c.php', 5);
        $entries = ErrorTracker::get();
        $id = $entries[0]['id'];

        $result = ErrorTracker::resolve($id);
        $this->assertTrue($result);

        $entries = ErrorTracker::get();
        $found = array_values(array_filter($entries, fn($e) => $e['id'] === $id));
        $this->assertTrue($found[0]['resolved']);
    }

    public function testErrorTrackerResolveNonexistent(): void
    {
        $this->assertFalse(ErrorTracker::resolve('nonexistent_fingerprint'));
    }

    public function testErrorTrackerClearResolved(): void
    {
        ErrorTracker::capture('Error', 'fixed', '', 'f.php', 1);
        ErrorTracker::capture('Error', 'still broken', '', 'g.php', 2);
        $entries = ErrorTracker::get();
        $fixedId = null;
        foreach ($entries as $e) {
            if ($e['message'] === 'fixed') {
                $fixedId = $e['id'];
                break;
            }
        }
        $this->assertNotNull($fixedId);
        ErrorTracker::resolve($fixedId);
        ErrorTracker::clearResolved();

        $entries = ErrorTracker::get();
        foreach ($entries as $e) {
            $this->assertFalse($e['resolved']);
        }
    }

    public function testErrorTrackerUnresolvedCount(): void
    {
        ErrorTracker::capture('Error', 'one', '', 'h.php', 1);
        ErrorTracker::capture('Error', 'two', '', 'i.php', 2);
        $entries = ErrorTracker::get();
        $twoId = null;
        foreach ($entries as $e) {
            if ($e['message'] === 'two') {
                $twoId = $e['id'];
                break;
            }
        }
        ErrorTracker::resolve($twoId);

        $unresolved = ErrorTracker::unresolvedCount();
        $this->assertGreaterThanOrEqual(1, $unresolved);
    }

    public function testErrorTrackerTracebackStored(): void
    {
        ErrorTracker::capture('Error', 'msg', "line 42\nline 43", 'trace.php', 42);
        $entries = ErrorTracker::get();
        $found = array_values(array_filter($entries, fn($e) => $e['message'] === 'msg'));
        $this->assertStringContainsString('line 42', $found[0]['traceback']);
    }

    public function testErrorTrackerHealth(): void
    {
        ErrorTracker::capture('Error', 'one', '', 'a.php', 1);
        ErrorTracker::capture('Error', 'two', '', 'b.php', 2);
        $entries = ErrorTracker::get();
        $twoId = null;
        foreach ($entries as $e) {
            if ($e['message'] === 'two') {
                $twoId = $e['id'];
                break;
            }
        }
        ErrorTracker::resolve($twoId);

        $health = ErrorTracker::health();
        $this->assertSame(2, $health['total']);
        $this->assertSame(1, $health['unresolved']);
        $this->assertSame(1, $health['resolved']);
        $this->assertFalse($health['healthy']);
    }

    public function testErrorTrackerHealthEmpty(): void
    {
        $health = ErrorTracker::health();
        $this->assertTrue($health['healthy']);
        $this->assertSame(0, $health['total']);
    }

    public function testErrorTrackerClearAll(): void
    {
        ErrorTracker::capture('Error', 'one', '', 'a.php', 1);
        ErrorTracker::capture('Error', 'two', '', 'b.php', 2);
        ErrorTracker::clearAll();
        $this->assertCount(0, ErrorTracker::get());
    }

    // ── API Handler: tables ──────────────────────────────────────

    public function testTablesEndpointReturnsTableNames(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE alpha (id INTEGER PRIMARY KEY)");
        $db->exec("CREATE TABLE bravo (id INTEGER PRIMARY KEY)");
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/tables');
        $this->assertNotNull($callback, 'Tables route should be registered');

        $request = Request::create('GET', '/__dev/api/tables');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('tables', $json);
        $this->assertIsArray($json['tables']);
        $this->assertContains('alpha', $json['tables']);
        $this->assertContains('bravo', $json['tables']);

        $db->close();
    }

    // ── API Handler: mtime ──────────────────────────────────────

    public function testMtimeEndpointReturnsMtime(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/mtime');
        $this->assertNotNull($callback, 'Mtime route should be registered');

        $request = Request::create('GET', '/__dev/api/mtime');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('mtime', $json);
        $this->assertIsInt($json['mtime']);

        $db->close();
    }

    // ── Hot-reload parity tests ────────────────────────────────────
    //
    // GET /__dev/api/mtime must return an in-memory counter. It only
    // advances when POST /__dev/api/reload is called. There must be no
    // filesystem scan and no sentinel file under src/. These tests
    // mirror equivalent suites in tina4-python, tina4-ruby, tina4-nodejs.

    private function resetReloadState(): void
    {
        $ref = new \ReflectionClass(DevAdmin::class);
        foreach (['reloadMtime' => 0, 'reloadFile' => '', 'pendingReload' => false] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function callReloadPost(array $body = []): array
    {
        $callback = $this->findRouteCallback('POST', '/__dev/api/reload');
        $this->assertNotNull($callback, 'Reload route should be registered');
        $request = Request::create('POST', '/__dev/api/reload', null, $body);
        $response = new Response(true);
        $result = $callback($request, $response);
        return json_decode($result->getBody(), true);
    }

    private function callMtimeGet(): array
    {
        $callback = $this->findRouteCallback('GET', '/__dev/api/mtime');
        $request = Request::create('GET', '/__dev/api/mtime');
        $response = new Response(true);
        $result = $callback($request, $response);
        return json_decode($result->getBody(), true);
    }

    public function testHotReloadMtimeStartsAtZero(): void
    {
        DevAdmin::register();
        $this->resetReloadState();

        $json = $this->callMtimeGet();
        $this->assertSame(0, $json['mtime']);
        $this->assertSame('', $json['file']);
    }

    public function testHotReloadPostBumpsMtime(): void
    {
        DevAdmin::register();
        $this->resetReloadState();

        $this->assertSame(0, $this->callMtimeGet()['mtime']);

        $before = time();
        $this->callReloadPost(['file' => 'src/routes/home.php', 'type' => 'reload']);
        $after = time();

        $json = $this->callMtimeGet();
        $this->assertGreaterThanOrEqual($before, $json['mtime']);
        $this->assertLessThanOrEqual($after, $json['mtime']);
        $this->assertSame('src/routes/home.php', $json['file']);
    }

    public function testHotReloadSetsPendingReloadFlag(): void
    {
        DevAdmin::register();
        $this->resetReloadState();

        $this->assertFalse(DevAdmin::$pendingReload);
        $this->callReloadPost([]);
        $this->assertTrue(DevAdmin::$pendingReload);
    }

    public function testHotReloadDoesNotWriteSentinelUnderSrc(): void
    {
        // Regression: earlier PHP code touched src/.reload_sentinel,
        // which fed back into the Rust CLI's src/ watcher and caused
        // an endless reload loop. Parity design must not touch the FS.
        DevAdmin::register();
        $this->resetReloadState();

        $sentinel = getcwd() . '/src/.reload_sentinel';
        $sentinelTina = getcwd() . '/.tina4/.reload_sentinel';
        @unlink($sentinel);
        @unlink($sentinelTina);

        $this->callReloadPost(['file' => 'whatever.php']);

        $this->assertFileDoesNotExist($sentinel, 'Reload must not create src/.reload_sentinel');
        $this->assertFileDoesNotExist($sentinelTina, 'Reload must not create .tina4/.reload_sentinel');
    }

    public function testHotReloadMtimeIsMonotonic(): void
    {
        DevAdmin::register();
        $this->resetReloadState();

        $this->callReloadPost(['file' => 'a.php']);
        $m1 = $this->callMtimeGet()['mtime'];
        sleep(1);
        $this->callReloadPost(['file' => 'b.php']);
        $m2 = $this->callMtimeGet()['mtime'];

        $this->assertGreaterThan($m1, $m2, 'Second reload must advance the counter');
        $this->assertSame('b.php', $this->callMtimeGet()['file']);
    }

    // ── API Handler: queue ──────────────────────────────────────

    public function testQueueEndpointReturnsJobs(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/queue');
        $this->assertNotNull($callback, 'Queue route should be registered');

        $request = Request::create('GET', '/__dev/api/queue');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('jobs', $json);
        $this->assertIsArray($json['jobs']);

        $db->close();
    }

    // ── API Handler: broken ──────────────────────────────────────

    public function testBrokenEndpointReturnsErrors(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/broken');
        $this->assertNotNull($callback, 'Broken route should be registered');

        $request = Request::create('GET', '/__dev/api/broken');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        // Python-parity shape: {errors, health} — no `count` (caller derives
        // from `len(errors)`). See /Users/andrevanzuydam/IdeaProjects/tina4-python
        // `_api_broken` for canonical shape.
        $this->assertArrayHasKey('errors', $json);
        $this->assertIsArray($json['errors']);
        $this->assertArrayHasKey('health', $json);
        $this->assertArrayNotHasKey('count', $json);

        $db->close();
    }

    // ── API Handler: websockets ──────────────────────────────────

    public function testWebsocketsEndpointReturnsConnections(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/websockets');
        $this->assertNotNull($callback, 'Websockets route should be registered');

        $request = Request::create('GET', '/__dev/api/websockets');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('connections', $json);
        $this->assertIsArray($json['connections']);
        $this->assertArrayHasKey('count', $json);

        $db->close();
    }

    // ── API Handler: graphql/schema ──────────────────────────────

    public function testGraphqlSchemaEndpointReturnsSchemaAndSdl(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/graphql/schema');
        $this->assertNotNull($callback, 'GraphQL schema route should be registered');

        $request = Request::create('GET', '/__dev/api/graphql/schema');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('schema', $json);
        $this->assertArrayHasKey('sdl', $json);

        $db->close();
    }

    // ── API Handler: messages/clear ──────────────────────────────

    public function testMessagesClearEndpointReturnsOk(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        MessageLog::log('test', 'info', 'To be cleared');
        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/messages/clear');
        $this->assertNotNull($callback, 'Messages clear route should be registered');

        $request = Request::create('POST', '/__dev/api/messages/clear');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('cleared', $json);
        $this->assertTrue($json['cleared']);
        $this->assertCount(0, MessageLog::get());

        $db->close();
    }

    // ── API Handler: requests/clear ──────────────────────────────

    public function testRequestsClearEndpointReturnsOk(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        RequestInspector::capture('GET', '/test', 200, 5.0);
        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/requests/clear');
        $this->assertNotNull($callback, 'Requests clear route should be registered');

        $request = Request::create('POST', '/__dev/api/requests/clear');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('cleared', $json);
        $this->assertTrue($json['cleared']);
        $this->assertCount(0, RequestInspector::get());

        $db->close();
    }

    // ── API Handler: connections ──────────────────────────────────

    public function testConnectionsEndpointReturnsConnectionInfo(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        \Tina4\App::setDatabase($db);

        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/connections');
        $this->assertNotNull($callback, 'Connections route should be registered');

        $request = Request::create('GET', '/__dev/api/connections');
        $response = new Response(true);

        $result = $callback($request, $response);
        $json = json_decode($result->getBody(), true);

        $this->assertArrayHasKey('url', $json);
        $this->assertArrayHasKey('username', $json);
        $this->assertArrayHasKey('password', $json);

        $db->close();
    }

    // ── Supervisor proxy: chat + threads (Python parity) ───────────
    //
    // Mirror Python's `_api_chat` / `_api_threads` / `_api_threads_sub`
    // proxies. We stub `DevAdmin::$supervisorTransport` so tests don't
    // need a live Rust agent — the stub records every outbound call and
    // returns a scripted response. Each test asserts the URL, method,
    // and body that PHP would have hit on the wire, plus the shape of
    // the response the SPA receives back.

    /**
     * Captured outbound supervisor calls from the test stub. We park them on
     * the test instance because returning a reference array from a helper
     * and capturing it by-ref inside the closure rebinds the reference on
     * function return — the closure would write to a phantom array.
     * @var array<int, array{method:string, url:string, body:?string, headers:array}>
     */
    private array $capturedSupervisorCalls = [];

    private function captureSupervisorCalls(array $script = []): void
    {
        // $script: optional [statusCode, body, contentType] per call, in order.
        // Defaults to 200 / "[]" / application/json when the test doesn't care.
        $this->capturedSupervisorCalls = [];
        $index = 0;
        $self = $this;
        DevAdmin::$supervisorTransport = function (string $method, string $url, ?string $body, array $headers) use ($self, &$index, $script): array {
            $self->capturedSupervisorCalls[] = [
                'method'  => $method,
                'url'     => $url,
                'body'    => $body,
                'headers' => $headers,
            ];
            [$status, $payload, $ct] = $script[$index] ?? [200, '[]', 'application/json'];
            $index++;
            return [
                'status'       => $status,
                'content_type' => $ct,
                'error'        => '',
                'chunks'       => function () use ($payload): \Generator { yield $payload; },
            ];
        };
    }

    public function testSupervisorUrlOverride(): void
    {
        // Explicit URL wins over derived ports — matches Python's resolution
        // order in _supervisor_base_url(). Trailing slash gets trimmed so the
        // proxy doesn't end up calling //chat or //threads.
        $previous = getenv('TINA4_SUPERVISOR_URL');
        putenv('TINA4_SUPERVISOR_URL=http://example.com:1234/');
        try {
            $this->assertSame('http://example.com:1234', DevAdmin::supervisorBaseUrl());
        } finally {
            // Restore env so we don't pollute later tests.
            if ($previous === false) {
                putenv('TINA4_SUPERVISOR_URL');
            } else {
                putenv('TINA4_SUPERVISOR_URL=' . $previous);
            }
        }
    }

    public function testSupervisorUrlDefaultsTo9145(): void
    {
        // Bare framework, no env: matches `tina4 agent` standalone default.
        $prev = ['TINA4_SUPERVISOR_URL' => getenv('TINA4_SUPERVISOR_URL'),
                 'TINA4_AGENT_PORT'     => getenv('TINA4_AGENT_PORT'),
                 'PORT'                 => getenv('PORT'),
                 'TINA4_PORT'           => getenv('TINA4_PORT')];
        foreach (array_keys($prev) as $key) putenv($key);
        try {
            $this->assertSame('http://127.0.0.1:9145', DevAdmin::supervisorBaseUrl());
        } finally {
            foreach ($prev as $key => $val) {
                $val === false ? putenv($key) : putenv("$key=$val");
            }
        }
    }

    public function testThreadsListProxiesToSupervisor(): void
    {
        $this->captureSupervisorCalls([
            [200, json_encode(['threads' => [['id' => 'abc', 'title' => 'hello']]]), 'application/json'],
        ]);
        putenv('TINA4_SUPERVISOR_URL=http://agent.test:9145');
        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/threads');
        $this->assertNotNull($callback, 'GET /__dev/api/threads must be registered');

        $request = Request::create('GET', '/__dev/api/threads');
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $captured = $this->capturedSupervisorCalls;
        $this->assertCount(1, $captured, 'Exactly one upstream call');
        $this->assertSame('GET', $captured[0]['method']);
        $this->assertSame('http://agent.test:9145/threads', $captured[0]['url']);
        $this->assertNull($captured[0]['body']);

        $json = json_decode($result->getBody(), true);
        $this->assertArrayHasKey('threads', $json);
        $this->assertSame('abc', $json['threads'][0]['id']);

        DevAdmin::resetSupervisorTransport();
        putenv('TINA4_SUPERVISOR_URL');
    }

    public function testChatProxyForwardsActiveFile(): void
    {
        // The SPA POSTs {message, thread_id?, active_file?} and expects the
        // active_file blob (path / language / content) to reach the Rust
        // agent verbatim — that's what lets the planner see the file the
        // user has open in the editor without an extra round-trip.
        $this->captureSupervisorCalls([
            [200, "event: status\ndata: ok\n\nevent: done\ndata: {}\n\n", 'text/event-stream'],
        ]);
        putenv('TINA4_SUPERVISOR_URL=http://agent.test:9145');
        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/chat');
        $this->assertNotNull($callback, 'POST /__dev/api/chat must be registered');

        $payload = [
            'message'     => 'fix the bug',
            'thread_id'   => 'thread-1',
            'active_file' => [
                'path'     => 'src/foo.php',
                'language' => 'php',
                'content'  => "<?php\necho 'hi';\n",
            ],
        ];
        $request = Request::create('POST', '/__dev/api/chat', body: $payload);
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $captured = $this->capturedSupervisorCalls;
        $this->assertCount(1, $captured);
        $this->assertSame('POST', $captured[0]['method']);
        $this->assertSame('http://agent.test:9145/chat', $captured[0]['url']);

        // Body must be valid JSON and round-trip with the same keys —
        // including active_file. If the proxy drops or rewrites keys,
        // the editor-aware code path breaks silently.
        $decoded = json_decode($captured[0]['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('fix the bug', $decoded['message']);
        $this->assertSame('thread-1', $decoded['thread_id']);
        $this->assertArrayHasKey('active_file', $decoded);
        $this->assertSame('src/foo.php', $decoded['active_file']['path']);
        $this->assertSame("<?php\necho 'hi';\n", $decoded['active_file']['content']);

        // Response is the SSE stream piped through unchanged. In testing
        // mode Response::stream() collects the chunks into the body.
        $body = $result->getBody();
        $this->assertStringContainsString('event: status', $body);
        $this->assertStringContainsString('event: done', $body);

        DevAdmin::resetSupervisorTransport();
        putenv('TINA4_SUPERVISOR_URL');
    }

    public function testThreadsPatchUpdatesUpstream(): void
    {
        // PATCH /threads/{id} is how the SPA archives/renames a thread.
        // Body forwards verbatim — body shape is the agent's contract, not
        // the framework's, so we just check the bytes match.
        $this->captureSupervisorCalls([
            [200, json_encode(['id' => 'thread-42', 'archived' => true]), 'application/json'],
        ]);
        putenv('TINA4_SUPERVISOR_URL=http://agent.test:9145');
        DevAdmin::register();

        $callback = $this->findRouteCallback('PATCH', '/__dev/api/threads/{id}');
        $this->assertNotNull($callback, 'PATCH /__dev/api/threads/{id} must be registered');

        $request = Request::create('PATCH', '/__dev/api/threads/thread-42', body: ['archived' => true]);
        // The Router populates ->params during dispatch — we're calling the
        // callback directly, so populate it manually to mimic real routing.
        $request->params = ['id' => 'thread-42'];
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $captured = $this->capturedSupervisorCalls;
        $this->assertCount(1, $captured);
        $this->assertSame('PATCH', $captured[0]['method']);
        $this->assertSame('http://agent.test:9145/threads/thread-42', $captured[0]['url']);

        $decoded = json_decode($captured[0]['body'], true);
        $this->assertSame(['archived' => true], $decoded);

        $json = json_decode($result->getBody(), true);
        $this->assertTrue($json['archived']);

        DevAdmin::resetSupervisorTransport();
        putenv('TINA4_SUPERVISOR_URL');
    }

    public function testThreadsMessagesProxiesGet(): void
    {
        // /__dev/api/threads/{id}/messages — message history for one thread.
        // Verifies the wildcard-style sub-route is registered and the
        // {id} param survives URL-encoding into the upstream path.
        $this->captureSupervisorCalls([
            [200, json_encode(['messages' => [['role' => 'user', 'content' => 'hi']]]), 'application/json'],
        ]);
        putenv('TINA4_SUPERVISOR_URL=http://agent.test:9145');
        DevAdmin::register();

        $callback = $this->findRouteCallback('GET', '/__dev/api/threads/{id}/messages');
        $this->assertNotNull($callback, 'GET /__dev/api/threads/{id}/messages must be registered');

        $request = Request::create('GET', '/__dev/api/threads/abc/messages');
        $request->params = ['id' => 'abc'];
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $captured = $this->capturedSupervisorCalls;
        $this->assertSame('GET', $captured[0]['method']);
        $this->assertSame('http://agent.test:9145/threads/abc/messages', $captured[0]['url']);

        $json = json_decode($result->getBody(), true);
        $this->assertSame('hi', $json['messages'][0]['content']);

        DevAdmin::resetSupervisorTransport();
        putenv('TINA4_SUPERVISOR_URL');
    }

    public function testChatProxyReturns503WhenAgentUnreachable(): void
    {
        // Parity with Python: when the agent is unreachable, the SPA must
        // get a structured 503 with a `hint` field so the chat panel can
        // show "Run `tina4 serve`" instead of a dead spinner.
        DevAdmin::$supervisorTransport = function (): array {
            return [
                'status'       => 0,
                'content_type' => '',
                'error'        => 'Connection refused',
                'chunks'       => function (): \Generator { yield ''; },
            ];
        };
        DevAdmin::register();

        $callback = $this->findRouteCallback('POST', '/__dev/api/chat');
        $request = Request::create('POST', '/__dev/api/chat', body: ['message' => 'hi']);
        $response = new Response(true);
        /** @var Response $result */
        $result = $callback($request, $response);

        $this->assertSame(503, $result->getStatusCode());
        $json = json_decode($result->getBody(), true);
        $this->assertSame('agent unreachable', $json['error']);
        $this->assertArrayHasKey('hint', $json);

        DevAdmin::resetSupervisorTransport();
    }

    // ── Helper ─────────────────────────────────────────────────────

    private function findRouteCallback(string $method, string $pattern): ?callable
    {
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                return $route['callback'];
            }
        }
        return null;
    }
}

// ── SQLite LIMIT dedup ─────────────────────────────────────────────

class SQLiteLimitDedupTest extends TestCase
{
    public function testFetchWithExistingLimitDoesNotDouble(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
        for ($i = 0; $i < 10; $i++) {
            $db->exec("INSERT INTO items (id, name) VALUES ({$i}, 'item{$i}')");
        }

        // SQL already has LIMIT — should NOT add another
        $result = $db->fetch("SELECT * FROM items LIMIT 3");
        $this->assertCount(3, $result['data']);

        $db->close();
    }

    public function testFetchWithoutLimitAddsDefault(): void
    {
        $db = new \Tina4\Database\SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
        for ($i = 0; $i < 30; $i++) {
            $db->exec("INSERT INTO items (id, name) VALUES ({$i}, 'item{$i}')");
        }

        // No LIMIT in SQL — adapter adds default (10)
        $result = $db->fetch("SELECT * FROM items");
        $this->assertCount(10, $result['data']);

        $db->close();
    }
}
