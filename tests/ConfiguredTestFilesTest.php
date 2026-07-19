<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * CI-collection guard for tina4stack/tina4-php#178.
 *
 * phpunit.xml once listed its test files by hand in a <file> list, so 78 of 186
 * `*Test.php` classes were never collected by CI — including
 * SessionCookieAttributesTest, the #174 session-cookie security regression. A
 * new test file was invisible to `vendor/bin/phpunit` (the config path CI runs)
 * until someone remembered to add a <file> line, while `composer test` (a bare
 * path arg) swept the whole directory and disagreed. The fix was directory
 * discovery (parity with Python testpaths / Ruby rspec / Node readdirSync).
 *
 * This guard pins that: every `tests/*Test.php` on disk MUST be collected by
 * phpunit.xml, so the hole can never silently reopen. It parses the SAME config
 * PHPUnit uses and resolves exactly what each testsuite collects (<file> +
 * <directory suffix> minus <exclude>), then compares against the real files on
 * disk. Same spirit as the docs audit-truth / pyapi guards.
 *
 * It is pure-logic over the real filesystem and the real config file — no mock,
 * no live dependency.
 */
final class ConfiguredTestFilesTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    /**
     * Resolve the absolute set of *Test.php files a phpunit configuration
     * collects across ALL its <testsuite> elements: every <file>, every
     * <directory suffix="…"> glob (recursive, matching PHPUnit), minus every
     * <exclude>. Keyed by absolute realpath so set maths is exact.
     *
     * @param \SimpleXMLElement $phpunit The parsed <phpunit> root.
     * @param string            $baseDir Directory the config's relative paths resolve against.
     * @return array<string, true> Absolute realpaths of collected files, as a set.
     */
    private function collectedFiles(\SimpleXMLElement $phpunit, string $baseDir): array
    {
        $collected = [];
        foreach ($phpunit->testsuites->testsuite as $suite) {
            // Excluded paths first, so directory globs can subtract them.
            $excluded = [];
            foreach ($suite->exclude as $exclude) {
                $real = realpath($baseDir . '/' . trim((string) $exclude));
                if ($real !== false) {
                    $excluded[$real] = true;
                }
            }

            foreach ($suite->file as $file) {
                $real = realpath($baseDir . '/' . trim((string) $file));
                if ($real !== false && !isset($excluded[$real])) {
                    $collected[$real] = true;
                }
            }

            foreach ($suite->directory as $directory) {
                $suffix = trim((string) ($directory['suffix'] ?? '')) ?: 'Test.php';
                $dir = realpath($baseDir . '/' . trim((string) $directory));
                if ($dir === false) {
                    continue;
                }
                foreach ($this->scanSuffix($dir, $suffix) as $path) {
                    if (!isset($excluded[$path])) {
                        $collected[$path] = true;
                    }
                }
            }
        }

        return $collected;
    }

    /**
     * Recursively find files under $dir whose name ends with $suffix — the same
     * traversal PHPUnit's <directory> performs.
     *
     * @return list<string> Absolute realpaths.
     */
    private function scanSuffix(string $dir, string $suffix): array
    {
        $found = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $found[] = $file->getRealPath();
            }
        }

        return $found;
    }

    /** @return list<string> Absolute realpaths of every tests/*Test.php on disk. */
    private function testFilesOnDisk(): array
    {
        return $this->scanSuffix(realpath(self::REPO_ROOT . '/tests'), 'Test.php');
    }

    /**
     * THE guard: no `tests/*Test.php` may exist on disk yet be missing from the
     * PHPUnit configuration. A gap here means CI silently ignores a test file —
     * exactly the #178 hole (SessionCookieAttributesTest never gated a build).
     */
    public function testEveryTestFileOnDiskIsCollectedByPhpunitConfig(): void
    {
        $xml = simplexml_load_file(realpath(self::REPO_ROOT . '/phpunit.xml'));
        $this->assertNotFalse($xml, 'phpunit.xml must be readable and valid XML');

        $collected = $this->collectedFiles($xml, realpath(self::REPO_ROOT));
        $onDisk = $this->testFilesOnDisk();

        $uncollected = array_values(array_filter(
            $onDisk,
            static fn(string $path): bool => !isset($collected[$path])
        ));

        $this->assertSame(
            [],
            $uncollected,
            "These test files exist on disk but phpunit.xml does not collect them, so "
            . "CI never runs them (tina4-php#178). Use <directory suffix=\"Test.php\"> "
            . "discovery instead of a hand-kept <file> list:\n  - "
            . implode("\n  - ", array_map(
                static fn(string $p): string => 'tests/' . basename($p),
                $uncollected
            ))
        );
    }

    /**
     * Belt-and-braces: the v3 suite must use <directory> discovery, not a
     * hand-kept <file> list. This is what actually keeps the collection
     * automatic — a future edit that reverts to listing files by hand would pass
     * the test above only until the next new file, then silently drop it. Fail
     * loudly the moment discovery is removed.
     */
    public function testV3SuiteUsesDirectoryDiscovery(): void
    {
        $xml = simplexml_load_file(realpath(self::REPO_ROOT . '/phpunit.xml'));
        $hasDirectoryDiscovery = false;
        foreach ($xml->testsuites->testsuite as $suite) {
            if ((string) $suite['name'] === 'Tina4 v3 Tests' && isset($suite->directory)) {
                $hasDirectoryDiscovery = true;
            }
        }
        $this->assertTrue(
            $hasDirectoryDiscovery,
            'The "Tina4 v3 Tests" suite must collect via <directory suffix="Test.php"> '
            . 'so new test files gate CI automatically (tina4-php#178).'
        );
    }

    /**
     * Negative case — proves the guard has TEETH.
     *
     * Feed the SAME resolver a synthetic hand-file-list config (the old #178
     * shape) that omits one real file on disk, and assert the resolver reports
     * that file as uncollected. Without this, a broken resolver that returned
     * "everything is collected" would let the positive test pass vacuously.
     */
    public function testResolverDetectsAFileMissingFromAHandList(): void
    {
        $onDisk = $this->testFilesOnDisk();
        sort($onDisk);
        $this->assertGreaterThan(2, count($onDisk), 'need several files on disk to build the fixture');

        // A hand list that registers every file EXCEPT the first — the #178 bug.
        $dropped = $onDisk[0];
        $entries = '';
        foreach (array_slice($onDisk, 1) as $path) {
            $entries .= '        <file>tests/' . basename($path) . "</file>\n";
        }
        $syntheticXml = <<<XML
        <phpunit>
            <testsuites>
                <testsuite name="Hand List">
        {$entries}    </testsuite>
            </testsuites>
        </phpunit>
        XML;

        $xml = simplexml_load_string($syntheticXml);
        $collected = $this->collectedFiles($xml, realpath(self::REPO_ROOT));

        $this->assertArrayNotHasKey(
            $dropped,
            $collected,
            'the dropped file must NOT appear as collected'
        );
        // And the real directory-discovery config MUST cover that same file —
        // i.e. discovery fixes exactly what the hand list dropped.
        $realXml = simplexml_load_file(realpath(self::REPO_ROOT . '/phpunit.xml'));
        $realCollected = $this->collectedFiles($realXml, realpath(self::REPO_ROOT));
        $this->assertArrayHasKey(
            $dropped,
            $realCollected,
            'directory discovery must collect the file the hand list dropped'
        );
    }
}
