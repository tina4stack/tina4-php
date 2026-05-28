<?php

// Feedback (and its sibling DevAdmin/MessageLog/RequestInspector classes)
// live alongside DevAdmin under PSR-4, so they autoload on first reference.
// Force-include DevAdmin.php to pull in MessageLog/RequestInspector too,
// in case other tests in the run cleared the autoload cache.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\Feedback;
use Tina4\MessageLog;
use Tina4\Request;
use Tina4\RequestInspector;
use Tina4\Response;
use Tina4\Router;

/**
 * Tier 4 — customer feedback widget. Ported from
 * tina4_python.dev_admin lines 1440-1645.
 *
 * The eight helpers each have a focused test; the two endpoints
 * (POST /__feedback/api/turn, GET /__feedback/widget.js) get
 * end-to-end coverage via Router::register() + Request::create().
 */
class FeedbackTest extends TestCase
{
    /** Env keys we touch — reset every test so no state leaks. */
    private array $envBackup = [];

    protected function setUp(): void
    {
        Router::clear();
        MessageLog::reset();
        RequestInspector::reset();
        Feedback::resetRateLimit();
        DevAdmin::resetSupervisorTransport();

        foreach ([
            'TINA4_ENABLE_FEEDBACK',
            'TINA4_FEEDBACK_WHITELIST',
            'TINA4_FEEDBACK_DEV_USER',
            'TINA4_SUPERVISOR_URL',
        ] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        Router::clear();
        ErrorTracker::reset();
        MessageLog::reset();
        RequestInspector::reset();
        Feedback::resetRateLimit();
        DevAdmin::resetSupervisorTransport();

        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function enableWithDevUser(string $user = 'dev@tina4.com'): void
    {
        putenv('TINA4_ENABLE_FEEDBACK=true');
        putenv('TINA4_FEEDBACK_WHITELIST=' . $user);
        putenv('TINA4_FEEDBACK_DEV_USER=' . $user);
    }

    private function findRouteCallback(string $method, string $pattern): ?callable
    {
        foreach (Router::getRoutes() as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                return $route['callback'];
            }
        }
        return null;
    }

    // ── Helper-level tests (8 helpers) ─────────────────────────────

    public function testEnabledRespectsTruthyValues(): void
    {
        foreach (['1', 'true', 'YES', 'On'] as $val) {
            putenv('TINA4_ENABLE_FEEDBACK=' . $val);
            $this->assertTrue(Feedback::enabled(), "Expected truthy: {$val}");
        }
        foreach (['0', 'false', 'no', 'off', ''] as $val) {
            putenv('TINA4_ENABLE_FEEDBACK=' . $val);
            $this->assertFalse(Feedback::enabled(), "Expected falsy: {$val}");
        }
    }

    public function testWhitelistEmptyWhenDisabled(): void
    {
        putenv('TINA4_ENABLE_FEEDBACK=false');
        putenv('TINA4_FEEDBACK_WHITELIST=alice@example.com');
        $this->assertSame([], Feedback::whitelist());
    }

    public function testWhitelistSplitsAndLowercases(): void
    {
        putenv('TINA4_ENABLE_FEEDBACK=true');
        putenv('TINA4_FEEDBACK_WHITELIST=Alice@Example.com, Bob@Example.com ,, ');
        $this->assertSame(['alice@example.com', 'bob@example.com'], Feedback::whitelist());
    }

    public function testIdentifyUserFallsBackToDevEnv(): void
    {
        putenv('TINA4_FEEDBACK_DEV_USER=Dev@Tina4.Com');
        $req = Request::create('GET', '/some/page');
        $this->assertSame('dev@tina4.com', Feedback::identifyUser($req));
    }

    public function testIdentifyUserReturnsNullWhenAnonymous(): void
    {
        $req = Request::create('GET', '/some/page');
        $this->assertNull(Feedback::identifyUser($req));
    }

    public function testIsWhitelistedWhenAllowed(): void
    {
        $this->enableWithDevUser('alice@example.com');
        $req = Request::create('GET', '/');
        [$ok, $user] = Feedback::isWhitelisted($req);
        $this->assertTrue($ok);
        $this->assertSame('alice@example.com', $user);
    }

    public function testIsWhitelistedRejectsNonMember(): void
    {
        putenv('TINA4_ENABLE_FEEDBACK=true');
        putenv('TINA4_FEEDBACK_WHITELIST=alice@example.com');
        putenv('TINA4_FEEDBACK_DEV_USER=mallory@example.com');
        $req = Request::create('GET', '/');
        [$ok, $user] = Feedback::isWhitelisted($req);
        $this->assertFalse($ok);
        $this->assertSame('mallory@example.com', $user);
    }

    public function testRateLimitOkAllowsFiveThenBlocks(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(Feedback::rateLimitOk('user@a.com'), "Call {$i} should pass");
        }
        $this->assertFalse(Feedback::rateLimitOk('user@a.com'), 'Sixth call must be blocked');
        // Separate user shares no state.
        $this->assertTrue(Feedback::rateLimitOk('other@b.com'));
    }

