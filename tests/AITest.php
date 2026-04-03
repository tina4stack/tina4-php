<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\AI;

class AITest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_ai_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── AI_TOOLS constant ──

    public function testAiToolsIsArray(): void
    {
        $this->assertIsArray(AI::$AI_TOOLS);
        $this->assertNotEmpty(AI::$AI_TOOLS);
    }

    public function testAiToolsHasSevenTools(): void
    {
        $this->assertCount(7, AI::$AI_TOOLS);
    }

    public function testAiToolsHaveRequiredKeys(): void
    {
        foreach (AI::$AI_TOOLS as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('context_file', $tool);
        }
    }

    public function testAiToolsIncludeExpectedTools(): void
    {
        $names = array_column(AI::$AI_TOOLS, 'name');
        $this->assertContains('claude-code', $names);
        $this->assertContains('cursor', $names);
        $this->assertContains('copilot', $names);
        $this->assertContains('windsurf', $names);
        $this->assertContains('aider', $names);
        $this->assertContains('cline', $names);
        $this->assertContains('codex', $names);
    }

    // ── isInstalled() ──

    public function testIsInstalledReturnsFalseWhenContextFileAbsent(): void
    {
        $tool = ['name' => 'cursor', 'context_file' => '.cursorules'];
        $this->assertFalse(AI::isInstalled($this->tempDir, $tool));
    }

    public function testIsInstalledReturnsTrueWhenContextFilePresent(): void
    {
        $tool = ['name' => 'claude-code', 'context_file' => 'CLAUDE.md'];
        file_put_contents($this->tempDir . '/CLAUDE.md', 'context');
        $this->assertTrue(AI::isInstalled($this->tempDir, $tool));
    }

    public function testIsInstalledHandlesNestedPath(): void
    {
        $tool = ['name' => 'copilot', 'context_file' => '.github/copilot-instructions.md'];
        mkdir($this->tempDir . '/.github', 0755, true);
        file_put_contents($this->tempDir . '/.github/copilot-instructions.md', 'context');
        $this->assertTrue(AI::isInstalled($this->tempDir, $tool));
    }

    // ── installSelected() ──

    public function testInstallSelectedSingleToolByNumber(): void
    {
        $created = AI::installSelected($this->tempDir, '2'); // cursor
        $this->assertNotEmpty($created);
        $this->assertFileExists($this->tempDir . '/.cursorules');
    }

    public function testInstallSelectedMultipleTools(): void
    {
        $created = AI::installSelected($this->tempDir, '1,2');
        $this->assertFileExists($this->tempDir . '/CLAUDE.md');
        $this->assertFileExists($this->tempDir . '/.cursorules');
    }

    public function testInstallSelectedAll(): void
    {
        AI::installSelected($this->tempDir, 'all');
        $this->assertFileExists($this->tempDir . '/CLAUDE.md');
        $this->assertFileExists($this->tempDir . '/.cursorules');
        $this->assertFileExists($this->tempDir . '/.windsurfrules');
        $this->assertFileExists($this->tempDir . '/CONVENTIONS.md');
        $this->assertFileExists($this->tempDir . '/.clinerules');
        $this->assertFileExists($this->tempDir . '/AGENTS.md');
    }

    public function testInstallSelectedAlwaysOverwrites(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', 'old content');
        AI::installSelected($this->tempDir, '1');
        $this->assertNotEquals('old content', file_get_contents($this->tempDir . '/CLAUDE.md'));
    }

    public function testInstallSelectedCreatesParentDirectories(): void
    {
        AI::installSelected($this->tempDir, '3'); // copilot
        $this->assertFileExists($this->tempDir . '/.github/copilot-instructions.md');
    }

    public function testInstallSelectedReturnsArray(): void
    {
        $result = AI::installSelected($this->tempDir, '4'); // windsurf
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testInstallSelectedIgnoresInvalidNumbers(): void
    {
        $result = AI::installSelected($this->tempDir, '99');
        $this->assertIsArray($result);
    }

    public function testInstallSelectedHandlesEmptySelection(): void
    {
        $result = AI::installSelected($this->tempDir, '');
        $this->assertIsArray($result);
    }

    // ── installAll() ──

    public function testInstallAllCreatesAllContextFiles(): void
    {
        AI::installAll($this->tempDir);
        $this->assertFileExists($this->tempDir . '/CLAUDE.md');
        $this->assertFileExists($this->tempDir . '/.cursorules');
        $this->assertFileExists($this->tempDir . '/.windsurfrules');
        $this->assertFileExists($this->tempDir . '/CONVENTIONS.md');
        $this->assertFileExists($this->tempDir . '/.clinerules');
        $this->assertFileExists($this->tempDir . '/AGENTS.md');
    }

    public function testInstallAllReturnsArray(): void
    {
        $result = AI::installAll($this->tempDir);
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(count(AI::$AI_TOOLS), count($result));
    }

    public function testInstallAllCreatesSubdirectories(): void
    {
        AI::installAll($this->tempDir);
        $this->assertDirectoryExists($this->tempDir . '/.claude');
        $this->assertDirectoryExists($this->tempDir . '/.github');
    }

    // ── generateContext() ──

    public function testGenerateContextReturnsNonEmptyString(): void
    {
        $context = AI::generateContext();
        $this->assertIsString($context);
        $this->assertNotEmpty($context);
    }

    public function testGenerateContextIncludesTina4Reference(): void
    {
        $context = AI::generateContext();
        $this->assertStringContainsString('Tina4', $context);
        $this->assertStringContainsString('tina4.com', $context);
    }

    public function testGenerateContextIncludesSkillsTable(): void
    {
        $context = AI::generateContext();
        $this->assertStringContainsString('Skill', $context);
    }

    // ── Status report (if present) ──

    public function testStatusReportWithDetectedTools(): void
    {
        // Test that isInstalled correctly reports installed status
        $tool = AI::$AI_TOOLS[0]; // claude-code
        file_put_contents($this->tempDir . '/' . $tool['context_file'], 'ctx');
        $this->assertTrue(AI::isInstalled($this->tempDir, $tool));

        // Verify other tools show as not installed
        $otherTool = AI::$AI_TOOLS[1]; // cursor
        $this->assertFalse(AI::isInstalled($this->tempDir, $otherTool));
    }
}
