<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * SESSION CONTRACT: a backend failure is LOUD, then degrades - on the REAL request path.
 *
 * ADR-0021: a backend that becomes unreachable is LOGGED and then degraded - the
 * read yields an empty session, save() returns false, and the request STILL
 * SERVES. It is never silent. TINA4_SESSION_STRICT re-raises instead.
 *
 * WHY THIS FILE EXISTS, and why it is separate from SessionBackendFailurePolicyTest.
 *
 * That file proves the policy on the Session OBJECT, thoroughly, against really
 * refused ports - 11 cases including the empty-read control and both strict-mode
 * cases. This file proves it on the HTTP REQUEST PATH, which is a different code
 * path and was NOT covered. Measured at v3 HEAD, Tina4/Router.php:670-672:
 *
 *     $session = new Session();
 *     $session->start($sessionCookie);
 *     $request->session = $session;
 *
 * Not a try/catch in sight. Session's own read/write policy logs and degrades
 * correctly, but CONSTRUCTION sits outside it: a refused TINA4_SESSION_BACKEND
 * throws straight out of the constructor, and a handler that cannot be built
 * throws out of start(). Either one returned a 500 for EVERY request rather than
 * serving the page without a session - an outage where the contract promises a
 * degrade.
 *
 * THE OTHER HALF OF THE RULE, and the easy thing to get wrong when making
 * failures loud: a genuinely EMPTY session is NOT an error and must never be
 * logged as one. A first-time visitor with no cookie, and a cookie whose session
 * the store has never heard of, are both ordinary. If those log an error the log
 * fills with noise on every new visitor and the real outage is invisible - the
 * same blindness the fix was meant to cure. Case 3 is that control and it is not
 * optional.
 *
 * NO MOCKS. The unreachable backend is a genuinely closed TCP port, the logger is
 * the REAL logger writing real bytes to a real file which the test reads back,
 * and the request goes through the real Router::dispatch.
 */
class SessionFailureRequestPathTest extends TestCase
{
    /**
     * A well-formed session id for a session no store has heard of. Without a
     * COOKIE the request path can take a "brand new session" branch and never
     * touch the backend at all, which would make an "unreachable backend" case
     * vacuous. Learned the hard way in the Python port, where the strict-mode
     * case returned a cheerful 200 for exactly that reason.
     */
    private const UNKNOWN_SESSION_COOKIE = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $logDir;
    private int $logMark = 0;
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/tina4_sess_reqpath_' . uniqid('', true);
        mkdir($this->logDir, 0755, true);

