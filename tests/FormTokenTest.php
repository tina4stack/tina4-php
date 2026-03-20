<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

class FormTokenTest extends TestCase
{
    private Frond $engine;

    protected function setUp(): void
    {
        $_ENV['SECRET'] = 'test-secret-key';
        $templateDir = sys_get_temp_dir() . '/tina4-frond-formtoken-test';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0777, true);
        }
        $this->engine = new Frond($templateDir);
    }

    protected function tearDown(): void
    {
        unset($_ENV['SECRET']);
    }

    /**
     * Decode the payload from a JWT string without validation.
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT must have 3 dot-separated parts');
        foreach ($parts as $part) {
            $this->assertNotEmpty($part, 'JWT segment must not be empty');
        }

        $payloadB64 = $parts[1];
        $remainder = strlen($payloadB64) % 4;
        if ($remainder !== 0) {
            $payloadB64 .= str_repeat('=', 4 - $remainder);
        }
        $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        return json_decode($payloadJson, true);
    }

    /**
     * Extract the JWT value from a rendered hidden input element.
     */
    private function extractToken(string $html): string
    {
        $this->assertStringContainsString(
            '<input type="hidden" name="formToken" value="',
            $html,
            'Expected hidden input element in output'
        );
        preg_match('/value="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'Token value should not be empty');
        return $matches[1];
    }

    /* ═══════════ Global function tests ═══════════ */

    public function testRendersHiddenInput(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $output);
        $this->assertStringEndsWith('">', trim($output));
    }

    public function testFormTokenAlias(): void
    {
        $output = $this->engine->renderString('{{ formToken() }}');
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $output);
    }

    public function testNotHtmlEscaped(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $this->assertStringNotContainsString('&lt;', $output);
        $this->assertStringNotContainsString('&gt;', $output);
    }

    public function testTokenIsValidJwt(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'Token must have 3 dot-separated base64 parts');
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('form', $payload['type']);
    }

    public function testBasicPayload(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('form', $payload['type']);
        $this->assertArrayNotHasKey('context', $payload);
        $this->assertArrayNotHasKey('ref', $payload);
    }

    public function testContextOnly(): void
    {
        $output = $this->engine->renderString('{{ form_token("my_context") }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('form', $payload['type']);
        $this->assertSame('my_context', $payload['context']);
        $this->assertArrayNotHasKey('ref', $payload);
    }

    public function testContextAndRef(): void
    {
        $output = $this->engine->renderString('{{ form_token("checkout|order_123") }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('form', $payload['type']);
        $this->assertSame('checkout', $payload['context']);
        $this->assertSame('order_123', $payload['ref']);
    }

    /* ═══════════ Filter tests ═══════════ */

    public function testFilterRendersHiddenInput(): void
    {
        $output = $this->engine->renderString('{{ "admin" | form_token }}');
        $this->assertStringContainsString('<input type="hidden" name="formToken" value="', $output);
    }

    public function testFilterWithContext(): void
    {
        $output = $this->engine->renderString('{{ "admin" | form_token }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('admin', $payload['context']);
    }

    public function testFilterWithPipeDescriptor(): void
    {
        $output = $this->engine->renderString('{{ "checkout|order_123" | form_token }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('checkout', $payload['context']);
        $this->assertSame('order_123', $payload['ref']);
    }
}
