<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Parity regression for TINA4_SESSION_NAME (the 3.13.79 Python-master fix).
 *
 * The write side (Router::dispatch's Set-Cookie + Session::cookieHeader) resolves
 * the cookie name from TINA4_SESSION_NAME, but the READ side in Router::dispatch
 * read a HARDCODED $_COOKIE['tina4_session']. A renamed cookie was therefore
 * WRITTEN under the configured name but READ back under "tina4_session", so the
 * session silently never resumed. The fix routes BOTH sides through one shared
 * resolver, Session::cookieName().
 *
 * This asserts the WIRE CONTRACT on a real `php -S` server: request 1 sets
 * Set-Cookie under the configured name (and NOT under "tina4_session"); request 2
 * replays that cookie and the session RESUMES (a per-session counter increments).
 * A default-name pair proves the unset behaviour is byte-identical to before.
 *
 * No mocks: real server, real HTTP, real Set-Cookie headers, real file-backed
 * session persisted to disk across the two requests.
 */
class SessionCookieNameTest extends TestCase
{
    private const CUSTOM_NAME = 'my_app_session';

    /** Custom-name server (TINA4_SESSION_NAME set). */
    private static ?TestServer $customServer = null;
    /** Default-name server (env unset). */
    private static ?TestServer $defaultServer = null;
    private static string $customRoot = '';
    private static string $defaultRoot = '';

    public static function setUpBeforeClass(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        // A clean-room app: framework only. GET / bumps a Tina4-session counter
        // and echoes it, so a resumed session shows a higher count than a fresh
        // one. The counter lives in $request->session (Tina4's own session, the
        // tina4_session cookie), which is exactly what the read/write name change
        // affects.
        $index = <<<PHP
        <?php
        require_once '{$autoload}';
        \\Tina4\\Router::get('/', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            \$count = (int)(\$request->session->get('count', 0)) + 1;
            \$request->session->set('count', \$count);
            return \$response('count=' . \$count);
        });
        \$result = \\Tina4\\Router::dispatch(new \\Tina4\\Request(), new \\Tina4\\Response());
        echo \$result->getBody();
        PHP;

        self::$customRoot = \TempPath::dir('tina4_sess_name_custom_', 0777);
        self::$defaultRoot = \TempPath::dir('tina4_sess_name_default_', 0777);
        file_put_contents(self::$customRoot . '/index.php', $index);
        file_put_contents(self::$defaultRoot . '/index.php', $index);

        // Custom-name server: TINA4_SESSION_NAME set for THIS child only.
        $customEnv = getenv();
        $customEnv['TINA4_SESSION_NAME'] = self::CUSTOM_NAME;
        self::$customServer = TestServer::start(self::$customRoot . '/index.php', $customEnv);

        // Default-name server: TINA4_SESSION_NAME explicitly absent.
        $defaultEnv = getenv();
        unset($defaultEnv['TINA4_SESSION_NAME']);
        self::$defaultServer = TestServer::start(self::$defaultRoot . '/index.php', $defaultEnv);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$customServer, self::$defaultServer] as $server) {
            $server?->stop();
        }
        foreach ([self::$customRoot, self::$defaultRoot] as $root) {
            @unlink($root . '/index.php');
            // best-effort clean-up of any session files the run wrote
            foreach (glob($root . '/data/sessions/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($root . '/data/sessions');
            @rmdir($root . '/data');
            @rmdir($root);
        }
    }



    /**
     * Perform a real GET / and return the body plus every Set-Cookie line.
     *
     * @param string[] $headers Extra request headers, e.g. a replayed Cookie.
     * @return array{body: string, cookies: string[]}
     */
    private function get(int $port, array $headers = []): array
    {
        $opts = ['method' => 'GET', 'ignore_errors' => true];
        if ($headers !== []) {
            $opts['header'] = implode("\r\n", $headers);
        }
        $ctx = stream_context_create(['http' => $opts]);
        $body = @file_get_contents("http://127.0.0.1:{$port}/", false, $ctx);
        $this->assertNotFalse($body, 'the clean-room app must respond');
        $cookies = [];
        foreach ($http_response_header ?? [] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookies[] = trim(substr($line, strlen('Set-Cookie:')));
            }
        }
        return ['body' => $body, 'cookies' => $cookies];
    }

    /** Return the whole Set-Cookie line whose name is $name (exact "name=" prefix). */
    private function cookieNamed(array $cookies, string $name): ?string
    {
        foreach ($cookies as $c) {
            if (stripos($c, $name . '=') === 0) {
                return $c;
            }
        }
        return null;
    }

    /** Extract the value of a "name=value; ..." Set-Cookie line. */
    private function cookieValue(string $setCookieLine): string
    {
        $firstPair = explode(';', $setCookieLine, 2)[0];
        return explode('=', $firstPair, 2)[1] ?? '';
    }

    /**
     * The write side must emit the configured name, not the hardcoded literal.
     */
    public function testCustomNameIsWrittenAndLegacyNameIsNot(): void
    {
        $first = $this->get(self::$customServer->port);

        $custom = $this->cookieNamed($first['cookies'], self::CUSTOM_NAME);
        $this->assertNotNull(
            $custom,
            'TINA4_SESSION_NAME=' . self::CUSTOM_NAME . ' must be the emitted cookie name - got: '
            . implode(' | ', $first['cookies'])
        );
        $this->assertNull(
            $this->cookieNamed($first['cookies'], 'tina4_session'),
            'the legacy hardcoded "tina4_session" cookie must NOT be emitted once the name is renamed'
        );
    }

    /**
     * THE lock-in: a cookie written under the configured name must be READ back
     * under that same name so the session RESUMES. This fails when the read side
     * still looks up the hardcoded "tina4_session" key.
     */
    public function testCustomNameSessionResumesOnReplay(): void
    {
        $first = $this->get(self::$customServer->port);
        $this->assertSame('count=1', $first['body'], 'a fresh session starts the counter at 1');

        $custom = $this->cookieNamed($first['cookies'], self::CUSTOM_NAME);
        $this->assertNotNull($custom, 'request 1 must set the renamed cookie');
        $sid = $this->cookieValue($custom);
        $this->assertNotSame('', $sid, 'the renamed cookie must carry a session id');

        $second = $this->get(
            self::$customServer->port,
            ['Cookie: ' . self::CUSTOM_NAME . '=' . $sid]
        );
        $this->assertSame(
            'count=2',
            $second['body'],
            'replaying the renamed cookie must RESUME the session (counter 1 -> 2); '
            . 'count=1 means the read side did not honour TINA4_SESSION_NAME and started a new session'
        );
    }

    /**
     * Unset TINA4_SESSION_NAME must be byte-identical to before: the cookie is
     * "tina4_session" and it still resumes on replay.
     */
    public function testDefaultNameIsTina4SessionAndResumes(): void
    {
        $first = $this->get(self::$defaultServer->port);
        $this->assertSame('count=1', $first['body']);

        $tina4 = $this->cookieNamed($first['cookies'], 'tina4_session');
        $this->assertNotNull(
            $tina4,
            'with TINA4_SESSION_NAME unset the cookie name must stay "tina4_session" - got: '
            . implode(' | ', $first['cookies'])
        );

        $sid = $this->cookieValue($tina4);
        $second = $this->get(self::$defaultServer->port, ['Cookie: tina4_session=' . $sid]);
        $this->assertSame(
            'count=2',
            $second['body'],
            'the default cookie name must still resume the session'
        );
    }
}
