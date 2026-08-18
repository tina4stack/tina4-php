<?php

// renderToolbar() + the $suppressReload flag live in DevAdmin.php; PSR-4 cannot
// autoload the helper classes individually, so force-include the file (mirrors
// DevAdminTest).
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;

/**
 * Dual-port AI dev mode.
 *
 * In debug mode (TINA4_DEBUG=true, TINA4_NO_AI_PORT unset) Tina4\Server opens a
 * SECOND listener on base+1000. The MAIN/base port hot-reloads — the dev toolbar
 * and the /__dev_reload reload script are injected. The base+1000 "AI port" is
 * STABLE: Server tags those connections (sets DevAdmin::$suppressReload = true)
 * so renderToolbar() omits the reload script, and a /__dev_reload websocket
 * upgrade on that port is answered with 404 (Tina4\Server::handleRequest). This
 * lets an AI tool drive base+1000 without its own edits triggering a refresh,
 * while the human dev keeps live reload on the base port. The Rust `tina4` CLI
 * fires reloads by POSTing /__dev/api/reload to the BASE port only.
 *
 * Parity with Python (master), Node, and Ruby — base hot-reloads, base+1000 is
 * the stable AI port.
 *
 * This unit test covers the injection-suppression core (renderToolbar). The
 * second-listener gating (only when debug && !TINA4_NO_AI_PORT) and the
 * /__dev_reload 404 are socket-level and verified live via `tina4 serve`.
 */
class DualPortReloadTest extends TestCase
{
    protected function setUp(): void
    {
        DevAdmin::$suppressReload = false;
    }

    protected function tearDown(): void
    {
        // Never leak the flag into other tests.
        DevAdmin::$suppressReload = false;
    }

    /**
     * Main (hot-reload) port: the reloader is enabled.
     *
     * Since the CSP-clean refactor the reloader script itself lives in the
     * external `/__dev/toolbar.js` asset — the toolbar HTML no longer inlines
     * it. Suppression is now signalled by a `data-reload` attribute on the
     * toolbar root that the external JS reads to decide whether to connect.
     * On the main port the attribute must be `"1"`. The `/__dev_reload` URL
     * still has to exist SOMEWHERE for the reloader to work; it now lives in
     * `DevAdmin::toolbarJs()`, so we also confirm that.
     */
    public function testMainPortInjectsReloadScript(): void
    {
        DevAdmin::$suppressReload = false;
        $html = DevAdmin::renderToolbar('GET', '/', 'static', 'req-main', 5);

        $this->assertStringContainsString('tina4-dev-toolbar', $html, 'the dev toolbar shell should render');
        $this->assertStringContainsString('data-reload="1"', $html, 'main port must enable the reloader via data-reload="1"');
        $this->assertStringContainsString('<script src="/__dev/toolbar.js"></script>', $html, 'the external toolbar script must be referenced');
        // toolbarJs() is a private static helper (its output ships via the
        // /__dev/toolbar.js route). Reflection reads what the route serves
        // without widening the public API just for the test.
        $js = (new \ReflectionMethod(DevAdmin::class, 'toolbarJs'))->invoke(null);
        $this->assertStringContainsString('/__dev_reload', $js, 'the reload WS URL must live in the external toolbar JS');
    }

    /**
     * AI port (base+1000): the reloader is suppressed — no auto-refresh.
     *
     * The suppression signal is `data-reload="0"` on the toolbar root. The old
     * assertion (that `/__dev_reload` was NOT in the HTML) still passed with
     * the CSP-clean shape, but ONLY VACUOUSLY: the URL is no longer in the
     * HTML in either variant — it lives in the external `/__dev/toolbar.js`.
     * Asserting on the actual suppression signal is what proves the gate.
     */
    public function testAiPortSuppressesReloadScript(): void
    {
        DevAdmin::$suppressReload = true;
        $html = DevAdmin::renderToolbar('GET', '/', 'static', 'req-ai', 5);

        $this->assertStringContainsString('data-reload="0"', $html, 'AI port must suppress the reloader via data-reload="0"');
        $this->assertStringNotContainsString('data-reload="1"', $html, 'the enabled signal must not be present on the AI port');
    }

    /** The suppression is per-request: flipping the flag flips the data-reload attribute. */
    public function testSuppressionTogglesPerRequest(): void
    {
        DevAdmin::$suppressReload = false;
        $main = DevAdmin::renderToolbar('GET', '/', 'static', 'a', 1);

        DevAdmin::$suppressReload = true;
        $ai = DevAdmin::renderToolbar('GET', '/', 'static', 'b', 1);

        $this->assertStringContainsString('data-reload="1"', $main);
        $this->assertStringContainsString('data-reload="0"', $ai);
    }
}
