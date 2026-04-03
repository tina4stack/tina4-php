<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DotEnv;

class DotEnvTest extends TestCase
{
    private string $tempDir;

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_dotenv_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        DotEnv::resetEnv();

        // Clean up temp files recursively
        $this->removeDir($this->tempDir);

        // Clean env vars we set
        foreach (['TEST_KEY', 'TEST_QUOTED', 'TEST_SINGLE', 'TEST_EXPORT', 'DB_HOST', 'DB_URL', 'REQUIRED_KEY', 'EMPTY_VAL', 'SPACED'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    public function testLoadSimpleKeyValue(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "TEST_KEY=hello\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('hello', DotEnv::getEnv('TEST_KEY'));
        $this->assertEquals('hello', $_ENV['TEST_KEY']);
        $this->assertEquals('hello', getenv('TEST_KEY'));
    }

    public function testLoadDoubleQuotedValue(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, 'TEST_QUOTED="hello world"' . "\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('hello world', DotEnv::getEnv('TEST_QUOTED'));
    }

    public function testLoadSingleQuotedValue(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "TEST_SINGLE='hello world'\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('hello world', DotEnv::getEnv('TEST_SINGLE'));
    }

    public function testLoadExportPrefix(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "export TEST_EXPORT=exported_value\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('exported_value', DotEnv::getEnv('TEST_EXPORT'));
    }

    public function testSkipsCommentsAndEmptyLines(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "# This is a comment\n\nTEST_KEY=value\n# Another comment\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('value', DotEnv::getEnv('TEST_KEY'));
        $this->assertNull(DotEnv::getEnv('# This is a comment'));
    }

    public function testSkipsSectionHeaders(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "[Project Settings]\nTEST_KEY=value\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('value', DotEnv::getEnv('TEST_KEY'));
    }

    public function testInlineCommentStripping(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "TEST_KEY=value # this is a comment\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('value', DotEnv::getEnv('TEST_KEY'));
    }

    public function testEscapeSequencesInDoubleQuotes(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, 'TEST_KEY="line1\nline2"' . "\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals("line1\nline2", DotEnv::getEnv('TEST_KEY'));
    }

    public function testVariableInterpolation(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "DB_HOST=localhost\nDB_URL=http://\${DB_HOST}/db\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('localhost', DotEnv::getEnv('DB_HOST'));
        $this->assertEquals('http://localhost/db', DotEnv::getEnv('DB_URL'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default_val', DotEnv::getEnv('NONEXISTENT_KEY', 'default_val'));
    }

    public function testGetReturnsNullWhenNoDefault(): void
    {
        $this->assertNull(DotEnv::getEnv('NONEXISTENT_KEY'));
    }

    public function testRequireThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Required environment variable 'REQUIRED_KEY' is not set");

        DotEnv::requireEnv('REQUIRED_KEY');
    }

    public function testRequireReturnsValueWhenSet(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "REQUIRED_KEY=present\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('present', DotEnv::requireEnv('REQUIRED_KEY'));
    }

    public function testHas(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "TEST_KEY=value\n");

        DotEnv::loadEnv($envFile);

        $this->assertTrue(DotEnv::hasEnv('TEST_KEY'));
        $this->assertFalse(DotEnv::hasEnv('NONEXISTENT'));
    }

    public function testAllReturnsLoadedVariables(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "TEST_KEY=one\nDB_HOST=two\n");

        DotEnv::loadEnv($envFile);

        $all = DotEnv::allEnv();
        $this->assertArrayHasKey('TEST_KEY', $all);
        $this->assertArrayHasKey('DB_HOST', $all);
        $this->assertEquals('one', $all['TEST_KEY']);
    }

    public function testLoadThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);

        DotEnv::loadEnv('/nonexistent/path/.env');
    }

    public function testEmptyValue(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "EMPTY_VAL=\n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('', DotEnv::getEnv('EMPTY_VAL'));
    }

    public function testValueWithSpaces(): void
    {
        $envFile = $this->tempDir . '/.env';
        file_put_contents($envFile, "SPACED = value_with_spaces \n");

        DotEnv::loadEnv($envFile);

        $this->assertEquals('value_with_spaces', DotEnv::getEnv('SPACED'));
    }
}
