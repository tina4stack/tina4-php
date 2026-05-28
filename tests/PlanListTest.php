<?php

/**
 * Tests for Tina4\Plan::listPlans() — the merge between the canonical
 * user-curated `plan/` directory and the Rust agent's `.tina4/plans/`
 * output directory.
 *
 * Each test scopes itself to a temporary project root via chdir() so
 * the framework's projectRoot() picks up the sandbox rather than the
 * real repo's `plan/` directory.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Plan;

class PlanListTest extends TestCase
{
    private string $sandbox = '';
    private string $originalCwd = '';

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: '';
        $this->sandbox = sys_get_temp_dir() . '/tina4_planlist_' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0755, true);
        chdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->rrmdir($this->sandbox);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writePlan(string $relDir, string $name, string $title, array $steps = []): void
    {
        $dir = $this->sandbox . '/' . $relDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $body = "# {$title}\n\n## Steps\n\n";
        foreach ($steps as $step) {
            $box = !empty($step['done']) ? 'x' : ' ';
            $body .= "- [{$box}] {$step['text']}\n";
        }
        file_put_contents($dir . '/' . $name, $body);
    }

    public function testListPlansMergesBothDirs(): void
    {
        $this->writePlan('plan', 'foo.md', 'Foo plan', [['text' => 'a', 'done' => false]]);
        $this->writePlan('.tina4/plans', 'bar.md', 'Bar plan', [['text' => 'b', 'done' => true]]);

        $plans = Plan::listPlans();
        $names = array_column($plans, 'name');

        $this->assertCount(2, $plans);
        $this->assertContains('foo.md', $names);
        $this->assertContains('bar.md', $names);
    }

    public function testListPlansDedupsByFilename(): void
    {
        // Same filename in both dirs — plan/ wins.
        $this->writePlan('plan', 'x.md', 'Canonical X', [['text' => 'canonical', 'done' => false]]);
        $this->writePlan('.tina4/plans', 'x.md', 'Rust X', [['text' => 'rust', 'done' => true]]);

        $plans = Plan::listPlans();
        $names = array_column($plans, 'name');

        $this->assertCount(1, $plans);
        $this->assertSame(['x.md'], $names);
        // The canonical plan/ entry wins — title must be "Canonical X".
        $this->assertSame('Canonical X', $plans[0]['title']);
        // And the path must point at plan/x.md, not .tina4/plans/x.md.
        $this->assertSame('plan/x.md', $plans[0]['path']);
    }

    public function testListPlansSortsNewestFirst(): void
    {
        // Plan filenames lead with unix timestamps — newest-first by
        // name sorts reverse-chronologically.
        $this->writePlan('plan', '1779800000-a.md', 'Older');
        $this->writePlan('plan', '1779900000-b.md', 'Newer');

        $plans = Plan::listPlans();
        $names = array_column($plans, 'name');

        $this->assertSame(['1779900000-b.md', '1779800000-a.md'], $names);
    }

    public function testListPlansIncludesPath(): void
    {
        $this->writePlan('plan', 'one.md', 'One');
        $this->writePlan('.tina4/plans', 'two.md', 'Two');

        $plans = Plan::listPlans();

        foreach ($plans as $p) {
            $this->assertArrayHasKey('path', $p);
            $this->assertIsString($p['path']);
            $this->assertNotEmpty($p['path']);
            // Path must be relative — never start with a leading slash.
            $this->assertStringStartsNotWith('/', $p['path']);
        }
        $byName = [];
        foreach ($plans as $p) {
            $byName[$p['name']] = $p['path'];
        }
        $this->assertSame('plan/one.md', $byName['one.md']);
        $this->assertSame('.tina4/plans/two.md', $byName['two.md']);
    }

    public function testListPlansHandlesMissingDirGracefully(): void
    {
        // Only plan/ exists — must not fail loudly.
        $this->writePlan('plan', 'solo.md', 'Solo');

        $plans = Plan::listPlans();
        $this->assertCount(1, $plans);
        $this->assertSame('solo.md', $plans[0]['name']);
    }
}
