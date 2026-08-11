<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 43 - request-id / correlation-id.
 *
 * Real request-pipeline tests: every case drives the REAL front controller
 * (Router::dispatch, via TestClient) - NO mocks. The logger case reads the REAL
 * tina4.log file the framework wrote.
 *
 * Proves the four cross-framework invariants:
 *   1. a well-formed inbound X-Request-ID is honoured and echoed on the response;
 *   2. an absent id yields a generated id echoed on the response;
 *   3. a CR/LF-bearing OR over-long OR illegal-charset inbound id is sanitized so
 *      it never reflects raw into the response header or a log line (no injection);
 *   4. a log line written during the request carries the id (log correlation).
 *
 * Plus the PHP-specific fix (RID-PHP-PROCESS-SCOPED): the id is now generated PER
 * REQUEST, not once in the App constructor - two requests get DISTINCT ids.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Log;
use Tina4\Router;
use Tina4\TestClient;
use Tina4\TestResponse;

class RequestIdTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_SECRET=rid-feature-43-secret');
        $_ENV['TINA4_SECRET'] = 'rid-feature-43-secret';

        // Write logs to a throwaway dir so the correlation case can read a REAL
        // file the framework wrote (TINA4_DEBUG truthy => the file sink is on).
        $this->tempDir = sys_get_temp_dir() . '/tina4_rid_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $_ENV['TINA4_DEBUG'] = 'true';
        putenv('TINA4_DEBUG=true');
        Log::reset();
        Log::configure(logDir: $this->tempDir);

        Router::get('/rid-echo', function ($request, $response) {
            // Return the id the framework threaded into the request context, so
            // the test can assert the handler and the wire agree.
            return $response(['rid' => Log::getRequestId()]);
        });
        Router::get('/rid-log', function ($request, $response) {
            Log::info('rid-log-marker');
            return $response(['rid' => Log::getRequestId()]);
        });
    }

    protected function tearDown(): void
    {
        Router::clear();
        Log::setRequestId(null);
        Log::reset();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET'], $_ENV['TINA4_DEBUG']);
        putenv('TINA4_DEBUG');
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    /** Case-insensitive response-header lookup. */
    private function header(TestResponse $res, string $name): ?string
    {
        foreach ($res->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function isWellFormed(?string $rid): bool
    {
        return $rid !== null && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $rid) === 1;
    }

    // 1. honour a valid inbound id -------------------------------------------
    public function testInboundIdIsHonouredAndEchoed(): void
    {
        $res = (new TestClient())->get('/rid-echo', headers: ['X-Request-ID' => 'trace-abc_123.4']);
        $this->assertSame('trace-abc_123.4', $this->header($res, 'X-Request-ID'));
        $this->assertSame('trace-abc_123.4', $res->json()['rid']);
    }

    // 2. generate when absent -------------------------------------------------
    public function testAbsentIdIsGeneratedAndEchoed(): void
    {
        $res = (new TestClient())->get('/rid-echo');
        $rid = $this->header($res, 'X-Request-ID');
        $this->assertTrue($this->isWellFormed($rid), "generated id not well-formed: " . var_export($rid, true));
        $this->assertSame($rid, $res->json()['rid'], 'handler id and response header disagree');
    }

    // 3. sanitize a hostile inbound id (CR/LF) --------------------------------
    public function testCrlfIdIsSanitized(): void
    {
        $hostile = "abc\r\nSet-Cookie: pwned=1";
        $res = (new TestClient())->get('/rid-echo', headers: ['X-Request-ID' => $hostile]);
        $rid = $this->header($res, 'X-Request-ID');
        $this->assertStringNotContainsString("\r", $rid, 'CR reflected into response header');
        $this->assertStringNotContainsString("\n", $rid, 'LF reflected into response header');
        $this->assertNotSame($hostile, $rid, 'raw hostile id was reflected');
        $this->assertTrue($this->isWellFormed($rid), 'response id is not a clean generated id');
        // the injected header must not have materialised anywhere in the response
        foreach ($res->headers as $value) {
            $this->assertStringNotContainsString('pwned', (string) $value);
        }
        $this->assertSame($rid, $res->json()['rid']);
    }

    // 3. sanitize a hostile inbound id (over-long) ----------------------------
    public function testOverlongIdIsSanitized(): void
    {
        $hostile = str_repeat('a', 500);
        $res = (new TestClient())->get('/rid-echo', headers: ['X-Request-ID' => $hostile]);
        $rid = $this->header($res, 'X-Request-ID');
        $this->assertNotSame($hostile, $rid, 'over-long id was reflected');
        $this->assertLessThanOrEqual(128, strlen((string) $rid));
        $this->assertTrue($this->isWellFormed($rid));
    }

    // 3. sanitize a hostile inbound id (illegal charset) ----------------------
    public function testIllegalCharsetIdIsSanitized(): void
    {
        $hostile = 'no spaces or *stars*';
        $res = (new TestClient())->get('/rid-echo', headers: ['X-Request-ID' => $hostile]);
        $rid = $this->header($res, 'X-Request-ID');
        $this->assertNotSame($hostile, $rid, 'illegal-charset id was reflected');
        $this->assertTrue($this->isWellFormed($rid));
    }

    // RID-PHP-PROCESS-SCOPED: two requests get DISTINCT generated ids ---------
    public function testEachRequestGetsItsOwnGeneratedId(): void
    {
        $a = $this->header((new TestClient())->get('/rid-echo'), 'X-Request-ID');
        $b = $this->header((new TestClient())->get('/rid-echo'), 'X-Request-ID');
        // With the old process-scoped id (minted once in the App constructor)
        // every request in the process shared ONE id; per-request generation
        // gives each its own.
        $this->assertNotSame($a, $b, 'two requests shared one id - still process-scoped');
        $this->assertTrue($this->isWellFormed($a) && $this->isWellFormed($b));
    }

    // 4. a log line carries the id -------------------------------------------
    public function testLogLineCarriesTheRequestId(): void
    {
        $res = (new TestClient())->get('/rid-log', headers: ['X-Request-ID' => 'corr-9f8e7d']);
        $this->assertSame('corr-9f8e7d', $this->header($res, 'X-Request-ID'));

        $logText = file_get_contents($this->tempDir . '/tina4.log');
        $markerLines = array_filter(
            explode("\n", $logText),
            static fn($line) => str_contains($line, 'rid-log-marker')
        );
        $this->assertNotEmpty($markerLines, 'the handler log line was never written to the file');
        $carries = false;
        foreach ($markerLines as $line) {
            if (str_contains($line, '[corr-9f8e7d]')) {
                $carries = true;
                break;
            }
        }
        $this->assertTrue($carries, 'the log line does not carry the request id: ' . implode(' | ', $markerLines));
    }

    // Direct, no-dependency unit checks of the sanitizer (a pure function). The
    // tight mutation target: drop the disallowed-char test and every hostile
    // case reflects raw; drop the length cap and the over-long case reflects.
    public function testSanitizerHonoursWellFormedIds(): void
    {
        foreach (['abc123', 'trace-id_9.8', str_repeat('A', 128), '0', 'x.y-z_1'] as $good) {
            $this->assertSame($good, Log::sanitizeRequestId($good), "should honour {$good}");
        }
    }

    public function testSanitizerRejectsHostileOrMalformedIds(): void
    {
        $cases = [
            "abc\r\nSet-Cookie: x=1",   // CRLF header injection
            "abc\rinject",              // bare CR
            "abc\ninject",              // bare LF
            'has space',                // space not allowed
            'semi;colon',               // ; not allowed
            'curl|bash',                // pipe not allowed
            '<script>',                 // angle brackets not allowed
            str_repeat('a', 129),       // over the length cap
            '',                         // empty
            null,                       // absent
        ];
        foreach ($cases as $bad) {
            $this->assertNull(Log::sanitizeRequestId($bad), 'should reject ' . var_export($bad, true));
        }
    }
}
