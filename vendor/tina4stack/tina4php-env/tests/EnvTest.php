<?php


use PHPUnit\Framework\TestCase;

class EnvTest extends  TestCase
{

    function testEnvValue (): void
    {
        $env = new \Tina4\Env("dev");
        $this->assertEquals("1.0.0", VERSION);
        $this->assertEquals("Tina4 Project", SWAGGER_TITLE);
        $this->assertIsArray( TINA4_DEBUG_LEVEL);
        $this->assertEquals(true, TINA4_DEBUG);
    }
}