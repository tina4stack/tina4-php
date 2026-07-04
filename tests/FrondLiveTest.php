<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;
use Tina4\Request;
use Tina4\Response;

/**
 * Frond {% live %} blocks - engine, endpoint (respondLive), push (pushLive).
 * Mirrors the Python test_frond_live* suites. No mocks: real Frond, real
 * Request/Response. Parity with Python master.
 */
class FrondLiveTest extends TestCase
{
    protected function setUp(): void
    {
        Frond::clearRegistry();
    }

    private function engine(): Frond
    {
        return new Frond(sys_get_temp_dir());
    }

    // ── engine ──────────────────────────────────────────────────

    public function testPollWrapperFirstPaintAndRegistry(): void
    {
        $out = $this->engine()->renderString(
            '{% live "notifications" poll 5 %}<ul>{% for n in items %}<li>{{ n }}</li>{% endfor %}</ul>{% endlive %}',
            ['items' => ['a', 'b']]
        );
        $this->assertStringContainsString('data-frond-live="notifications"', $out);
        $this->assertStringContainsString('id="live-notifications"', $out);
        $this->assertStringContainsString('data-mode="poll"', $out);
        $this->assertStringContainsString('data-interval="5"', $out);
        $this->assertStringContainsString('data-src="/__frond/live/notifications"', $out);
        $this->assertStringContainsString('<li>a</li>', $out);
        $this->assertTrue(Frond::hasLiveFragment('notifications'));
    }

    public function testRenderLiveReRendersWithFreshData(): void
    {
        $this->engine()->renderString('{% live "cart" poll 3 %}<b>{{ count }}</b>{% endlive %}', ['count' => 1]);
        $html = Frond::renderLive('cart', ['count' => 9]);
        $this->assertStringContainsString('<b>9</b>', $html);
    }

    public function testRenderLiveUnknownReturnsNull(): void
    {
        $this->assertNull(Frond::renderLive('never-registered', []));
    }

    public function testSseMode(): void
    {
        $out = $this->engine()->renderString('{% live "feed" sse %}<span>{{ n }}</span>{% endlive %}', ['n' => 12]);
        $this->assertStringContainsString('data-mode="sse"', $out);
        $this->assertStringContainsString('data-src="/__frond/live/feed"', $out);
    }

    public function testWsModeUsesDataWs(): void
    {
        $out = $this->engine()->renderString('{% live "chat" ws "/ws/chat" %}hi{% endlive %}', []);
        $this->assertStringContainsString('data-mode="ws"', $out);
        $this->assertStringContainsString('data-ws="/ws/chat"', $out);
        $this->assertSame('/ws/chat', Frond::getLiveWsPath('chat'));
    }

    public function testExplicitSrcRoute(): void
    {
        $out = $this->engine()->renderString('{% live "cart" poll 5 src "/fragments/cart" %}0{% endlive %}', []);
        $this->assertStringContainsString('data-src="/fragments/cart"', $out);
    }

    public function testUnknownTransportThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine()->renderString('{% live "x" bogus %}y{% endlive %}', []);
    }

    public function testPollWithoutSecondsThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine()->renderString('{% live "x" poll %}y{% endlive %}', []);
    }

    public function testCrossOriginSrcRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine()->renderString('{% live "x" poll 5 src "http://evil.example/x" %}y{% endlive %}', []);
    }

    public function testNestedLiveThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine()->renderString('{% live "a" poll 5 %}{% live "b" poll 5 %}z{% endlive %}{% endlive %}', []);
    }

    public function testLiveSourceRegistersProvider(): void
    {
        $fn = function ($request) { return ['n' => 3]; };
        Frond::liveSource('orders', $fn);
        $this->assertSame($fn, Frond::getLiveSource('orders'));
    }

    // ── endpoint (respondLive) ──────────────────────────────────

    public function testEndpointReRendersWithProviderData(): void
    {
        $this->engine()->renderString('{% live "cart" poll 5 %}<b>{{ count }}</b> items{% endlive %}', ['count' => 1]);
        Frond::liveSource('cart', fn($r) => ['count' => 7]);
        $resp = Frond::respondLive(new Request(), new Response(true), 'cart');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('<b>7</b> items', $resp->getBody());
    }

    public function testEndpointUnknownName404(): void
    {
        $resp = Frond::respondLive(new Request(), new Response(true), 'nope');
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testEndpointFragmentNotRenderedYet404(): void
    {
        Frond::liveSource('later', fn($r) => ['x' => 1]);
        $resp = Frond::respondLive(new Request(), new Response(true), 'later');
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testEndpointReappliesAuthScopingPerRequest(): void
    {
        // IDOR contract: the provider re-runs with the live request every
        // refresh, so an unauthenticated caller never gets another user's data.
        $this->engine()->renderString('{% live "me" poll 5 %}<span>{{ who }}</span>{% endlive %}', ['who' => '']);
        Frond::liveSource('me', function ($request) {
            $user = $request->headers['x-user'] ?? null;
            return ['who' => $user ?: 'guest'];
        });

        $anon = new Request(headers: []);
        $r1 = Frond::respondLive($anon, new Response(true), 'me');
        $this->assertStringContainsString('<span>guest</span>', $r1->getBody());

        $authed = new Request(headers: ['x-user' => 'alice']);
        $r2 = Frond::respondLive($authed, new Response(true), 'me');
        $this->assertStringContainsString('<span>alice</span>', $r2->getBody());
        $this->assertStringNotContainsString('alice', $r1->getBody());
    }

    public function testEndpointProviderNoneButFragmentExists(): void
    {
        $this->engine()->renderString('{% live "static" poll 5 %}<p>hello</p>{% endlive %}', []);
        $resp = Frond::respondLive(new Request(), new Response(true), 'static');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('<p>hello</p>', $resp->getBody());
    }

    // ── push_live ───────────────────────────────────────────────

    public function testPushLiveReturnsRenderedHtml(): void
    {
        $this->engine()->renderString('{% live "score" ws "/ws/score" %}<b>{{ n }}</b>{% endlive %}', ['n' => 0]);
        $html = Frond::pushLive('score', ['n' => 5]);
        $this->assertStringContainsString('<b>5</b>', $html);
    }

    public function testPushLiveUnknownReturnsNull(): void
    {
        $this->assertNull(Frond::pushLive('ghost', []));
    }
}
