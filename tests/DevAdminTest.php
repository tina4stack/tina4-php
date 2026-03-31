<?php

// MessageLog and RequestInspector are defined in DevAdmin.php alongside DevAdmin
// but PSR-4 cannot autoload them individually, so we force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\MessageLog;
use Tina4\RequestInspector;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

class DevAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Router::reset();
        MessageLog::reset();
        RequestInspector::reset();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Router::reset();
        MessageLog::reset();
        RequestInspector::reset();
    }

    // ── Registration ────────────────────────────────────────────

    public function testRegisterAddsDevRoutes(): void
    {
        DevAdmin::register();
        $routes = Router::list();
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/__dev', $patterns);
    }

    public function testRegisterAddsStatusEndpoint(): void
    {
        DevAdmin::register();
        $routes = Router::list();
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/__dev/api/status', $patterns);
    }

    public function testRegisterAddsRoutesEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        $this->assertContains('/__dev/api/routes', $patterns);
    }

    public function testRegisterAddsMessagesEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        $this->assertContains('/__dev/api/messages', $patterns);
    }

    public function testRegisterAddsRequestsEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        $this->assertContains('/__dev/api/requests', $patterns);
    }

    public function testRegisterAddsMailboxEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        $this->assertContains('/__dev/api/mailbox', $patterns);
    }

    public function testRegisterAddsSystemEndpoint(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        $this->assertContains('/__dev/api/system', $patterns);
    }

    public function testRegisterAddsDuplicateDevRoute(): void
    {
        DevAdmin::register();
        $patterns = array_column(Router::list(), 'pattern');
        // Both /__dev and /__dev/ are registered but Router normalizes trailing slash
        // so both show up as /__dev. Verify at least two GET routes for /__dev exist.
        $devRoutes = array_filter(Router::list(), fn($r) => $r['pattern'] === '/__dev' && $r['method'] === 'GET');
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
}
