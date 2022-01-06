<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;



final class EnvTest extends TestCase
{
    private $env;

    function setUp(): void
    {
       $this->env = new \Tina4\Env();
    }

    public function testEnv() : void
    {
       $this->env->readParams("");
       $this->assertEquals(true, TINA4_DEBUG, "Debug should be false");
       $this->env->readParams("prod");
       $this->assertFileExists($this->env->documentRoot.".env.prod", "File .env.prod exists");
    }
}