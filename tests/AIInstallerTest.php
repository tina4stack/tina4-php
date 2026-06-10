<?php

/**
 * Tina4 PHP — v3.13.9 non-destructive AI installer.
 *
 * The pre-v3.13.9 installer clobbered the user's CLAUDE.md (and the
 * equivalent context files for Cursor / Copilot / Windsurf / etc.) on
 * every install. v3.13.9 switches to a marker-bracketed skill block
 * that preserves whatever the user had in the file.
 *
 * Four branches covered:
 *
 *   1. Fresh install — file doesn't exist, full framework guide + block.
 *   2. Refresh — file exists with markers, just the block gets replaced.
 *   3. Migration — file starts with a pre-v3.13.9 framework header,
 *      the old dump gets replaced.
 *   4. Preserve — file exists with user content, block appended at end.
 */

use PHPUnit\Framework\TestCase;
use Tina4\AI;

class AIInstallerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_ai_installer_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Test the private writeOrMerge via reflection. We want the same
     * branch coverage as the Python tests without exposing it.
     */
    private function writeOrMerge(string $contextPath, string $contextFile, string $frameworkGuide): string
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('writeOrMerge');
        $method->setAccessible(true);
        return $method->invoke(null, $contextPath, $contextFile, $frameworkGuide);
    }

    // ── Marker / block helpers ──────────────────────────────────────

    public function testMarkdownFilesGetHtmlCommentMarkers(): void
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('markersFor');
        $method->setAccessible(true);
        $markers = $method->invoke(null, 'CLAUDE.md');
        $this->assertSame('<!-- tina4-skills:start -->', $markers[0]);
        $this->assertSame('<!-- tina4-skills:end -->', $markers[1]);
    }

    public function testRuleFilesGetHashCommentMarkers(): void
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('markersFor');
        $method->setAccessible(true);
        $markers = $method->invoke(null, '.cursorules');
        $this->assertSame('# tina4-skills:start', $markers[0]);
        $this->assertSame('# tina4-skills:end', $markers[1]);
    }

    public function testMarkdownSkillBlockMentionsAllThreeSkills(): void
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('skillBlock');
        $method->setAccessible(true);
        $block = $method->invoke(null, 'CLAUDE.md');
        $this->assertStringContainsString('## Tina4 Skills', $block);
        $this->assertStringContainsString('tina4-developer', $block);
        $this->assertStringContainsString('tina4-js', $block);
        $this->assertStringContainsString('tina4-maintainer', $block);
        $this->assertStringContainsString('tina4.com', $block);
    }

    // ── Old-framework header detection ──────────────────────────────

    public function testRecognisesOldFrameworkHeaders(): void
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('looksLikeOldFrameworkInstall');
        $method->setAccessible(true);
        foreach (
            [
                "# Tina4 Python — Developer Guidelines\n\nOld dump...",
                "# Tina4 PHP\n\nContent...",
                "# Tina4 Ruby\n\nContent...",
                "# CLAUDE.md — AI Developer Guide for tina4-nodejs (v3.13.5)",
            ] as $text
        ) {
            $this->assertTrue($method->invoke(null, $text), "Should recognise: {$text}");
        }
    }

    public function testUserClaudeMdIsNotOld(): void
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('looksLikeOldFrameworkInstall');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke(null, "# My Project Notes\n\nDeploy info..."));
    }

    // ── writeOrMerge end-to-end ─────────────────────────────────────

    private const FRAMEWORK_GUIDE = "# Tina4 PHP\n\nFramework boilerplate here.";

    public function testFreshInstallWritesGuidePlusBlock(): void
    {
        $path = $this->tmpDir . '/CLAUDE.md';
        $action = $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $this->assertSame('Installed', $action);
        $body = file_get_contents($path);
        $this->assertStringContainsString('Framework boilerplate', $body);
        $this->assertStringContainsString('<!-- tina4-skills:start -->', $body);
        $this->assertStringContainsString('<!-- tina4-skills:end -->', $body);
    }

    public function testExistingWithMarkersOnlyRefreshesBlock(): void
    {
        $path = $this->tmpDir . '/CLAUDE.md';
        file_put_contents(
            $path,
            "# My Project Notes\n\n"
            . "Deploy to staging via `make deploy`.\n\n"
            . "<!-- tina4-skills:start -->\n"
            . "OLD BLOCK CONTENT — should be replaced\n"
            . "<!-- tina4-skills:end -->\n\n"
            . "Don't forget to bump the version before tagging."
        );

        $action = $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $this->assertSame('Refreshed skill block in', $action);

        $body = file_get_contents($path);
        $this->assertStringNotContainsString('OLD BLOCK CONTENT', $body);
        $this->assertStringContainsString('tina4-developer', $body);
        $this->assertStringContainsString('# My Project Notes', $body);
        $this->assertStringContainsString("Deploy to staging via `make deploy`.", $body);
        $this->assertStringContainsString("Don't forget to bump the version before tagging.", $body);
        // Framework guide must NOT be injected when the user already has
        // a marker block — we only refresh the bracketed content.
        $this->assertStringNotContainsString('Framework boilerplate', $body);
    }

    public function testRefreshIsIdempotent(): void
    {
        $path = $this->tmpDir . '/CLAUDE.md';
        file_put_contents($path, "# My Notes\n");
        $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $first = file_get_contents($path);
        $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $second = file_get_contents($path);
        $this->assertSame($first, $second, "second run should be a no-op");
    }

    public function testOldFrameworkInstallIsMigrated(): void
    {
        $path = $this->tmpDir . '/CLAUDE.md';
        file_put_contents(
            $path,
            "# Tina4 PHP\n\nOld framework guide content (should be replaced)...\n"
        );
        $action = $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $this->assertSame('Migrated (replaced old framework dump in)', $action);
        $body = file_get_contents($path);
        $this->assertStringNotContainsString('Old framework guide content', $body);
        $this->assertStringContainsString('Framework boilerplate here.', $body);
        $this->assertStringContainsString('<!-- tina4-skills:start -->', $body);
    }

    public function testUserContentIsPreservedAndBlockAppended(): void
    {
        $path = $this->tmpDir . '/CLAUDE.md';
        $original = "# 24rent App\n\n"
            . "## Conventions\n\n"
            . "- Branch naming: `feature/<jira>-<slug>`\n"
            . "- Deploy: `make deploy ENV=staging`\n";
        file_put_contents($path, $original);

        $action = $this->writeOrMerge($path, 'CLAUDE.md', self::FRAMEWORK_GUIDE);
        $this->assertSame('Appended skill block to', $action);

        $body = file_get_contents($path);
        foreach (explode("\n", trim($original)) as $line) {
            if (trim($line) !== '') {
                $this->assertStringContainsString($line, $body, "lost user line: {$line}");
            }
        }
        $this->assertStringContainsString('<!-- tina4-skills:start -->', $body);
    }

    public function testRuleFileUsesHashMarkers(): void
    {
        $path = $this->tmpDir . '/.cursorules';
        file_put_contents($path, "Rule: keep handlers under 50 lines\n");
        $this->writeOrMerge($path, '.cursorules', "# Cursor framework guide");

        $body = file_get_contents($path);
        $this->assertStringContainsString('# tina4-skills:start', $body);
        $this->assertStringContainsString('# tina4-skills:end', $body);
        $this->assertStringNotContainsString('<!--', $body, ".cursorules must not get HTML markers");
        $this->assertStringContainsString('Rule: keep handlers under 50 lines', $body);
    }
}
