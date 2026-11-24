<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * GHSA-h2mr-m3v3-cq8c: a connection URL whose password holds / ? # @ % or a
 * space, that has one slash or none after the scheme, that has no host, or
 * whose port is not a number, put the password (or a piece of it) into the
 * redacted string, the parse error, the connect error and the log.
 *
 * The cases are SHARED by all four frameworks
 * (tests/fixtures/db_url_redaction_cases.json). Every password x template x
 * scheme is pushed through the REAL code - DatabaseUrl::redact(), the real
 * URL parsers, and the real Database / adapter / queue / backplane connect
 * path against a closed 127.0.0.1 port - and every output is searched for a
 * secret fragment: the returned string, getMessage() of the whole
 * getPrevious() chain, and everything the process printed or logged.
 *
 * Nothing is mocked. The connect sweep runs in child processes so that the
 * console log, PHP warnings and the log FILE are all captured, and so the
 * slow refused-connect round trips of each scheme run side by side.
 */

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;
use Tina4\Graph\GraphUrl;
use Tina4\Mqtt;
use Tina4\Queue;

final class DatabaseUrlRedactionCasesTest extends TestCase
{
    /** Every scheme Database accepts that can carry credentials. */
    private const DB_SCHEMES = [
        'postgres', 'postgresql', 'pgsql', 'mysql', 'mssql', 'sqlserver',
        'firebird', 'mongodb', 'pymongo', 'odbc',
    ];

    /** Schemes DatabaseUrl (the struct parser) accepts with credentials. */
    private const DATABASE_URL_SCHEMES = [
        'postgres', 'postgresql', 'pgsql', 'mysql', 'mssql', 'sqlserver', 'firebird',
    ];

    private const AMQP_SCHEMES = ['amqp', 'amqps'];
    private const MQTT_SCHEMES = ['mqtt', 'mqtts', 'tcp'];
    private const GRAPH_SCHEMES = [
        'ultipa', 'ultipas', 'neo4j', 'neo4j+s', 'bolt', 'bolt+s', 'memgraph', 'arango', 'arangodb',
    ];
    private const BACKPLANE_SCHEMES = ['redis', 'valkey', 'tcp'];
    private const KAFKA_SCHEMES = ['kafka'];

    /** @var array<string, mixed>|null */
    private static ?array $cases = null;

