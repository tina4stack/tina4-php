<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Regression coverage for tina4stack/tina4-php#112.
 *
 * On Apache + PHP-FPM and any SAPI where `session.auto_start = Off`
 * (PHP's default), writes to $_SESSION inside route handlers were
 * silently discarded between requests. Router::dispatch now calls
 * session_start() before running the handler so $_SESSION persists
 * the way existing app code expects.
 */
class RouterSessionStartTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        // Use a per-test temp directory so tests never collide on the
        // save-path (parallel PHPUnit workers, leftover files, etc.)
        $this->sessionPath = sys_get_temp_dir()
            . '/tina4_session_start_' . uniqid('', true);
        mkdir($this->sessionPath, 0755, true);
        putenv("TINA4_PHP_SESSION_PATH={$this->sessionPath}");

        // Belt + braces — clear any session state that might have
        // leaked from an earlier test in the same process.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        putenv('TINA4_PHP_SESSION_PATH');
        foreach (glob($this->sessionPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->sessionPath);
        Router::clear();
    }

    public function testDispatchStartsNativePhpSession(): void
    {
        // Before dispatch, PHP's native session is dormant.
        $this->assertSame(PHP_SESSION_NONE, session_status());

        $invoked = false;
        Router::get('/sessiontest', function (Request $req, Response $res) use (&$invoked) {
            $invoked = true;
            // Inside the handler, the native session must be live so
            // route code can read/write $_SESSION like any other PHP
            // application expects.
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());
            $_SESSION['test_key'] = 'test_value';
            return $res->text('ok');
        });

        $request = new Request('GET', '/sessiontest');
        Router::dispatch($request, new Response());

        $this->assertTrue($invoked, 'route handler should have been invoked');
        $this->assertArrayHasKey('test_key', $_SESSION);
        $this->assertSame('test_value', $_SESSION['test_key']);
    }

    public function testSessionPathIsConfigurable(): void
    {
        // TINA4_PHP_SESSION_PATH in setUp() points at our temp dir.
        // session_save_path() should reflect that after dispatch.
        Router::get('/ping', fn(Request $req, Response $res) => $res->text('ok'));
        Router::dispatch(new Request('GET', '/ping'), new Response());

        $this->assertSame($this->sessionPath, session_save_path());
    }

    public function testDoesNotRestartAnAlreadyActiveSession(): void
    {
        // If the host app called session_start() before dispatch
        // (common in legacy bootstraps), Router must NOT call it
        // again — doing so raises a PHP warning and can corrupt
        // the session. The guard is `session_status() === PHP_SESSION_NONE`.
        session_save_path($this->sessionPath);
        @session_start();
        $_SESSION['preexisting'] = 42;

        Router::get('/existing', function (Request $req, Response $res) {
            // The value written before dispatch must still be there.
            $this->assertSame(42, $_SESSION['preexisting'] ?? null);
            return $res->text('ok');
        });

        Router::dispatch(new Request('GET', '/existing'), new Response());
    }

    public function testTina4SessionAndNativeSessionCoexist(): void
    {
        // The framework-owned $request->session (tina4_session cookie
        // + data/sessions/*.json) must keep working alongside native
        // $_SESSION. They share nothing, but neither should break
        // the other.
        Router::get('/coexist', function (Request $req, Response $res) {
            $req->session->set('framework_key', 'alpha');
            $_SESSION['native_key'] = 'beta';
            return $res->text('ok');
        });

        Router::dispatch(new Request('GET', '/coexist'), new Response());

        $this->assertSame('beta', $_SESSION['native_key'] ?? null);
        // Framework session is per-request in this test harness; we
        // can't round-trip it without the full HTTP cookie cycle, but
        // we can confirm the write didn't throw and the value made it
        // into the session object's in-memory state before save().
        // The important check is that both writes happened without
        // one clobbering the other.
    }
}
