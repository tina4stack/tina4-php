<?php

namespace Tina4;

/**
 * Provider-neutral Web Push sender using PHP's ext-openssl and stream wrapper.
 *
 * Web Push is optional: the base framework remains dependency-free. When VAPID
 * is configured, missing ext-openssl or incomplete keys fail immediately.
 */
final class Push
{
    private const RECORD_SIZE = 4096;
    private const MAX_PAYLOAD = 4079;

    private string $subject;
    private string $publicKey;
    private string $privateKey;
    private int $ttl;
    private ?string $urgency;

    public function __construct(?string $subject = null, ?string $publicKey = null, ?string $privateKey = null, int $ttl = 60, ?string $urgency = null)
    {
        $this->subject = trim($subject ?? (getenv('TINA4_VAPID_SUBJECT') ?: ''));
        $this->publicKey = trim($publicKey ?? (getenv('TINA4_VAPID_PUBLIC') ?: ''));
        $this->privateKey = trim($privateKey ?? (getenv('TINA4_VAPID_PRIVATE') ?: ''));
        $this->ttl = $ttl;
        $this->urgency = $urgency;
        if (in_array(strtolower(trim((string)(getenv('TINA4_WEB_PUSH') ?: ''))), ['0', 'false', 'off', 'no'], true)) {
            throw new PushError('Web Push is disabled by TINA4_WEB_PUSH');
        }
        if ($this->subject !== '' || $this->publicKey !== '' || $this->privateKey !== '') {
            $this->configuration();
        }
    }

