<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;

class AuthTest extends TestCase
{
    private Auth $auth;

    protected function setUp(): void
    {
        $this->auth = new Auth();
    }

    public function testGetTokenReturnsNonEmptyString(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testValidTokenAcceptsValidToken(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $this->assertTrue($this->auth->validToken($token));
    }

    public function testValidTokenRejectsGarbage(): void
    {
        $this->assertFalse($this->auth->validToken("not.a.valid.token"));
    }

    public function testValidTokenRejectsEmptyString(): void
    {
        $this->assertFalse($this->auth->validToken(""));
    }

    public function testValidTokenAcceptsBearerPrefix(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $this->assertTrue($this->auth->validToken("Bearer " . $token));
    }

    public function testGetPayloadReturnsRoutePayload(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $payload = $this->auth->getPayLoad($token);
        $this->assertEquals("/api/test", $payload["payload"]);
    }

    public function testGetPayloadDifferentPaths(): void
    {
        $token1 = $this->auth->getToken(["payload" => "/api/test"]);
        $token2 = $this->auth->getToken(["payload" => "/api/other"]);

        $this->assertEquals("/api/test", $this->auth->getPayLoad($token1)["payload"]);
        $this->assertEquals("/api/other", $this->auth->getPayLoad($token2)["payload"]);
    }

    public function testGetPayloadExcludesExpiresByDefault(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $payload = $this->auth->getPayLoad($token);
        $this->assertArrayNotHasKey("expires", $payload);
    }

    public function testGetPayloadIncludesExpiresWhenRequested(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"]);
        $payload = $this->auth->getPayLoad($token, true);
        $this->assertArrayHasKey("expires", $payload);
        $this->assertGreaterThan(time(), $payload["expires"]);
    }

    public function testTokenWithCustomExpiryInDays(): void
    {
        $token = $this->auth->getToken(["payload" => "/api/test"], 1);
        $payload = $this->auth->getPayLoad($token, true);
        // Should expire roughly 1 day from now
        $expectedExpiry = time() + 86400;
        $this->assertEqualsWithDelta($expectedExpiry, $payload["expires"], 5);
    }

    public function testGetPayloadReturnsEmptyForInvalidToken(): void
    {
        $payload = $this->auth->getPayLoad("invalid.token.here");
        $this->assertEmpty($payload);
    }
}
