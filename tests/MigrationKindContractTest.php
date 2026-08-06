<?php declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/**
 * migration_contract :: createMigration validates its $kind
 *
 * MEASURED 2026-08-06 across all four frameworks: the accepted value for a CODE
 * migration differed in every one, and NOT ONE validated it.
 *
 *   python "python"   php "php"   ruby "ruby" or "python"   node "class"
 *
 * So createMigration('add users', kind: 'python') produced a code migration in
 * Python and Ruby and a SILENT .sql FILE in PHP and Node - the same call, four
 * artefacts, no error. The caller finds out when the migration does nothing
 * they wrote.
 *
 * 'code' is now canonical in all four; each keeps its language name as a legacy
 * alias; anything else throws.
 */
class MigrationKindContractTest extends TestCase
{
    private function tmpDir(): string
    {
        return \TempPath::dir('tina4_migkind_');
    }

    public function testCodeIsTheCanonicalKind(): void
    {
        $path = \Tina4\Migration::createMigration('add users', $this->tmpDir(), 'code');
        $this->assertSame('php', pathinfo($path, PATHINFO_EXTENSION));
    }

    public function testTheLanguageNameStillWorksAsALegacyAlias(): void
    {
        $path = \Tina4\Migration::createMigration('add users', $this->tmpDir(), 'php');
        $this->assertSame('php', pathinfo($path, PATHINFO_EXTENSION));
    }

    public function testSqlIsTheDefaultAndUnchanged(): void
    {
        $dir = $this->tmpDir();
        $this->assertSame('sql', pathinfo(\Tina4\Migration::createMigration('a', $dir), PATHINFO_EXTENSION));
        $this->assertSame('sql', pathinfo(\Tina4\Migration::createMigration('b', $dir, 'sql'), PATHINFO_EXTENSION));
    }

    public function testAnUnknownKindThrowsInsteadOfSilentlyWritingSql(): void
    {
        // Another framework's spelling is the most likely typo, and it used to
        // produce a .sql file with no complaint.
        foreach (['python', 'ruby', 'class', 'typo'] as $bogus) {
            try {
                \Tina4\Migration::createMigration('add users', $this->tmpDir(), $bogus);
                $this->fail("kind '{$bogus}' did not throw - it silently produced a file");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unknown migration kind', $e->getMessage());
            }
        }
    }
}
