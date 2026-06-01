<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Debug;
use Tina4\Log;

/**
 * Verify the \Tina4\Debug compatibility shim shipped in 3.13.0.
 *
 * Chapter 15 of the documentation has long taught:
 *
 *     \Tina4\Debug::message("Login failed", TINA4_LOG_WARNING, [...]);
 *
 * But the real logger was \Tina4\Log all along — \Tina4\Debug did not
 * exist, and the TINA4_LOG_* constants were undefined. Every example
 * triggered "Class not found" and "undefined constant" errors. The shim
 * forwards level-specific methods (and the generic ::message dispatcher)
 * to the real Log so the chapter's code samples now run as-written.
 */
final class ParityDebugShimTest extends TestCase
{
    public function testDebugClassExists(): void
    {
        $this->assertTrue(class_exists(Debug::class), "Tina4\\Debug shim must exist");
    }

    public function testLogLevelConstantsDefined(): void
    {
        $this->assertTrue(defined('TINA4_LOG_DEBUG'));
        $this->assertTrue(defined('TINA4_LOG_INFO'));
        $this->assertTrue(defined('TINA4_LOG_WARNING'));
        $this->assertTrue(defined('TINA4_LOG_ERROR'));
        $this->assertTrue(defined('TINA4_LOG_CRITICAL'));
    }

    public function testEachLevelMethodCallable(): void
    {
        // Just verify they don't throw — the real Log routes to whatever
        // backend is configured (silent by default in tests).
        Debug::debug("debug message");
        Debug::info("info message");
        Debug::warning("warning message");
        Debug::error("error message");
        Debug::critical("critical message");
        $this->addToAssertionCount(5);
    }

    public function testMessageDispatcherAcceptsLevels(): void
    {
        Debug::message("test", TINA4_LOG_DEBUG);
        Debug::message("test", TINA4_LOG_INFO);
        Debug::message("test", TINA4_LOG_WARNING);
        Debug::message("test", TINA4_LOG_ERROR);
        Debug::message("test", TINA4_LOG_CRITICAL);
        $this->addToAssertionCount(5);
    }

    public function testMessageDefaultsToInfo(): void
    {
        // Default level (no level arg) should not throw
        Debug::message("test default");
        $this->addToAssertionCount(1);
    }

    public function testMessageUnknownLevelDefaultsToInfo(): void
    {
        // Unknown level should silently default to info, not throw
        Debug::message("test", "nonsense-level");
        $this->addToAssertionCount(1);
    }

    public function testContextArrayPassedThrough(): void
    {
        Debug::info("with context", ["user_id" => 42, "trace_id" => "abc"]);
        $this->addToAssertionCount(1);
    }
}
