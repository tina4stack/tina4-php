<?php

/**
 * Lock-in: exactly one dev-admin bundle ships.
 *
 * src/public/js/ must carry tina4-dev-admin.min.js and nothing else matching
 * tina4-dev-admin*.js. PHP already dropped its unminified duplicate (php#181);
 * Python, Ruby and Node each carried tina4-dev-admin.js AND
 * tina4-dev-admin.min.js as BYTE-IDENTICAL copies, roughly 940K of dead weight
 * in every install, referenced by nothing.
 *
 * This is the PHP half of the four-framework gate: copying the bundle back
 * under a second name would silently double the Composer archive again.
 *
 * Reads the REAL shipped file off disk -- no mocks, no fixtures.
 *
 * Parity: Python tests/test_static.py, Ruby spec/dev_admin_spec.rb,
 * Node test/devAdminBundleDedup.test.ts.
 */
class DevAdminBundleDedupTest extends \PHPUnit\Framework\TestCase
{
    private function jsDir(): string
    {
        return __DIR__ . '/../src/public/js';
    }

    public function testShippedBundleExists(): void
    {
        $this->assertFileExists($this->jsDir() . '/tina4-dev-admin.min.js');
    }

    public function testNoUnminifiedDuplicate(): void
    {
        $this->assertFileDoesNotExist(
            $this->jsDir() . '/tina4-dev-admin.js',
            'tina4-dev-admin.js is back -- that is ~940K of byte-identical dead weight in the Composer archive'
        );
    }

    public function testExactlyOneDevAdminBundle(): void
    {
        // Guards the general case: a differently-named copy (-full.js, -dev.js)
        // would slip a check that only names tina4-dev-admin.js.
        $copies = glob($this->jsDir() . '/tina4-dev-admin*.js') ?: [];
        $names = array_map('basename', $copies);
        sort($names);
        $this->assertSame(['tina4-dev-admin.min.js'], $names);
    }

    public function testKeptBundleIsTheRealThing(): void
    {
        // A bad dedup could leave a stub behind; assert substance, not just presence.
        $js = (string)file_get_contents($this->jsDir() . '/tina4-dev-admin.min.js');
        $this->assertGreaterThan(100_000, strlen($js));
        $this->assertStringContainsString('db-table-list', $js);
    }
}