    // ── Widget injector (idempotent middleware) ────────────────────

    public function testWidgetNotInjectedWhenDisabled(): void
    {
        // Default state in setUp() — TINA4_ENABLE_FEEDBACK unset.
        $html = '<!doctype html><html><body><p>hi</p></body></html>';
        $req = Request::create('GET', '/some/page');
        $this->assertSame($html, Feedback::injectFeedbackWidget($req, $html));
    }

    public function testWidgetNotInjectedOnDevPaths(): void
    {
        $this->enableWithDevUser();
        $html = '<!doctype html><html><body><p>hi</p></body></html>';
        $req = Request::create('GET', '/__dev/whatever');
        $this->assertSame($html, Feedback::injectFeedbackWidget($req, $html));

        $req2 = Request::create('GET', '/__feedback/widget.js');
        $this->assertSame($html, Feedback::injectFeedbackWidget($req2, $html));
    }

    public function testWidgetInjectedForWhitelistedUser(): void
    {
        $this->enableWithDevUser();
        $html = '<!doctype html><html><body><p>hi</p></body></html>';
        $req = Request::create('GET', '/dashboard');
        $out = Feedback::injectFeedbackWidget($req, $html);

        $this->assertNotSame($html, $out);
        $this->assertStringContainsString(
            '<script src="/__feedback/widget.js" data-tina4-feedback></script>',
            $out
        );
        // Script must sit immediately before the LAST </body>.
        $this->assertStringContainsString(
            'data-tina4-feedback></script></body>',
            $out
        );
    }

    public function testWidgetIdempotent(): void
    {
        $this->enableWithDevUser();
        $html = '<!doctype html><html><body><p>hi</p></body></html>';
        $req = Request::create('GET', '/dashboard');
        $first = Feedback::injectFeedbackWidget($req, $html);
        $second = Feedback::injectFeedbackWidget($req, $first);
        $this->assertSame($first, $second);
        // Exactly one script tag — no double injection.
        $this->assertSame(1, substr_count($second, 'data-tina4-feedback'));
    }

    public function testWidgetSkipsResponsesWithoutBodyTag(): void
    {
        $this->enableWithDevUser();
        $req = Request::create('GET', '/dashboard');
        $fragment = '<div>partial</div>';
        $this->assertSame($fragment, Feedback::injectFeedbackWidget($req, $fragment));
    }

    // ── Endpoint: POST /__feedback/api/turn ────────────────────────

    public function testTurnRefusesNonWhitelisted(): void
    {
        // Feature off entirely — every POST gets 403.
        Feedback::register();
        $cb = $this->findRouteCallback('POST', '/__feedback/api/turn');
        $this->assertNotNull($cb);

        $req = Request::create('POST', '/__feedback/api/turn', body: ['message' => 'hi']);
        $res = new Response(true);
        $result = $cb($req, $res);

        $this->assertSame(403, $result->getStatusCode());
        $json = json_decode($result->getBody(), true);
        $this->assertSame('not authorised for feedback', $json['error']);
    }

