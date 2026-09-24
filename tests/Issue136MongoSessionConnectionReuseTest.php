<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

/**
 * tina4-python#136 parity: with TINA4_SESSION_BACKEND=mongodb every request
 * built a Session, every Session built a handler, and every handler opened a
 * NEW MongoDB client that was never closed.
 *
 * PHP's MongoSessionHandler speaks the wire protocol over its own fsockopen()
 * socket, and Session::getMongoHandler() builds a new handler per Session - so
 * every request opened a NEW TCP connection to MongoDB. The contract: one
 * connection per process, reused by every request that process serves.
 *
 * Measured for real in a long-lived process - Tina4's own socket server with
 * TINA4_SERVE_FORK=false, so one php process serves every request, as a pool
 * worker, Swoole or RoadRunner does - against a real MongoDB, over real HTTP.
 * The route reports every live socket the process holds to the MongoDB port
 * (the kernel's view, through get_resources()), so a connection per request
 * shows up as a new local port per request.
 */

use PHPUnit\Framework\TestCase;

class Issue136MongoSessionConnectionReuseTest extends TestCase
{
    private const DB_NAME = 'tina4_issue136_php';

    private string $mongoUri = '';
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->mongoUri = getenv('TINA4_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        if (preg_match('#^mongodb://([^:/?@]+)(?::(\d+))?#', $this->mongoUri, $m) !== 1) {
            $this->fail("unparseable Mongo URI {$this->mongoUri}");
        }
        $socket = @fsockopen($m[1], (int) ($m[2] ?? 27017), $errNo, $errStr, 2);
        if ($socket === false) {
            $this->markTestSkipped("[needs:mongo] mongo not reachable at {$m[1]}:" . ($m[2] ?? 27017));
        }
        fclose($socket);

        $this->projectDir = sys_get_temp_dir() . '/tina4-issue136-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/routes', 0777, true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        // The route counts the process's live sockets to the MongoDB port after
        // the session has been read, so the connection the handler is using is
        // open at that moment.
        file_put_contents($this->projectDir . '/src/routes/visits.php', <<<'PHP'
<?php
\Tina4\Router::get('/visits', function ($request, $response) {
    $visits = (int) ($request->session->get('visits') ?? 0) + 1;
    $request->session->set('visits', $visits);
    $ports = [];
    foreach (get_resources('stream') as $stream) {
        $peer = @stream_socket_get_name($stream, true);
        if (is_string($peer) && str_ends_with($peer, ':' . getenv('ISSUE136_MONGO_PORT'))) {
            $ports[] = (string) stream_socket_get_name($stream, false);
        }
    }
    return $response->json(['visits' => $visits, 'mongo_sockets' => $ports]);
});
\Tina4\Router::get('/sever', function ($request, $response) {
    // Sever every live MongoDB connection this process holds - a real broken
    // socket, as after a MongoDB restart or an idle timeout.
    foreach (get_resources('stream') as $stream) {
        $peer = @stream_socket_get_name($stream, true);
        if (is_string($peer) && str_ends_with($peer, ':' . getenv('ISSUE136_MONGO_PORT'))) {
            stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
        }
    }
    return $response->json(['severed' => true]);
});
PHP);

        file_put_contents(
            $this->projectDir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "(new \\Tina4\\App(basePath: __DIR__))->run('127.0.0.1', (int) \$argv[1]);\n"
        );
    }

    protected function tearDown(): void
    {
        if (class_exists('MongoDB\\Driver\\Manager')) {
            try {
                (new \MongoDB\Driver\Manager($this->mongoUri))
                    ->executeCommand(self::DB_NAME, new \MongoDB\Driver\Command(['dropDatabase' => 1]));
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    /**
     * Serve the project from ONE long-lived process and send a run of requests
     * to it, carrying the session cookie from each response into the next.
     *
     * @param int $requests How many /visits requests to send
     * @param int $severAfter Sever the process's MongoDB connections after this request (0 = never)
     * @return list<array{visits: int, mongo_sockets: list<string>}>
     */
    private function serveRequests(int $requests, int $severAfter = 0): array
    {
        preg_match('#^mongodb://[^:/?@]+(?::(\d+))?#', $this->mongoUri, $m);
        $server = \TestServer::startScript($this->projectDir . '/index.php', [], [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_SERVE_FORK' => 'false',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_SESSION_BACKEND' => 'mongodb',
            'TINA4_SESSION_MONGO_URI' => $this->mongoUri,
            'TINA4_SESSION_MONGO_DB' => self::DB_NAME,
            'ISSUE136_MONGO_PORT' => (string) ($m[1] ?? 27017),
        ], $this->projectDir);

        try {
            $cookie = '';
            $results = [];
            for ($i = 1; $i <= $requests; $i++) {
                $context = stream_context_create(['http' => [
                    'header' => ($cookie === '' ? '' : "Cookie: {$cookie}\r\n") . "Connection: close\r\n",
                    'ignore_errors' => true,
                    'timeout' => 15,
                ]]);
                $body = @file_get_contents($server->base() . '/visits', false, $context);
                foreach ($http_response_header ?? [] as $line) {
                    if (preg_match('/^Set-Cookie:\s*(tina4_session=[^;]+)/i', $line, $c) === 1) {
                        $cookie = $c[1];
                    }
                }
                $decoded = json_decode((string) $body, true);
                $this->assertIsArray($decoded, "request {$i}: " . $body . ' ' . $server->log());
                $results[] = $decoded;
                if ($i === $severAfter) {
                    @file_get_contents($server->base() . '/sever', false, $context);
                }
            }
            return $results;
        } finally {
            $server->stop();
        }
    }

    public function testOneMongoConnectionServesEveryRequestInALongLivedProcess(): void
    {
        $results = $this->serveRequests(12);

        // Instrument check: the session really went through MongoDB and round-tripped.
        $this->assertSame(range(1, 12), array_column($results, 'visits'),
            'the session counter must persist request to request through MongoDB');

        $perRequest = array_map(static fn(array $r): int => count($r['mongo_sockets']), $results);
        $this->assertSame(array_fill(0, 12, 1), $perRequest,
            'the process must hold exactly ONE live MongoDB connection at every request');

        $localPorts = array_unique(array_merge(...array_column($results, 'mongo_sockets')));
        $this->assertCount(1, $localPorts,
            'every request must reuse the SAME connection (one local port), not open a new one: '
            . implode(', ', $localPorts));
    }

    public function testABrokenSharedConnectionIsReplacedAndTheSessionSurvives(): void
    {
        $results = $this->serveRequests(6, 3);

        $this->assertSame(range(1, 6), array_column($results, 'visits'),
            'after the shared connection is severed the next request must reconnect and keep the session');

        $before = array_unique(array_merge(...array_column(array_slice($results, 0, 3), 'mongo_sockets')));
        $after = array_unique(array_merge(...array_column(array_slice($results, 3), 'mongo_sockets')));
        $this->assertCount(1, $before);
        $this->assertCount(1, $after, 'one replacement connection, reused from then on');
        $this->assertNotSame($before, $after, 'the severed connection must have been replaced');
    }
}
