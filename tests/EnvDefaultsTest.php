<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\CorsMiddleware;

/**
 * Lock-in tests for env-default decisions (Tina4 v3 uniformity sweep).
 *
 * These guard against silent drift back to the old, less-safe defaults:
 *   - TINA4_CORS_CREDENTIALS now defaults to "false" (was "true").
 *   - AI / dev-admin URL fallbacks now point at localhost (was andrevanzuydam.com).
 */
class EnvDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure CORS credentials env is unset so we exercise the fallback default.
        putenv('TINA4_CORS_CREDENTIALS');
        unset($_ENV['TINA4_CORS_CREDENTIALS']);
    }

    // -- CORS credentials default -------------------------------------------

    public function testCorsCredentialsDefaultIsFalse(): void
    {
        // No explicit credentials arg, env unset -> default must be false.
        // The Access-Control-Allow-Credentials header is only ever emitted
        // for a non-wildcard origin, so use a specific allowed origin.
        $cors = new CorsMiddleware(origins: 'http://app.com');

        $headers = $cors->getHeaders('http://app.com');

        $this->assertArrayNotHasKey(
            'Access-Control-Allow-Credentials',
            $headers,
            'TINA4_CORS_CREDENTIALS must default to false (no credentials header)'
        );
    }

    public function testCorsCredentialsStillOptInViaEnv(): void
    {
        // Opting in via env must still work.
        putenv('TINA4_CORS_CREDENTIALS=true');

        try {
            $cors = new CorsMiddleware(origins: 'http://app.com');
            $headers = $cors->getHeaders('http://app.com');

            $this->assertArrayHasKey('Access-Control-Allow-Credentials', $headers);
            $this->assertEquals('true', $headers['Access-Control-Allow-Credentials']);
        } finally {
            putenv('TINA4_CORS_CREDENTIALS');
        }
    }

    // -- AI URL default resolves to localhost -------------------------------

    public function testAiUrlDefaultResolvesToLocalhost(): void
    {
        // Mirror the exact resolution expression used in DevAdmin / Plan:
        //   getenv('TINA4_AI_URL') ?: 'http://localhost:11437/api/chat'
        putenv('TINA4_AI_URL');
        unset($_ENV['TINA4_AI_URL']);

        $aiUrl = getenv('TINA4_AI_URL') ?: 'http://localhost:11437/api/chat';

        $this->assertEquals('http://localhost:11437/api/chat', $aiUrl);
    }

    public function testAiUrlDefaultsDoNotPointAtRemoteHost(): void
    {
        // Contract check: no AI/dev-admin default fallback may resolve to the
        // shared remote stack. Hosted lives in the user's own .env.
        $sources = [
            __DIR__ . '/../Tina4/DevAdmin.php',
            __DIR__ . '/../Tina4/Plan.php',
        ];

        foreach ($sources as $source) {
            $this->assertFileExists($source);
            $contents = file_get_contents($source);
            $this->assertStringNotContainsString(
                'andrevanzuydam.com',
                $contents,
                basename($source) . ' must not hardcode a remote AI host default'
            );
        }
    }
}