    /** @return array{publicKey:string,privateKey:string} */
    public static function generateVapidKeys(): array
    {
        self::requireOpenSsl();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new PushError('Unable to generate a P-256 VAPID key pair');
        }
        $details = openssl_pkey_get_details($key);
        $ec = $details['ec'] ?? null;
        if (!is_array($ec) || !isset($ec['x'], $ec['y'], $ec['d'])) {
            throw new PushError('OpenSSL did not return raw P-256 key material');
        }
        return ['publicKey' => self::b64($ec['x'] . $ec['y'], "\x04"), 'privateKey' => self::b64($ec['d'])];
    }

    /** @return array{ok:bool,status:int,dead:bool,retryable:bool,endpoint:string,response:string} */
    public function send(array $subscription, mixed $payload): array
    {
        $endpoint = $this->endpoint($subscription);
        [$subject, $publicKey, $privateKey] = $this->configuration();
        [$public, $private] = $this->vapidKeys($publicKey, $privateKey);
        $raw = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($raw === false) throw new PushError('Push payload is not JSON serializable');
        return $this->deliver($endpoint, $subject, $publicKey, $private, $public, $this->encrypt($raw, $subscription));
    }

    private function endpoint(array $subscription): string
    {
        $endpoint = $subscription['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') throw new PushError('A Web Push subscription with an endpoint is required');
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new PushError('Push subscription endpoint must use HTTP or HTTPS');
        }
        return $endpoint;
    }

    private function vapidKeys(string $publicKey, string $privateKey): array
    {
        $public = self::decode($publicKey, 'TINA4_VAPID_PUBLIC');
        $private = self::decode($privateKey, 'TINA4_VAPID_PRIVATE');
        if (strlen($public) !== 65 || $public[0] !== "\x04") throw new PushError('TINA4_VAPID_PUBLIC must be a 65-byte P-256 public key');
        if (strlen($private) !== 32) throw new PushError('TINA4_VAPID_PRIVATE must be a 32-byte P-256 private key');
        $vapidPrivate = openssl_pkey_get_private(self::privatePem($private, $public));
        $details = $vapidPrivate !== false ? openssl_pkey_get_details($vapidPrivate) : false;
        $derived = is_array($details) && isset($details['ec']['x'], $details['ec']['y']) ? "\x04" . $details['ec']['x'] . $details['ec']['y'] : '';
        if ($derived !== $public) throw new PushError('TINA4_VAPID_PUBLIC does not match TINA4_VAPID_PRIVATE');
        return [$public, $private];
    }

    private function deliver(string $endpoint, string $subject, string $publicKey, string $private, string $public, string $body): array
    {
        $headers = [
            'Authorization: vapid t=' . $this->vapidToken($endpoint, $subject, $private, $public) . ', k=' . $publicKey,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . $this->ttl,
        ];
        if ($this->urgency !== null && $this->urgency !== '') {
            $headers[] = 'Urgency: ' . $this->urgency;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $responseBody = @file_get_contents($endpoint, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        if ($status === 0) {
            throw new PushError('Web Push request failed: no HTTP response received');
        }
        return ['ok' => $status < 400, 'status' => $status, 'dead' => in_array($status, [404, 410], true), 'retryable' => $status === 408 || $status === 429 || $status >= 500, 'endpoint' => $endpoint, 'response' => is_string($responseBody) ? $responseBody : ''];
    }

    private function configuration(): array
    {
        $missing = [];
        foreach ([
            'TINA4_VAPID_SUBJECT' => $this->subject,
            'TINA4_VAPID_PUBLIC' => $this->publicKey,
            'TINA4_VAPID_PRIVATE' => $this->privateKey,
        ] as $name => $value) {
            if ($value === '') $missing[] = $name;
        }
        if ($missing !== []) {
            throw new PushError('Web Push is configured but missing: ' . implode(', ', $missing));
        }
        self::requireOpenSsl();
        return [$this->subject, $this->publicKey, $this->privateKey];
    }

    private static function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl') || !function_exists('openssl_pkey_derive')) {
            throw new PushError('Web Push requires PHP ext-openssl; enable the optional crypto capability');
        }
    }

    private static function b64(string $value, string $prefix = ''): string
    {
        return rtrim(strtr(base64_encode($prefix . $value), '+/', '-_'), '=');
    }

    private static function decode(string $value, string $name): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new PushError($name . ' must be a non-empty base64url string');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) throw new PushError($name . ' must be base64url encoded');
        return $decoded;
    }

    private static function length(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = '';
        while ($length > 0) { $bytes = chr($length & 0xff) . $bytes; $length >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function seq(string $value): string { return "\x30" . self::length(strlen($value)) . $value; }

    private static function publicPem(string $point): string
    {
        $oidEc = "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01";
        $oidCurve = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $algorithm = self::seq($oidEc . $oidCurve);
        $bitString = "\x03" . self::length(strlen($point) + 1) . "\x00" . $point;
        $der = self::seq($algorithm . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function privatePem(string $private, string $point): string
    {
        $oidCurve = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $publicBitString = "\x03" . self::length(strlen($point) + 1) . "\x00" . $point;
        $body = "\x02\x01\x01" . "\x04" . self::length(strlen($private)) . $private
            . "\xA0" . self::length(strlen($oidCurve)) . $oidCurve
            . "\xA1" . self::length(strlen($publicBitString)) . $publicBitString;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode(self::seq($body)), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    private function encrypt(string $payload, array $subscription): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) throw new PushError('Push payload is too large; maximum is 4079 bytes');
        $client = self::decode((string)($subscription['keys']['p256dh'] ?? ''), 'subscription.keys.p256dh');
        $auth = self::decode((string)($subscription['keys']['auth'] ?? ''), 'subscription.keys.auth');
        if (strlen($client) !== 65 || $client[0] !== "\x04") throw new PushError('subscription.keys.p256dh must be a 65-byte P-256 public key');
        if (strlen($auth) !== 16) throw new PushError('subscription.keys.auth must be a 16-byte authentication secret');
        $ephemeral = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($ephemeral);
        $ec = $details['ec'] ?? [];
        $server = "\x04" . ($ec['x'] ?? '') . ($ec['y'] ?? '');
        $private = $ec['d'] ?? '';
        // PHP's API takes the peer public key first and our private key second.
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($client)), openssl_pkey_get_private(self::privatePem($private, $server)));
        if (!is_string($shared)) throw new PushError('OpenSSL could not derive the Web Push ECDH secret');
        $ikm = self::hkdf(self::hmac($auth, $shared), "WebPush: info\0" . $client . $server, 32);
        $salt = random_bytes(16);
        $prk = self::hmac($salt, $ikm);
        $cek = self::hkdf($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($prk, "Content-Encoding: nonce\0", 12);
        $tag = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) throw new PushError('OpenSSL could not encrypt the Web Push payload');
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($server)) . $server . $ciphertext . $tag;
    }

    private static function hmac(string $key, string $value): string { return hash_hmac('sha256', $value, $key, true); }

    private static function hkdf(string $prk, string $info, int $length): string
    {
        $out = ''; $previous = '';
        for ($i = 1; strlen($out) < $length; $i++) {
            $previous = self::hmac($prk, $previous . $info . chr($i)); $out .= $previous;
            if ($i > 255) throw new PushError('HKDF output is too large');
        }
        return substr($out, 0, $length);
    }

    private function vapidToken(string $endpoint, string $subject, string $private, string $public): string
    {
        $parts = parse_url($endpoint);
        $aud = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $claims = self::b64(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;
        $signature = '';
        $key = openssl_pkey_get_private(self::privatePem($private, $public));
        if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) throw new PushError('OpenSSL could not sign the VAPID token');
        return $input . '.' . self::b64(self::derToRawSignature($signature));
    }

    private static function derToRawSignature(string $der): string
    {
        $offset = 2; if (ord($der[1]) & 0x80) $offset = 2 + (ord($der[1]) & 0x7f);
        $read = static function (string $value, int &$pos): string { $pos++; $len = ord($value[$pos++]); if ($len & 0x80) { $n = $len & 0x7f; $len = 0; for ($j = 0; $j < $n; $j++) $len = ($len << 8) | ord($value[$pos++]); } $out = substr($value, $pos, $len); $pos += $len; return ltrim($out, "\0"); };
        $r = $read($der, $offset); $s = $read($der, $offset);
        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }
}

class PushError extends \RuntimeException {}
