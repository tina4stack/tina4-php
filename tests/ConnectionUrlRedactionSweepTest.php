<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Every error message that names a connection URL must pass it through
 * \Tina4\DatabaseUrl::redact first. These are the places the sweep found that
 * echoed the raw URL, password included:
 *
 *   - Mqtt::parseUrl()   "malformed MQTT url '<raw>'" and the unsupported-scheme error
 *   - Api (https URL when PHP has no https wrapper) "(requested <url>)"
 *
 * The MQTT cases are pure functions over their input (no dependency, no double).
 * The Api case runs in a child PHP process whose runtime genuinely has no
 * "https" stream wrapper, which is the exact condition the error reports.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Mqtt;

final class ConnectionUrlRedactionSweepTest extends TestCase
{
    private const SECRET = 'mqtt-s3cret-pw';

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedMqttUrls(): array
    {
        $secret = self::SECRET;
        return [
            'non-numeric port' => ["mqtt://alice:{$secret}@broker.example:abc", 'port must be numeric'],
            'empty host' => ["mqtt://alice:{$secret}@:1883", 'expected mqtt://host:port'],
            'unclosed ipv6 bracket' => ["mqtt://alice:{$secret}@[::1:1883", 'unclosed IPv6 bracket'],
            'unsupported scheme' => ["ws://alice:{$secret}@broker.example:1883", "unsupported MQTT url scheme 'ws'"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedMqttUrls')]
    public function testMalformedMqttUrlErrorNeverContainsThePassword(string $url, string $reason): void
    {
        try {
            Mqtt::parseUrl($url);
            $this->fail("parseUrl accepted a malformed url: {$reason}");
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString($reason, $error->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $error->getMessage());
        }
    }

    public function testMalformedMqttUrlErrorStillNamesTheUserAndHost(): void
    {
        try {
            Mqtt::parseUrl('mqtt://alice:' . self::SECRET . '@broker.example:abc');
            $this->fail('parseUrl accepted a non-numeric port');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('mqtt://alice:***@broker.example:abc', $error->getMessage());
        }
    }

    public function testHttpsUnavailableErrorNeverContainsTheUrlPassword(): void
    {
        $script = sys_get_temp_dir() . '/tina4-https-unavailable-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, <<<'PHP'
<?php
require getenv('CHILD_AUTOLOAD');
// The runtime this error describes: no https stream wrapper registered.
stream_wrapper_unregister('https');
if (in_array('https', stream_get_wrappers(), true)) {
    fwrite(STDOUT, "HTTPS-STILL-REGISTERED\n");
    exit(2);
}
$api = new \Tina4\Api('https://alice:api-s3cret-pw@api.example.invalid');
$result = $api->get('/items');
fwrite(STDOUT, json_encode(['error' => $result['error'] ?? null]) . "\n");
try {
    foreach ($api->streamLines('/items') as $line) {}
    fwrite(STDOUT, json_encode(['stream' => 'no error']) . "\n");
} catch (\Throwable $error) {
    fwrite(STDOUT, json_encode(['stream' => $error->getMessage()]) . "\n");
}
PHP);
        $environment = getenv();
        $environment['CHILD_AUTOLOAD'] = dirname(__DIR__) . '/vendor/autoload.php';
        $environment['TINA4_NO_BROWSER'] = 'true';
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($script);

        $this->assertSame(0, $exitCode, "child failed:\n{$stdout}\n{$stderr}");
        $lines = array_values(array_filter(array_map(
            fn($line) => json_decode($line, true),
            explode("\n", trim($stdout))
        ), 'is_array'));
        $this->assertCount(2, $lines, "unexpected child output:\n{$stdout}\n{$stderr}");
        foreach ([$lines[0]['error'], $lines[1]['stream']] as $message) {
            $this->assertIsString($message);
            $this->assertStringContainsString('Outbound HTTPS is unavailable', $message);
            $this->assertStringContainsString('alice:***@api.example.invalid', $message);
            $this->assertStringNotContainsString('api-s3cret-pw', $message);
        }
    }
}
