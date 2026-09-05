<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Push;
use Tina4\PushError;

/** Feature 140: provider-neutral Web Push contract coverage. */
final class PushTest extends TestCase
{
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$process, $pipes, $root]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($root . '/*') ?: [] as $file) @unlink($file);
            @rmdir($root);
        }
        $this->servers = [];
    }

    public function testGeneratesKeysAndDeliversToRealEndpoint(): void
    {
        $keys = Push::generateVapidKeys();
        $this->assertSame(65, strlen($this->decode($keys['publicKey'])));
        $this->assertSame(32, strlen($this->decode($keys['privateKey'])));

        [$url, $requestFile] = $this->server();
        $client = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($client);
        $p256dh = $this->b64("\x04" . $details['ec']['x'] . $details['ec']['y']);
        $subscription = ['endpoint' => $url . '?status=201', 'keys' => ['p256dh' => $p256dh, 'auth' => $this->b64(str_repeat("\x07", 16))]];

        $result = (new Push('mailto:test@tina4.com', $keys['publicKey'], $keys['privateKey']))->send($subscription, ['message' => 'hello']);

        $this->assertTrue($result['ok']);
        $this->assertSame(201, $result['status']);
        $this->assertFalse($result['dead']);
        $this->assertFalse($result['retryable']);
        $request = json_decode((string)file_get_contents($requestFile), true);
        $this->assertSame('aes128gcm', strtolower($request['headers']['content-encoding'] ?? ''));
        $this->assertNotEmpty($request['body']);
    }

    public function testClassifiesDeadAndRetryableResponses(): void
    {
        $keys = Push::generateVapidKeys();
        [$url] = $this->server();
        $subscription = $this->subscription($url, '410');
        $sender = new Push('mailto:test@tina4.com', $keys['publicKey'], $keys['privateKey']);

        $dead = $sender->send($subscription, 'expired');
        $this->assertTrue($dead['dead']);
        $this->assertFalse($dead['retryable']);
        $this->assertSame(410, $dead['status']);

        $notFound = $sender->send($this->subscription($url, '404'), 'expired');
        $this->assertTrue($notFound['dead']);
        $this->assertFalse($notFound['retryable']);
        $this->assertSame(404, $notFound['status']);

        $retry = $sender->send($this->subscription($url, '429'), 'busy');
        $this->assertFalse($retry['dead']);
        $this->assertTrue($retry['retryable']);
        $this->assertSame(429, $retry['status']);

        $serverError = $sender->send($this->subscription($url, '500'), 'busy');
        $this->assertFalse($serverError['dead']);
        $this->assertTrue($serverError['retryable']);
        $this->assertSame(500, $serverError['status']);
    }

    public function testInvalidConfigurationAndSubscriptionFailLoudly(): void
    {
        $this->expectException(PushError::class);
        $this->expectExceptionMessage('TINA4_VAPID');
        (new Push('', '', ''))->send(['endpoint' => 'http://127.0.0.1/push', 'keys' => ['p256dh' => 'x', 'auth' => 'x']], 'payload');
    }

    public function testInvalidSubscriptionKeyIsRejectedBeforeDelivery(): void
    {
        $keys = Push::generateVapidKeys();
        $sender = new Push('mailto:test@tina4.com', $keys['publicKey'], $keys['privateKey']);
        $this->expectException(PushError::class);
        $this->expectExceptionMessage('subscription.keys.p256dh');
        $sender->send(['endpoint' => 'http://127.0.0.1/push', 'keys' => ['p256dh' => 'bad', 'auth' => $this->b64(str_repeat("\x07", 16))]], 'payload');
    }

    private function subscription(string $url, string $status): array
    {
        $client = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($client);
        $p256dh = $this->b64("\x04" . $details['ec']['x'] . $details['ec']['y']);
        $this->assertSame(65, strlen($this->decode($p256dh)));
        return ['endpoint' => $url . '?status=' . $status, 'keys' => ['p256dh' => $p256dh, 'auth' => $this->b64(str_repeat("\x07", 16))]];
    }

    private function server(): array
    {
        $root = sys_get_temp_dir() . '/tina4_push_' . bin2hex(random_bytes(5));
        mkdir($root);
        $requestFile = $root . '/request.json';
        file_put_contents($root . '/router.php', <<<'PHP'
<?php
$body = file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $key => $value) if (str_starts_with($key, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
file_put_contents(__DIR__ . '/request.json', json_encode(['headers' => $headers, 'body' => base64_encode($body)]));
http_response_code((int)($_GET['status'] ?? 201));
echo 'accepted';
PHP);
        $port = random_int(20000, 45000);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) { fclose($socket); break; }
            usleep(50_000);
        }
        $this->servers[] = [$process, $pipes, $root];
        return ["http://127.0.0.1:$port/push", $requestFile];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        return (string)base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
