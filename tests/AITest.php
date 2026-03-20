<?php

/**
 * Tina4 - This is not a 4ramework.
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

    // ── Detection tests ──

    public function testDetectAiReturnsAllSevenTools(): void
    {
        $tools = AI::detectAi($this->tempDir);
        $this->assertCount(7, $tools);

        $names = array_column($tools, 'name');
        $this->assertContains('claude-code', $names);
        $this->assertContains('cursor', $names);
        $this->assertContains('copilot', $names);
        $this->assertContains('windsurf', $names);
        $this->assertContains('aider', $names);
        $this->assertContains('cline', $names);
        $this->assertContains('codex', $names);
    }

    public function testDetectAiToolStructure(): void
    {
        $tools = AI::detectAi($this->tempDir);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('installed', $tool);
            $this->assertIsBool($tool['installed']);
            $this->assertIsString($tool['description']);
        }
    }

    public function testDetectNoneInEmptyDir(): void
    {
        $names = AI::detectAiNames($this->tempDir);
        $this->assertEmpty($names);
    }

    public function testDetectClaudeCodeByDir(): void
    {
        mkdir($this->tempDir . '/.claude', 0755, true);
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('claude-code', $names);
    }

    public function testDetectClaudeCodeByFile(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', '# Context');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('claude-code', $names);
    }

    public function testDetectCursorByDir(): void
    {
        mkdir($this->tempDir . '/.cursor', 0755, true);
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('cursor', $names);
    }

    public function testDetectCursorByFile(): void
    {
        file_put_contents($this->tempDir . '/.cursorules', '# Rules');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('cursor', $names);
    }

    public function testDetectCopilotByDir(): void
    {
        mkdir($this->tempDir . '/.github', 0755, true);
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('copilot', $names);
    }

    public function testDetectCopilotByFile(): void
    {
        mkdir($this->tempDir . '/.github', 0755, true);
        file_put_contents($this->tempDir . '/.github/copilot-instructions.md', '# Copilot');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('copilot', $names);
    }

    public function testDetectWindsurf(): void
    {
        file_put_contents($this->tempDir . '/.windsurfrules', '# Windsurf');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('windsurf', $names);
    }

    public function testDetectAiderByConf(): void
    {
        file_put_contents($this->tempDir . '/.aider.conf.yml', 'model: gpt-4');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('aider', $names);
    }

    public function testDetectAiderByConventions(): void
    {
        file_put_contents($this->tempDir . '/CONVENTIONS.md', '# Conventions');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('aider', $names);
    }

    public function testDetectCline(): void
    {
        file_put_contents($this->tempDir . '/.clinerules', '# Cline');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('cline', $names);
    }

    public function testDetectCodexByAgents(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', '# Agents');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('codex', $names);
    }

    public function testDetectCodexByCodexMd(): void
    {
        file_put_contents($this->tempDir . '/codex.md', '# Codex');
        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('codex', $names);
    }

    public function testDetectMultipleTools(): void
    {
        mkdir($this->tempDir . '/.claude', 0755, true);
        mkdir($this->tempDir . '/.cursor', 0755, true);
        file_put_contents($this->tempDir . '/.windsurfrules', '# WS');

        $names = AI::detectAiNames($this->tempDir);
        $this->assertContains('claude-code', $names);
        $this->assertContains('cursor', $names);
        $this->assertContains('windsurf', $names);
        $this->assertCount(3, $names);
    }

    // ── Context generation tests ──

    public function testGenerateContextContainsPhpContent(): void
    {
        $context = AI::generateContext();
        $this->assertStringContainsString('Tina4 PHP', $context);
        $this->assertStringContainsString('composer', $context);
        $this->assertStringContainsString('Route::get', $context);
        $this->assertStringContainsString('Route::post', $context);
        $this->assertStringContainsString('ORM', $context);
        $this->assertStringContainsString('tina4.com', $context);
    }

    public function testGenerateContextWithSkills(): void
    {
        $context = AI::generateContext(true);
        $this->assertStringContainsString('/tina4-route', $context);
        $this->assertStringContainsString('/tina4-crud', $context);
        $this->assertStringContainsString('/tina4-orm', $context);
        $this->assertStringContainsString('AI Workflow', $context);
    }

    public function testGenerateContextWithoutSkills(): void
    {
        $context = AI::generateContext(false);
        $this->assertStringNotContainsString('/tina4-route', $context);
        $this->assertStringNotContainsString('AI Workflow', $context);
        // Core content should still be present
        $this->assertStringContainsString('Tina4 PHP', $context);
    }

    public function testGenerateContextContainsProjectStructure(): void
    {
        $context = AI::generateContext();
        $this->assertStringContainsString('src/routes/', $context);
        $this->assertStringContainsString('src/orm/', $context);
        $this->assertStringContainsString('src/templates/', $context);
        $this->assertStringContainsString('migrations/', $context);
    }

    // ── Install context tests ──

    public function testInstallContextCreatesFiles(): void
    {
        $created = AI::installContext($this->tempDir, ['claude-code'], false);
        $this->assertNotEmpty($created);
        $this->assertContains('CLAUDE.md', $created);
        $this->assertFileExists($this->tempDir . '/CLAUDE.md');
        $this->assertDirectoryExists($this->tempDir . '/.claude');
    }

    public function testInstallContextCreatesCorrectContent(): void
    {
        AI::installContext($this->tempDir, ['claude-code'], false);
        $content = file_get_contents($this->tempDir . '/CLAUDE.md');
        $this->assertStringContainsString('Tina4 PHP', $content);
        $this->assertStringContainsString('composer', $content);
    }

    public function testInstallContextDoesNotOverwriteWithoutForce(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', 'Original content');
        mkdir($this->tempDir . '/.claude', 0755, true);

        AI::installContext($this->tempDir, ['claude-code'], false);
        $content = file_get_contents($this->tempDir . '/CLAUDE.md');
        $this->assertSame('Original content', $content);
    }

    public function testInstallContextOverwritesWithForce(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', 'Original content');
        mkdir($this->tempDir . '/.claude', 0755, true);

        AI::installContext($this->tempDir, ['claude-code'], true);
        $content = file_get_contents($this->tempDir . '/CLAUDE.md');
        $this->assertStringContainsString('Tina4 PHP', $content);
    }

    public function testInstallContextForCursor(): void
    {
        $created = AI::installContext($this->tempDir, ['cursor'], false);
        $this->assertContains('.cursorules', $created);
        $this->assertFileExists($this->tempDir . '/.cursorules');
        $this->assertDirectoryExists($this->tempDir . '/.cursor');
    }

    public function testInstallContextForCopilot(): void
    {
        $created = AI::installContext($this->tempDir, ['copilot'], false);
        $this->assertContains('.github/copilot-instructions.md', $created);
        $this->assertFileExists($this->tempDir . '/.github/copilot-instructions.md');
        $this->assertDirectoryExists($this->tempDir . '/.github');
    }

    public function testInstallContextForWindsurf(): void
    {
        $created = AI::installContext($this->tempDir, ['windsurf'], false);
        $this->assertContains('.windsurfrules', $created);
        $this->assertFileExists($this->tempDir . '/.windsurfrules');
    }

    public function testInstallContextAutoDetect(): void
    {
        mkdir($this->tempDir . '/.claude', 0755, true);
        file_put_contents($this->tempDir . '/.windsurfrules', '# old');

        $created = AI::installContext($this->tempDir, null, true);
        $this->assertContains('CLAUDE.md', $created);
        $this->assertContains('.windsurfrules', $created);
    }

    public function testInstallContextIgnoresUnknownTool(): void
    {
        $created = AI::installContext($this->tempDir, ['nonexistent-tool'], false);
        $this->assertEmpty($created);
    }

    // ── Install all tests ──

    public function testInstallAllCreatesAllFiles(): void
    {
        $created = AI::installAll($this->tempDir, false);

        $this->assertContains('CLAUDE.md', $created);
        $this->assertContains('.cursorules', $created);
        $this->assertContains('.github/copilot-instructions.md', $created);
        $this->assertContains('.windsurfrules', $created);
        $this->assertContains('CONVENTIONS.md', $created);
        $this->assertContains('.clinerules', $created);
        $this->assertContains('AGENTS.md', $created);

        $this->assertFileExists($this->tempDir . '/CLAUDE.md');
        $this->assertFileExists($this->tempDir . '/.cursorules');
        $this->assertFileExists($this->tempDir . '/.github/copilot-instructions.md');
        $this->assertFileExists($this->tempDir . '/.windsurfrules');
        $this->assertFileExists($this->tempDir . '/CONVENTIONS.md');
        $this->assertFileExists($this->tempDir . '/.clinerules');
        $this->assertFileExists($this->tempDir . '/AGENTS.md');
    }

    public function testInstallAllCreatesConfigDirs(): void
    {
        AI::installAll($this->tempDir, false);
        $this->assertDirectoryExists($this->tempDir . '/.claude');
        $this->assertDirectoryExists($this->tempDir . '/.cursor');
        $this->assertDirectoryExists($this->tempDir . '/.github');
    }

    // ── Status report tests ──

    public function testStatusReportEmptyDir(): void
    {
        $report = AI::statusReport($this->tempDir);
        $this->assertStringContainsString('Tina4 AI Context Status', $report);
        $this->assertStringContainsString('No AI coding tools detected', $report);
        $this->assertStringContainsString('Not detected', $report);
    }

    public function testStatusReportWithDetectedTools(): void
    {
        mkdir($this->tempDir . '/.claude', 0755, true);
        file_put_contents($this->tempDir . '/.windsurfrules', '# WS');

        $report = AI::statusReport($this->tempDir);
        $this->assertStringContainsString('Detected AI tools:', $report);
        $this->assertStringContainsString('Claude Code (Anthropic CLI)', $report);
        $this->assertStringContainsString('Windsurf (Codeium)', $report);
        $this->assertStringContainsString('[x]', $report);
        $this->assertStringContainsString('[ ]', $report);
    }

    public function testStatusReportAllDetected(): void
    {
        mkdir($this->tempDir . '/.claude', 0755, true);
        mkdir($this->tempDir . '/.cursor', 0755, true);
        mkdir($this->tempDir . '/.github', 0755, true);
        file_put_contents($this->tempDir . '/.windsurfrules', '#');
        file_put_contents($this->tempDir . '/.clinerules', '#');
        file_put_contents($this->tempDir . '/.aider.conf.yml', '#');
        file_put_contents($this->tempDir . '/AGENTS.md', '#');

        $report = AI::statusReport($this->tempDir);
        $this->assertStringContainsString('Detected AI tools:', $report);
        $this->assertStringNotContainsString('No AI coding tools detected', $report);
    }
}