    /** @return array<string, mixed> */
    private static function cases(): array
    {
        if (self::$cases === null) {
            $json = file_get_contents(__DIR__ . '/fixtures/db_url_redaction_cases.json');
            self::$cases = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$cases;
    }

    /**
     * @param list<string> $schemes
     * @return list<array{scheme: string, template: string, password: string, url: string}>
     */
    private static function urls(array $schemes): array
    {
        $out = [];
        foreach ($schemes as $scheme) {
            foreach (self::cases()['templates'] as $template) {
                foreach (self::cases()['passwords'] as $password) {
                    $out[] = [
                        'scheme' => $scheme,
                        'template' => $template,
                        'password' => $password,
                        'url' => str_replace(['{scheme}', '{pw}'], [$scheme, $password], $template),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * The fragments that must not appear for this case.
     *
     * ONE combination is structurally ambiguous and is NOT a leak:
     * "scheme://u:Pq1@Rs2" (the no-host template with the password "Pq1@Rs2")
     * IS, by the URL grammar, user "u", password "Pq1", host "Rs2" - there is
     * no other reading of it. The host is shown by design (a redacted line
     * keeps the host), so only the part that is the password, "Pq1", is
     * checked there. Every other combination is checked for every fragment.
     *
     * @return list<string>
     */
    private static function fragmentsFor(string $template, string $password): array
    {
        if ($template === '{scheme}://u:{pw}' && $password === 'Pq1@Rs2') {
            return ['Pq1'];
        }
        return self::cases()['secret_fragments'];
    }

    /** @var list<string> Every leak found by the running test, reported together. */
    private array $leaks = [];

    /**
     * Record (rather than stop at) a leak, so one run names EVERY leaking case
     * and the failure count is the number of leaking cases, not "the first".
     */
    private function assertNoSecret(string $output, string $template, string $password, string $context): void
    {
        foreach (self::fragmentsFor($template, $password) as $fragment) {
            if (str_contains($output, $fragment)) {
                $this->leaks[] = "{$context}: secret fragment '{$fragment}' leaked in: "
                    . str_replace("\n", ' | ', trim($output));
                return;
            }
        }
        $this->addToAssertionCount(1);
    }

    private function assertNothingLeaked(): void
    {
        $this->assertSame(
            [],
            $this->leaks,
            count($this->leaks) . " leaking case(s):\n" . implode("\n", $this->leaks)
        );
    }

    /** Class and message of the exception and of every getPrevious(). */
    private static function chainText(\Throwable $error): string
    {
        $text = '';
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            $text .= get_class($e) . ': ' . $e->getMessage() . "\n";
        }
        return $text;
    }

    // -- 1. the string-level redaction primitive ---------------------------------

    public function testRedactNeverPrintsThePasswordAndKeepsSchemeUserAndHost(): void
    {
        $schemes = array_merge(
            ['sqlite'],
            self::DB_SCHEMES,
            self::AMQP_SCHEMES,
            self::MQTT_SCHEMES,
            self::GRAPH_SCHEMES,
            self::BACKPLANE_SCHEMES,
            self::KAFKA_SCHEMES,
        );
        $checked = 0;
        $lost = [];
        foreach (self::urls(array_values(array_unique($schemes))) as $case) {
            $redacted = DatabaseUrl::redact($case['url']);
            $this->assertNoSecret($redacted, $case['template'], $case['password'], "redact({$case['url']})");

            // Still useful: the scheme, the user and the host (where there is one) survive.
            if (!str_starts_with($redacted, $case['scheme'])) {
                $lost[] = "redact({$case['url']}) lost the scheme: {$redacted}";
            }
            if (!str_contains($redacted, 'u:' . DatabaseUrl::REDACTED)) {
                $lost[] = "redact({$case['url']}) did not keep the user beside a masked password: {$redacted}";
            }
            if (str_contains($case['template'], '@dbhost') && !str_contains($redacted, 'dbhost')) {
                $lost[] = "redact({$case['url']}) lost the host: {$redacted}";
            }
            $checked++;
        }
        $this->assertSame(
            count(self::cases()['templates']) * count(self::cases()['passwords']) * count(array_unique($schemes)),
            $checked
        );
        $this->assertNothingLeaked();
        $this->assertSame([], $lost, count($lost) . " case(s) lost a part:\n" . implode("\n", $lost));
    }

    // -- 2. the real parsers -------------------------------------------------------

    public function testDatabaseUrlNeverPrintsThePassword(): void
    {
        foreach (self::urls(self::DATABASE_URL_SCHEMES) as $case) {
            $context = "new DatabaseUrl({$case['url']})";
            try {
                $parsed = new DatabaseUrl($case['url']);
            } catch (\Throwable $error) {
                $this->assertNoSecret(self::chainText($error), $case['template'], $case['password'], $context);
                continue;
            }
            // Parsed: every rendering, and every non-secret field, is clean.
            $rendered = implode("\n", [
                $parsed->toSafeString(),
                (string) json_encode($parsed),
                print_r($parsed, true),
                (string) $parsed->host,
                (string) $parsed->username,
                $parsed->database,
                $parsed->getDsn(),
            ]);
            $this->assertNoSecret($rendered, $case['template'], $case['password'], $context);
        }
        $this->assertNothingLeaked();
    }

    public function testAmqpMqttAndGraphParsersNeverPrintThePassword(): void
    {
        foreach (self::urls(self::AMQP_SCHEMES) as $case) {
            $context = "Queue::parseAmqpUrl({$case['url']})";
            try {
                $config = Queue::parseAmqpUrl($case['url']);
            } catch (\Throwable $error) {
                $this->assertNoSecret(self::chainText($error), $case['template'], $case['password'], $context);
                continue;
            }
            // The password field holds the password by design; nothing else may.
            unset($config['password']);
            $this->assertNoSecret(json_encode($config) ?: '', $case['template'], $case['password'], $context);
        }

        foreach (self::urls(self::MQTT_SCHEMES) as $case) {
            $context = "Mqtt::parseUrl({$case['url']})";
            try {
                $config = Mqtt::parseUrl($case['url']);
            } catch (\Throwable $error) {
                $this->assertNoSecret(self::chainText($error), $case['template'], $case['password'], $context);
                continue;
            }
            unset($config['password']);
            $this->assertNoSecret(json_encode($config) ?: '', $case['template'], $case['password'], $context);
        }

        foreach (self::urls(self::GRAPH_SCHEMES) as $case) {
            $context = "new GraphUrl({$case['url']})";
            try {
                $graph = new GraphUrl($case['url']);
            } catch (\Throwable $error) {
                $this->assertNoSecret(self::chainText($error), $case['template'], $case['password'], $context);
                continue;
            }
            $rendered = implode("\n", [
                $graph->getDsn(),
                $graph->host,
                (string) $graph->username,
                (string) $graph->graph,
                json_encode($graph->params) ?: '',
            ]);
            $this->assertNoSecret($rendered, $case['template'], $case['password'], $context);
        }
        $this->assertNothingLeaked();
    }

    public function testMustStillParseEntriesParseToTheirParts(): void
    {
        foreach (self::cases()['must_still_parse'] as $expected) {
            $url = new DatabaseUrl($expected['url']);
            $this->assertSame($expected['host'], $url->host, $expected['url']);
            $this->assertSame($expected['port'] ?? 5432, $url->port, $expected['url']);
            $this->assertSame($expected['user'], $url->username, $expected['url']);
            $this->assertSame($expected['password'], $url->password, $expected['url']);
            $this->assertSame($expected['database'], $url->database, $expected['url']);

            // The same shape through the other parsers that share the rule.
            $graphUrl = 'neo4j' . substr($expected['url'], strlen('postgres'));
            $graph = new GraphUrl($graphUrl);
            $this->assertSame($expected['host'], $graph->host, $graphUrl);
            $this->assertSame($expected['user'], $graph->username, $graphUrl);
            $this->assertSame($expected['password'], $graph->password, $graphUrl);
            $this->assertSame($expected['database'], $graph->graph, $graphUrl);

            $mqttUrl = 'mqtt' . substr($expected['url'], strlen('postgres'));
            $mqtt = Mqtt::parseUrl($mqttUrl);
            $this->assertSame($expected['host'], $mqtt['host'], $mqttUrl);
            $this->assertSame($expected['user'], $mqtt['username'], $mqttUrl);
            $this->assertSame($expected['password'], $mqtt['password'], $mqttUrl);

            $amqpUrl = 'amqp' . substr($expected['url'], strlen('postgres'));
            $amqp = Queue::parseAmqpUrl($amqpUrl);
            $this->assertSame($expected['host'], $amqp['host'], $amqpUrl);
            $this->assertSame($expected['user'], $amqp['username'], $amqpUrl);
        }
    }

    /**
     * PHP-specific (not in the shared list): the documented behaviour change.
     * An '@' the parser cannot take as the userinfo delimiter - one in the
     * query, with or without credentials - is refused, and the refusal never
     * contains the value.
     */
    public function testAnUnencodedAtOutsideTheCredentialsIsRefusedWithoutEchoingTheValue(): void
    {
        foreach ([
            'postgres://dbhost/appdb?application_name=Pq1@Rs2',
            'postgres://u:Pq1@dbhost/appdb?application_name=x@Rs2',
        ] as $url) {
            try {
                new DatabaseUrl($url);
                $this->fail("an unencoded '@' outside the credentials was accepted: {$url}");
            } catch (\InvalidArgumentException $error) {
                $text = self::chainText($error);
                $this->assertStringContainsString('%40', $text);
                foreach (self::cases()['secret_fragments'] as $fragment) {
                    $this->assertStringNotContainsString($fragment, $text);
                }
            }
        }
        // Encoded, the same values parse.
        $parsed = new DatabaseUrl('postgres://u:Pq1%40Rs2@dbhost/appdb?application_name=x%40y');
        $this->assertSame('Pq1@Rs2', $parsed->password);
        $this->assertSame('dbhost', $parsed->host);
    }

    // -- 3. the real connect paths, in child processes ---------------------------

    /**
     * Entry point name => schemes it is driven with. Each pair runs in its own
     * child process. Hosts are rewritten to 127.0.0.1 and the explicit port to
     * 1 (closed), so every adapter's own connect-failure path runs; PGPORT=1
     * does the same for the libpq default port. Nothing else in the URL - the
     * shape under test - is touched.
     *
     * @return array<string, list<string>>
     */
    private static function connectEntryPoints(): array
    {
        return [
            'database' => self::DB_SCHEMES,
            'PostgresAdapter' => ['postgres', 'postgresql', 'pgsql'],
            'PdoPostgresAdapter' => ['postgres'],
            'MySQLAdapter' => ['mysql'],
            'MSSQLAdapter' => ['mssql', 'sqlserver'],
            'FirebirdAdapter' => ['firebird'],
            'PdoFirebirdAdapter' => ['firebird'],
            'rabbitmq' => self::AMQP_SCHEMES,
            'kafka' => self::KAFKA_SCHEMES,
            'backplane' => self::BACKPLANE_SCHEMES,
        ];
    }

    public function testConnectPathsNeverPrintThePasswordToAnErrorOrTheLog(): void
    {
        $root = sys_get_temp_dir() . '/tina4-ghsa-h2mr-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $script = $root . '/child.php';
        file_put_contents($script, self::childScript());
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $fixture = __DIR__ . '/fixtures/db_url_redaction_cases.json';

        $children = [];
        foreach (self::connectEntryPoints() as $entry => $schemes) {
            foreach ($schemes as $scheme) {
                $dir = "{$root}/{$entry}-" . preg_replace('/\W/', '_', $scheme);
                mkdir($dir . '/logs', 0777, true);
                $env = array_merge(getenv(), [
                    'TINA4_DATABASE_CONNECT_TIMEOUT' => '1',
                    'PGPORT' => '1',
                    'TINA4_LOG_DIR' => $dir . '/logs',
                    'TINA4_LOG_LEVEL' => 'ALL',
                    'TINA4_DATABASE_URL' => '',
                    'TINA4_QUEUE_URL' => '',
                ]);
                $process = proc_open(
                    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $script, $autoload, $fixture, $entry, $scheme],
                    [
                        0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
                        1 => ['file', $dir . '/out.txt', 'w'],
                        2 => ['redirect', 1],
                    ],
                    $pipes,
                    $dir,
                    $env
                );
                $this->assertIsResource($process, "could not start the {$entry}/{$scheme} child");
                $children[] = ['entry' => $entry, 'scheme' => $scheme, 'dir' => $dir, 'process' => $process];
            }
        }

        $deadline = time() + 900;
        foreach ($children as &$child) {
            while (proc_get_status($child['process'])['running'] && time() < $deadline) {
                usleep(200000);
            }
            if (proc_get_status($child['process'])['running']) {
                proc_terminate($child['process']);
                $child['timedOut'] = true;
            }
            proc_close($child['process']);
        }
        unset($child);

        // Read everything the children printed or logged, then remove the
        // scratch tree before asserting (it holds the secret fragments).
        foreach ($children as &$child) {
            $child['output'] = (string) @file_get_contents($child['dir'] . '/out.txt');
            // Any log file the child left behind (it drains them per case too).
            foreach (glob($child['dir'] . '/logs/*') ?: [] as $logFile) {
                $child['output'] .= "\n@@LOGFILE@@\n" . file_get_contents($logFile);
            }
        }
        unset($child);
        self::removeTree($root);

        $perUrl = count(self::cases()['templates']) * count(self::cases()['passwords']);
        foreach ($children as $child) {
            $label = "{$child['entry']} / {$child['scheme']}";
            $this->assertFalse($child['timedOut'] ?? false, "{$label}: child timed out");
            $output = $child['output'];

            $this->assertStringContainsString('@@DONE ' . $perUrl . '@@', $output, "{$label}: child did not finish:\n{$output}");

            // Split into per-case blocks so the one ambiguous case can be judged
            // on its own; text outside any block is checked for every fragment.
            $blocks = preg_split('/^@@CASE (\d+)@@$/m', $output, -1, PREG_SPLIT_DELIM_CAPTURE);
            $this->assertIsArray($blocks);
            $this->assertNoSecret((string) $blocks[0], '', '', "{$label} (before the first case)");
            $urls = self::urls([$child['scheme']]);
            for ($i = 1; $i < count($blocks); $i += 2) {
                $case = $urls[(int) $blocks[$i]];
                $this->assertNoSecret((string) $blocks[$i + 1], $case['template'], $case['password'], "{$label} {$case['url']}");
            }
        }
        $this->assertNothingLeaked();
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * The child: drive one entry point with every URL of one scheme, print
     * each outcome (message chain) between case markers, and append whatever
     * the logger wrote to its file during that case.
     */
    private static function childScript(): string
    {
        return <<<'PHP'
<?php
[, $autoload, $fixture, $entry, $scheme] = $argv;
require $autoload;
$cases = json_decode(file_get_contents($fixture), true);
$logDir = getenv('TINA4_LOG_DIR');

$drain = static function () use ($logDir): string {
    $text = '';
    foreach (glob($logDir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            $text .= file_get_contents($file);
            file_put_contents($file, '');
        }
    }
    return $text;
};

$chain = static function (\Throwable $error): string {
    $text = '';
    for ($e = $error; $e !== null; $e = $e->getPrevious()) {
        $text .= get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    return $text;
};

$run = static function (string $entry, string $url): string {
    switch ($entry) {
        case 'database':
            \Tina4\Database\Database::create($url);
            return 'CONNECTED';
        case 'PostgresAdapter':
            new \Tina4\Database\PostgresAdapter($url);
            return 'CONNECTED';
        case 'PdoPostgresAdapter':
            new \Tina4\Database\PdoPostgresAdapter($url);
            return 'CONNECTED';
        case 'MySQLAdapter':
            new \Tina4\Database\MySQLAdapter($url);
            return 'CONNECTED';
        case 'MSSQLAdapter':
            new \Tina4\Database\MSSQLAdapter($url);
            return 'CONNECTED';
        case 'FirebirdAdapter':
            new \Tina4\Database\FirebirdAdapter($url);
            return 'CONNECTED';
        case 'PdoFirebirdAdapter':
            new \Tina4\Database\PdoFirebirdAdapter($url);
            return 'CONNECTED';
        case 'rabbitmq':
            putenv('TINA4_QUEUE_URL=' . $url);
            (new \Tina4\Queue('rabbitmq', [], 'ghsa'))->push(['x' => 1]);
            return 'PUSHED';
        case 'kafka':
            putenv('TINA4_QUEUE_URL=' . $url);
            (new \Tina4\Queue('kafka', [], 'ghsa'))->push(['x' => 1]);
            return 'PUSHED';
        case 'backplane':
            new \Tina4\RedisBackplane($url);
            return 'CONNECTED';
    }
    throw new \LogicException('unknown entry ' . $entry);
};

$i = 0;
foreach ($cases['templates'] as $template) {
    foreach ($cases['passwords'] as $password) {
        $url = str_replace(['{scheme}', '{pw}'], [$scheme, $password], $template);
        // Reach a closed local port; leave the shape under test alone.
        $url = str_replace(['@dbhost:5432', '@dbhost'], ['@127.0.0.1:1', '@127.0.0.1'], $url);
        fwrite(STDOUT, "\n@@CASE {$i}@@\n");
        try {
            $outcome = $run($entry, $url);
        } catch (\Throwable $error) {
            $outcome = $chain($error);
        }
        fwrite(STDOUT, $outcome . "\n" . $drain() . "\n");
        $i++;
    }
}
fwrite(STDOUT, "\n@@DONE {$i}@@\n");
PHP;
    }
}
