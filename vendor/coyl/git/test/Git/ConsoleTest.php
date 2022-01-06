<?php


class ConsoleTest extends PHPUnit_Framework_TestCase
{
    public function testCurrentPath()
    {
        $console = new \Coyl\Git\Console();
        $console->setCurrentPath(__DIR__);
        self::assertEquals(__DIR__, $console->runCommand('pwd'));
    }
}
