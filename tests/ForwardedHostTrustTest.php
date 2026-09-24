<?php
/*
Copyright (c) 2026 Code Infinity
SPDX-License-Identifier: MPL-2.0
This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
*/

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Medium security finding F6 — X-Forwarded-Host must only be honoured when the
 * raw socket peer is a trusted proxy (TINA4_TRUSTED_PROXIES, ADR-0019).
 *
 * An untrusted client can otherwise forge X-Forwarded-Host and control the
 * absolute request->url the app builds — the base for password-reset links,
 * cache keys and open-redirect targets. The existing trusted-proxy gate covered
 * X-Forwarded-For only; this pins the same rule for the host. Case names match
 * the sibling regressions in tina4-nodejs/test/forwardedHostTrust.test.ts,
 * tina4-python/tests/test_forwarded_host_trust.py and
 * tina4-ruby/spec/forwarded_host_trust_spec.rb.
 *
 * Real HTTP requests with a real socket peer (127.0.0.1). No mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\TrustedProxy;

class ForwardedHostTrustTest extends TestCase
{
    private const TEST_PEER = '127.0.0.1';

    protected function tearDown(): void
    {
        unset($_ENV['TINA4_TRUSTED_PROXIES']);
        putenv('TINA4_TRUSTED_PROXIES');
        TrustedProxy::reset();
    }

    private function setTrusted(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['TINA4_TRUSTED_PROXIES']);
            putenv('TINA4_TRUSTED_PROXIES');
        } else {
            $_ENV['TINA4_TRUSTED_PROXIES'] = $value;
            putenv("TINA4_TRUSTED_PROXIES={$value}");
        }
        TrustedProxy::reset();
    }

    private function requestUrl(string $forwardedHost): string
    {
        $server = TestServer::start(__DIR__ . '/fixtures/forwarded_host_server.php', array_merge(getenv(), [
            'TINA4_TRUSTED_PROXIES' => getenv('TINA4_TRUSTED_PROXIES') ?: '',
        ]));
        try {
            return file_get_contents($server->base() . '/reset-link', false, stream_context_create(['http' => [
                'header' => "X-Forwarded-Host: {$forwardedHost}\r\nX-Forwarded-Proto: https\r\n",
            ]]));
        } finally {
            $server->stop();
        }
    }

    public function testForwardedHostAndProtoShareRawPeerTrust(): void
    {
        $this->setTrusted(null);
        $url = $this->requestUrl('evil.com');
        $this->assertStringStartsWith('http://127.0.0.1:', $url);
        $this->checkTrustedProxy();
        $this->assertStringNotContainsString(
            'evil.com',
            $url,
            'forged X-Forwarded-Host leaked into request->url'
        );
    }

    private function checkTrustedProxy(): void
    {
        $this->setTrusted('127.0.0.1/8');
        $url = $this->requestUrl('app.example.com');
        $this->assertStringStartsWith('https://app.example.com/', $url);
        $this->assertStringContainsString(
            'app.example.com',
            $url,
            'forwarded host not honoured behind a trusted proxy'
        );
    }
}
