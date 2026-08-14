<?php

/**
 * `tina4 ai` must install the actual SKILL files (project + global), not just a
 * CLAUDE.md pointer to skills that aren't shipped. Real network fetch from the
 * release tag, no mocks. Mirrors tina4-python tests/test_ai_skill_install.py.
 */

use PHPUnit\Framework\TestCase;
use Tina4\AITools;

class AISkillInstallTest extends TestCase
{
    private string $tmpDir;
    private string|false $prevRef;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tina4_skill_install_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->prevRef = getenv('TINA4_SKILLS_REF');
    }

    protected function tearDown(): void
    {
        if ($this->prevRef === false) {
            putenv('TINA4_SKILLS_REF');
        } else {
            putenv('TINA4_SKILLS_REF=' . $this->prevRef);
        }
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testInstallSkillsLandsInProjectAndGlobal(): void
    {
        // Pin to a tag known to carry the split skills so the test is deterministic.
        putenv('TINA4_SKILLS_REF=3.13.59');

        $projSkills = $this->tmpDir . '/proj/.claude/skills';
        $globalSkills = $this->tmpDir . '/home/.claude/skills';

        $installed = AITools::installSkills(
            $this->tmpDir . '/proj',
            [$projSkills, $globalSkills]
        );

        $expected = ['tina4-developer-php', 'tina4-js', 'tina4-maintainer'];
        sort($installed);
        $this->assertSame($expected, $installed);

        foreach ([$projSkills, $globalSkills] as $dest) {
            foreach ($expected as $skill) {
                $this->assertFileExists("{$dest}/{$skill}/SKILL.md", "{$skill}/SKILL.md missing in {$dest}");
            }
            // a reference file came down too, for each skill
            $this->assertFileExists("{$dest}/tina4-developer-php/references/data-and-orm.md");
            $this->assertFileExists("{$dest}/tina4-js/references/signals-and-reactivity.md");
            $this->assertFileExists("{$dest}/tina4-maintainer/references/routing-and-orm.md");
        }

        $body = file_get_contents("{$projSkills}/tina4-developer-php/SKILL.md");
        $this->assertStringContainsString('The Tina4 Working Method', $body); // real 3.13.59 content
    }

    public function testClaudeSkillBlockUsesSplitSkillName(): void
    {
        // Private-method reflection needs no setAccessible() on PHP 8.1+.
        $method = (new ReflectionClass(AITools::class))->getMethod('skillBlock');
        $block = $method->invoke(null, 'CLAUDE.md');
        $this->assertStringContainsString('tina4-developer-php', $block);          // split name
        $this->assertStringNotContainsString('skills/tina4-developer/SKILL.md', $block); // old pre-split name gone
    }
}
