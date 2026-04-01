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

    // ── Dashboard HTML ──────────────────────────────────────────

    public function testDashboardReturnsHtml(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Tina4 Dev Admin', $html);
    }

    public function testDashboardContainsTabs(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('dev-tab', $html);
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

    // ── Database tab HTML ──────────────────────────────────────────

    public function testDashboardSplitScreenLayout(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('table-list', $html);
        $this->assertStringContainsString('query-results', $html);
        $this->assertStringContainsString('query-input', $html);
    }

    public function testDashboardCopyButtons(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('Copy CSV', $html);
        $this->assertStringContainsString('Copy JSON', $html);
        $this->assertStringContainsString('Paste', $html);
    }

    public function testDashboardLimitDropdown(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('query-limit', $html);
        $this->assertStringContainsString('<option value="20">20</option>', $html);
        $this->assertStringContainsString('<option value="0">All</option>', $html);
    }

    public function testDashboardSeedControls(): void
    {
        $html = DevAdmin::renderDashboard();
        $this->assertStringContainsString('seed-table', $html);
        $this->assertStringContainsString('seed-count', $html);
        $this->assertStringContainsString('seedTable()', $html);
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