        foreach (['TINA4_SESSION_BACKEND', 'TINA4_SESSION_STRICT', 'TINA4_SESSION_PATH',
                  'TINA4_SESSION_REDIS_HOST', 'TINA4_SESSION_REDIS_PORT',
                  'TINA4_LOG_OUTPUT', 'TINA4_LOG_DIR', 'TINA4_LOG_FORMAT', 'TINA4_LOG_LEVEL'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->logDir);
        putenv('TINA4_LOG_FORMAT=json');
        putenv('TINA4_LOG_LEVEL=debug');
        Log::configure(logDir: $this->logDir, minLevel: 'debug');

        // DRIVER SANITY. Without this, every "was logged" assertion below would
        // be vacuous and every "logged nothing" assertion trivially true - the
        // control would prove the opposite of what it claims.
        $this->markLog();
        Log::error('sink-selftest');
        $this->assertNotEmpty(
            array_filter($this->loggedErrors(), fn($m) => str_contains($m, 'sink-selftest')),
            'the real logger wrote nothing to ' . $this->logDir . ' - every log assertion here would be meaningless'
        );

        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        $_COOKIE = [];
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDir);
    }

    private function logFile(): string
    {
        $candidates = glob($this->logDir . '/*.log') ?: [];
        return $candidates[0] ?? ($this->logDir . '/tina4.log');
    }

    private function markLog(): void
    {
        $path = $this->logFile();
        $this->logMark = file_exists($path) ? (int)filesize($path) : 0;
    }

    /** Only the ERROR records, parsed out of the REAL JSON the logger wrote. */
    private function loggedErrors(): array
    {
        $path = $this->logFile();
        if (!file_exists($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        fseek($handle, $this->logMark);
        $body = stream_get_contents($handle);
        fclose($handle);

        $out = [];
        foreach (explode("\n", (string)$body) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && strtolower((string)($entry['level'] ?? '')) === 'error') {
                $out[] = (string)($entry['message'] ?? '');
            }
        }
        return $out;
    }

    private function sessionErrors(): array
    {
        return array_values(array_filter(
            $this->loggedErrors(),
            fn($message) => str_contains(strtolower($message), 'session')
        ));
    }

    /** Bind a port, learn its number, close it. Nothing listens afterwards. */
    private function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
        $this->assertNotFalse($socket, 'could not bind a probe port');
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        fclose($socket);

        $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.5);
        $this->assertFalse(
            $probe,
            "127.0.0.1:{$port} answered - it was supposed to be closed, so this case would test nothing"
        );
        return $port;
    }

    private function dispatch(): Response
    {
        // Request's method/path are READONLY - they are constructor arguments,
        // not assignable properties. Building one by assignment throws an Error
        // that a broad catch in case 4 happily mistook for a strict-mode raise,
        // which is exactly why that case now asserts the raise came with a log.
        $request = new Request('GET', '/session-failure-probe-' . uniqid('', true));
        return Router::dispatch($request, new Response());
    }

    /**
     * An unusable session backend must leave a record an operator can find.
     *
     * TINA4_SESSION_BACKEND is set to a name the framework deliberately refuses.
     * That refusal is a loud exception by design, and the request path used to
     * let it escape as a 500 with nothing logged about the session at all.
     */
    public function testABackendFailureOnTheRequestPathIsLoggedNotSilent(): void
    {
        putenv('TINA4_SESSION_BACKEND=redsi'); // a real typo, deliberately refused
        $this->markLog();

        $this->dispatch();

        $this->assertNotEmpty(
            $this->sessionErrors(),
            'an unusable session backend produced NO session error log on the real '
            . 'request path - the operator has no signal at all. Errors seen: '
            . json_encode($this->loggedErrors())
        );
    }

    /**
     * Loud is only half the rule: the request must still be SERVED.
     *
     * Degrading means the user gets their page without a session, not a 500.
     * That is what separates a degrade from an outage.
     */
    public function testABackendFailureOnTheRequestPathStillServesTheRequest(): void
    {
        $port = $this->closedPort();
        putenv('TINA4_SESSION_BACKEND=redis');
        putenv('TINA4_SESSION_REDIS_HOST=127.0.0.1');
        putenv('TINA4_SESSION_REDIS_PORT=' . $port);
        $_COOKIE[\Tina4\Session::cookieName()] = self::UNKNOWN_SESSION_COOKIE;
        $this->markLog();

        $result = $this->dispatch();

        $status = $result->getStatusCode();
        $this->assertLessThan(
            500,
            $status,
            "an unreachable session backend returned {$status} instead of serving "
            . 'the request without a session'
        );
        $this->assertNotEmpty(
            $this->sessionErrors(),
            'the request served, but the unreachable backend was not logged - degrading '
            . 'silently is the defect, not the fix'
        );
    }

    /**
     * NEGATIVE CONTROL, and the one most likely to be got wrong.
     *
     * A first-time visitor has no cookie, and a returning visitor may carry an
     * id the store has never heard of. Both are ORDINARY, not failures. If
     * making failures loud also makes these loud, the log fills with noise on
     * every new visitor and the real outage becomes invisible - the same
     * blindness the fix was meant to cure.
     *
     * Without this case, "log an error unconditionally" passes both cases above.
     */
    public function testAnEmptySessionOnTheRequestPathIsNotLoggedAsAFailure(): void
    {
        $sessionDir = sys_get_temp_dir() . '/tina4_sess_empty_' . uniqid('', true);
        mkdir($sessionDir, 0755, true);
        putenv('TINA4_SESSION_BACKEND=file');
        putenv('TINA4_SESSION_PATH=' . $sessionDir);
        $this->markLog();

        // (a) a brand new visitor with no cookie at all
        $fresh = $this->dispatch();
        // (b) a returning visitor whose session the store has never heard of
        $_COOKIE[\Tina4\Session::cookieName()] = self::UNKNOWN_SESSION_COOKIE;
        $unknown = $this->dispatch();

        $this->assertLessThan(500, $fresh->getStatusCode());
        $this->assertLessThan(500, $unknown->getStatusCode());
        $this->assertSame(
            [],
            $this->sessionErrors(),
            'a healthy backend with an EMPTY session logged an error. An empty session '
            . 'is not a failure.'
        );

        foreach (glob($sessionDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($sessionDir);
    }

    /**
     * TINA4_SESSION_STRICT must actually reach the request path.
     *
     * Strict mode exists so an operator can choose "fail the request" over
     * "serve it without a session". The contract word is RE-RAISES, so that is
     * what this asserts - and it must still have logged first, or the log has a
     * hole exactly where the outage is.
     */
    public function testStrictModeOnTheRequestPathRefusesInsteadOfDegrading(): void
    {
        putenv('TINA4_SESSION_BACKEND=redsi'); // refused at construction, deterministically
        putenv('TINA4_SESSION_STRICT=true');
        $this->markLog();

        $raised = null;
        try {
            $this->dispatch();
        } catch (\Throwable $error) {
            $raised = $error;
        }

        $this->assertNotNull(
            $raised,
            'TINA4_SESSION_STRICT was set and an unusable backend still served the request. '
            . 'Strict mode is inert on the request path: the caller was told everything was '
            . 'fine while the session did not exist.'
        );
        $this->assertNotEmpty(
            $this->sessionErrors(),
            'strict mode raised without logging first - loud must mean logged AND raised, '
            . 'or the log has a hole exactly where the outage is'
        );
    }
}
