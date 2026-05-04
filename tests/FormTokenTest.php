<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

class FormTokenTest extends TestCase
{
    private Frond $engine;

    protected function setUp(): void
    {
        $_ENV['TINA4_SECRET'] = 'test-secret-key';
        $templateDir = sys_get_temp_dir() . '/tina4-frond-formtoken-test';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0777, true);
        }
        $this->engine = new Frond($templateDir);
    }

    protected function tearDown(): void
    {
        unset($_ENV['TINA4_SECRET']);
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

    /* ═══════════ JWT structure tests ═══════════ */

    public function testTokenHasIatClaim(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertIsInt($payload['iat']);
    }

    public function testTokenHasExpClaim(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);
    }

    public function testEachRenderProducesUniqueToken(): void
    {
        $output1 = $this->engine->renderString('{{ form_token() }}');
        sleep(1);
        $output2 = $this->engine->renderString('{{ form_token() }}');
        $token1 = $this->extractToken($output1);
        $token2 = $this->extractToken($output2);
        $this->assertNotEquals($token1, $token2);
    }

    public function testTokenSignatureValidates(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        $this->assertTrue(\Tina4\Auth::validToken($token));
        $payload = \Tina4\Auth::getPayload($token);
        $this->assertSame('form', $payload['type']);
    }

    /* ═══════════ Descriptor edge cases ═══════════ */

    public function testEmptyStringDescriptor(): void
    {
        $output = $this->engine->renderString('{{ form_token("") }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('form', $payload['type']);
        $this->assertArrayNotHasKey('context', $payload);
    }

    public function testDescriptorWithSpecialChars(): void
    {
        $output = $this->engine->renderString('{{ form_token("user-profile_v2") }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('user-profile_v2', $payload['context']);
    }

    public function testDescriptorWithMultiplePipes(): void
    {
        $output = $this->engine->renderString('{{ form_token("checkout|order_123|extra") }}');
        $token = $this->extractToken($output);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame('checkout', $payload['context']);
        // split('|', 2) means ref gets the rest
        $this->assertStringContainsString('order_123', $payload['ref']);
    }

    public function testHiddenInputNameAttribute(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $this->assertStringContainsString('name="formToken"', $output);
    }

    public function testHiddenInputTypeAttribute(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $this->assertStringContainsString('type="hidden"', $output);
    }

    public function testFormTokenInTemplateWithOtherContent(): void
    {
        $output = $this->engine->renderString('<form>{{ form_token() }}<button>Submit</button></form>');
        $this->assertStringContainsString('<form>', $output);
        $this->assertStringContainsString('<input type="hidden" name="formToken"', $output);
        $this->assertStringContainsString('<button>Submit</button>', $output);
        $this->assertStringContainsString('</form>', $output);
    }

    public function testFormTokenUsesSecretEnv(): void
    {
        $output = $this->engine->renderString('{{ form_token() }}');
        $token = $this->extractToken($output);
        // Should validate with the same secret
        $this->assertTrue(\Tina4\Auth::validToken($token));
        // Should NOT validate with a different secret
        $_ENV['TINA4_SECRET'] = 'wrong-secret';
        $wrongResult = \Tina4\Auth::validToken($token);
        $_ENV['TINA4_SECRET'] = 'test-secret-key';
        $this->assertFalse($wrongResult);
    }
}