    public function testTurnRateLimit(): void
    {
        $this->enableWithDevUser('alice@example.com');

        // No outbound network — stub the transport to return a finalisation.
        DevAdmin::$supervisorTransport = function (): array {
            return [
                'status'       => 200,
                'content_type' => 'application/json',
                'error'        => '',
                'chunks'       => function (): \Generator {
                    yield json_encode(['ask' => 'follow-up?']);
                },
            ];
        };
        Feedback::register();
        $cb = $this->findRouteCallback('POST', '/__feedback/api/turn');

        // First 5 calls — all 200.
        for ($i = 0; $i < 5; $i++) {
            $req = Request::create('POST', '/__feedback/api/turn', body: ['message' => "turn {$i}"]);
            $res = new Response(true);
            $out = $cb($req, $res);
            $this->assertSame(200, $out->getStatusCode(), "Call {$i} should succeed");
        }

        // Sixth — rate limit kicks in.
        $req = Request::create('POST', '/__feedback/api/turn', body: ['message' => 'turn 6']);
        $res = new Response(true);
        $out = $cb($req, $res);
        $this->assertSame(429, $out->getStatusCode());
        $json = json_decode($out->getBody(), true);
        $this->assertSame('rate limit exceeded', $json['error']);
        $this->assertStringContainsString('5', $json['hint']);
    }

    public function testTurnForwardsToSupervisor(): void
    {
        $this->enableWithDevUser('alice@example.com');
        putenv('TINA4_SUPERVISOR_URL=http://agent.test:9145');

        $captured = [];
        DevAdmin::$supervisorTransport = function (string $method, string $url, ?string $body, array $headers) use (&$captured): array {
            $captured[] = compact('method', 'url', 'body', 'headers');
            return [
                'status'       => 200,
                'content_type' => 'application/json',
                'error'        => '',
                'chunks'       => function (): \Generator {
                    yield json_encode(['ask' => 'which page were you on?']);
                },
            ];
        };
        Feedback::register();
        $cb = $this->findRouteCallback('POST', '/__feedback/api/turn');

        $req = Request::create('POST', '/__feedback/api/turn', body: [
            'message'         => 'the save button is hard to find',
            'conversation_id' => 'c-1',
            // Client tries to spoof sender — server must overwrite it.
            'sender'          => 'mallory@evil.com',
        ]);
        $res = new Response(true);
        $out = $cb($req, $res);

        $this->assertSame(200, $out->getStatusCode());
        $this->assertCount(1, $captured);
        $this->assertSame('POST', $captured[0]['method']);
        $this->assertSame('http://agent.test:9145/feedback/intake', $captured[0]['url']);

        $forwarded = json_decode($captured[0]['body'], true);
        $this->assertIsArray($forwarded);
        $this->assertSame('the save button is hard to find', $forwarded['message']);
        $this->assertSame('c-1', $forwarded['conversation_id']);
        // Server-stamped — client spoof attempt was overwritten.
        $this->assertSame('alice@example.com', $forwarded['sender']);
    }

    public function testTurnReturns502WhenAgentUnreachable(): void
    {
        $this->enableWithDevUser('alice@example.com');
        DevAdmin::$supervisorTransport = function (): array {
            return [
                'status'       => 0,
                'content_type' => '',
                'error'        => 'Connection refused',
                'chunks'       => function (): \Generator { yield ''; },
            ];
        };
        Feedback::register();
        $cb = $this->findRouteCallback('POST', '/__feedback/api/turn');

        $req = Request::create('POST', '/__feedback/api/turn', body: ['message' => 'hi']);
        $res = new Response(true);
        $out = $cb($req, $res);

        $this->assertSame(502, $out->getStatusCode());
        $json = json_decode($out->getBody(), true);
        $this->assertSame('agent unreachable', $json['error']);
    }

    // ── Endpoint: GET /__feedback/widget.js ────────────────────────

    public function testWidgetJsServedWithNoCacheHeaders(): void
    {
        Feedback::register();
        $cb = $this->findRouteCallback('GET', '/__feedback/widget.js');
        $this->assertNotNull($cb);

        $req = Request::create('GET', '/__feedback/widget.js');
        $res = new Response(true);
        /** @var Response $out */
        $out = $cb($req, $res);

        $this->assertSame(200, $out->getStatusCode());
        $headers = $out->getHeaders();
        // Header lookup is case-sensitive on Response — we set 'Cache-Control'.
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertStringContainsString('no-cache', $headers['Cache-Control']);
        $this->assertStringContainsString('must-revalidate', $headers['Cache-Control']);
        $this->assertArrayHasKey('Pragma', $headers);
        $this->assertSame('no-cache', $headers['Pragma']);
        // Body must be JS — either the real bundle or the stub.
        $body = $out->getBody();
        $this->assertNotEmpty($body);
    }
}
