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

    /** Main (hot-reload) port: the /__dev_reload reload script IS injected. */
    public function testMainPortInjectsReloadScript(): void
    {
        DevAdmin::$suppressReload = false;
        $html = DevAdmin::renderToolbar('GET', '/', 'static', 'req-main', 5);

        $this->assertStringContainsString('tina4-dev-toolbar', $html, 'the dev toolbar shell should render');
        $this->assertStringContainsString('/__dev_reload', $html, 'main port must inject the hot-reload script');
    }

    /** AI port (base+1000): the reload script is suppressed — no auto-refresh. */
    public function testAiPortSuppressesReloadScript(): void
    {
        DevAdmin::$suppressReload = true;
        $html = DevAdmin::renderToolbar('GET', '/', 'static', 'req-ai', 5);

        $this->assertStringNotContainsString('/__dev_reload', $html, 'AI port must NOT inject the hot-reload script');
    }

    /** The suppression is per-request: flipping the flag flips the output. */
    public function testSuppressionTogglesPerRequest(): void
    {
        DevAdmin::$suppressReload = false;
        $main = DevAdmin::renderToolbar('GET', '/', 'static', 'a', 1);

        DevAdmin::$suppressReload = true;
        $ai = DevAdmin::renderToolbar('GET', '/', 'static', 'b', 1);

        $this->assertStringContainsString('/__dev_reload', $main);
        $this->assertStringNotContainsString('/__dev_reload', $ai);
    }
}
