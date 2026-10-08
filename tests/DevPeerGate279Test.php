<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;

/**
 * Regression for tina4-php#279: the dev-admin peer gate behind a non-loopback
 * peer (a Docker dev box publishes its port, so the browser's requests arrive
 * from the container-network gateway, never loopback).
 *
 * Two layers, no mocks:
 *   - ipInCidr(): pure IP/CIDR matching (v4, v6, mapped, boundaries) — always runs.
 *   - A REAL `php -S 0.0.0.0:<port>` reached over the host's own non-loopback
 *     IPv4, so App reads a genuine non-loopback REMOTE_ADDR. Skipped only when
 *     the host has no non-loopback IPv4 (a loopback-only CI box).
 *
 * Expected (what 3.13.136 did and the gate must restore):
 *   - /__dev/toolbar.css and /__dev/toolbar.js load for any peer (static, no
 *     secrets) — they must not sit behind the peer gate.
 *   - /__dev stays 403 for a non-loopback peer by default (the security boundary).
 *   - TINA4_DEV_ALLOWED_PEERS admits that raw peer to /__dev (explicit opt-in).
 *   - the toolbar is NOT injected into a page whose viewer the gate would refuse.
 */
class DevPeerGate279Test extends TestCase
{
    /** @var array<int, resource> */
    private array $procs = [];
    /** @var string[] */
    private array $logs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            if (is_resource($p)) {
                @proc_terminate($p, 9);
                @proc_close($p);
            }
        }
        foreach ($this->logs as $l) {
            @unlink($l);
        }
        $this->procs = [];
        $this->logs = [];
    }

    // ── ipInCidr(): pure, always runs ────────────────────────────────────────

    public function testIpInCidrMatchesV4ExactAndRange(): void
    {
        $this->assertTrue(DevAdmin::ipInCidr('172.22.0.1', '172.22.0.1'), 'bare IP == /32');
        $this->assertTrue(DevAdmin::ipInCidr('172.22.0.1', '172.16.0.0/12'), 'Docker default bridge range');
        $this->assertTrue(DevAdmin::ipInCidr('192.168.88.148', '192.168.0.0/16'));
        $this->assertFalse(DevAdmin::ipInCidr('10.0.0.5', '172.16.0.0/12'), 'outside the range');
        $this->assertFalse(DevAdmin::ipInCidr('172.32.0.1', '172.16.0.0/12'), 'just past /12');
    }

    public function testIpInCidrV6AndMappedAndFamilyMismatch(): void
    {
        $this->assertTrue(DevAdmin::ipInCidr('fd00::1', 'fd00::/8'));
        $this->assertTrue(DevAdmin::ipInCidr('::ffff:172.22.0.1', '172.16.0.0/12'), 'IPv4-mapped peer matched as IPv4');
        $this->assertFalse(DevAdmin::ipInCidr('172.22.0.1', 'fd00::/8'), 'v4 peer never matches a v6 CIDR');
        $this->assertFalse(DevAdmin::ipInCidr('not-an-ip', '172.16.0.0/12'));
        $this->assertFalse(DevAdmin::ipInCidr('172.22.0.1', 'garbage/12'));
    }

    // ── Real non-loopback peer over a real php -S ────────────────────────────

    public function testToolbarAssetsServedButDashboardGatedThenOptInAdmits(): void
    {
        $peer = $this->nonLoopbackIpv4();
        if ($peer === null) {
            $this->markTestSkipped('no non-loopback IPv4 on this host (loopback-only CI box)');
        }

        // Without the opt-in: assets 200, dashboard 403, no toolbar injected.
        $port = $this->startServer([]);
        $base = "http://{$peer}:{$port}";
        $host = "localhost:{$port}";

        $this->assertSame(200, $this->httpStatus("{$base}/__dev/toolbar.js", $host, $port),
            'toolbar.js is a static asset and must load for any peer');
        $this->assertSame(200, $this->httpStatus("{$base}/__dev/toolbar.css", $host, $port),
            'toolbar.css is a static asset and must load for any peer');
        $this->assertSame(403, $this->httpStatus("{$base}/__dev", $host, $port),
            'the dashboard stays refused for a non-loopback peer by default');
        $this->assertStringNotContainsString('tina4-dev-toolbar', $this->httpBody("{$base}/", $host, $port),
            'a page for a refused viewer must NOT carry toolbar markup');

        // A loopback viewer of the SAME server still gets the toolbar.
        $this->assertStringContainsString('tina4-dev-toolbar',
            $this->httpBody("http://127.0.0.1:{$port}/", "localhost:{$port}", $port),
            'a loopback viewer still gets the toolbar');

        // With the opt-in: the raw peer reaches the dashboard and gets the toolbar.
        $port2 = $this->startServer(['TINA4_DEV_ALLOWED_PEERS' => $peer]);
        $base2 = "http://{$peer}:{$port2}";
        $host2 = "localhost:{$port2}";
        $this->assertSame(200, $this->httpStatus("{$base2}/__dev", $host2, $port2),
            'TINA4_DEV_ALLOWED_PEERS admits the raw peer to the dashboard');
        $this->assertStringContainsString('tina4-dev-toolbar', $this->httpBody("{$base2}/", $host2, $port2),
            'an admitted peer gets the toolbar injected');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,string> $extraEnv */
    private function startServer(array $extraEnv): int
    {
        $port = $this->freePort();
        $env = getenv();
        $env['TINA4_NO_BROWSER'] = 'true';
        $env['TINA4_SUPPRESS'] = 'true';
        $env['TINA4_DEBUG'] = 'true';
        $env['TINA4_AUTO_MIGRATE'] = 'false';
        $env['TINA4_SECRET'] = bin2hex(random_bytes(32));
        foreach ($extraEnv as $k => $v) {
            $env[$k] = $v;
        }

        $fixture = __DIR__ . '/fixtures/dev_peer_gate_279_app.php';
        $log = tempnam(sys_get_temp_dir(), 'tina4-279-');
        $this->logs[] = $log;
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 0.0.0.0:' . $port . ' ' . escapeshellarg($fixture);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($fixture),
            $env
        );
        $this->assertIsResource($proc, 'could not start php -S');
        $this->procs[] = $proc;

        for ($i = 0; $i < 200; $i++) {
            $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 0.1);
            if ($c !== false) {
                fclose($c);
                return $port;
            }
            usleep(25000);
        }
        $this->fail('php -S never accepted on port ' . $port . ': ' . (string) @file_get_contents($log));
    }

    private function httpStatus(string $url, string $host, int $port): int
    {
        [$code] = $this->curl($url, $host);
        return $code;
    }

    private function httpBody(string $url, string $host, int $port): string
    {
        [, $body] = $this->curl($url, $host);
        return $body;
    }

    /** @return array{0:int,1:string} */
    private function curl(string $url, string $host): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Host: {$host}"],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // curl_close() is a no-op and deprecated since PHP 8.5
        return [$code, $body];
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) explode(':', $name)[1];
    }

    /** The host's own non-loopback IPv4, via the kernel's default-route choice. */
    private function nonLoopbackIpv4(): ?string
    {
        if (!function_exists('socket_create')) {
            return null;
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return null;
        }
        // UDP connect sends nothing; it just sets the default peer so the kernel
        // picks (and getsockname reveals) the local address it would route from.
        @socket_connect($sock, '8.8.8.8', 53);
        $addr = '';
        @socket_getsockname($sock, $addr);
        @socket_close($sock);
        if ($addr === '' || str_starts_with($addr, '127.') || $addr === '0.0.0.0') {
            return null;
        }
        return $addr;
    }
}
