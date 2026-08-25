<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the `generate` resolution-transparency contract added in
 * 3.13.117 (AI-agent experience):
 *
 *   - `--json` emits ONE clean JSON envelope on STDOUT (all writes-time
 *      chatter routed to STDERR).
 *   - `--dry-run` computes the resolution and writes NOTHING.
 *   - the bare form prints a human resolution block to STDERR BEFORE the
 *      writes, including the "auto-pluralized" note when a SQL reserved
 *      word is swapped (Order -> orders).
 *   - `commands --json` advertises `resolution_contract.version = "1"`.
 *
 * NO mocks. Every case shells out to REAL bin/tina4php in a REAL temp dir and
 * asserts on the actual STDOUT/STDERR/exit code + files that would land on
 * disk for a caller. Mirrors CommandsManifestTest's runCli() shape so the CLI
 * is exercised exactly as the tina4 Rust client drives it.
 */

use PHPUnit\Framework\TestCase;

class GenerateResolutionTest extends TestCase
{
    private static string $bin;

    /**
     * Per-run temp dirs the tests create. Tracked so tearDown can reap them
     * even if an assertion aborts mid-test — a leaked src/orm/ under /tmp
     * would eventually collide with a second run.
     *
     * @var list<string>
     */
    private array $createdDirs = [];

    public static function setUpBeforeClass(): void
    {
        $bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse($bin, 'bin/tina4php not found');
        self::$bin = $bin;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->reapDir($dir);
            }
        }
        $this->createdDirs = [];
    }

    private function reapDir(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Fresh empty temp dir under sys_get_temp_dir(). The generator runs
     * against this cwd; nothing under the repo is touched.
     */
    private function freshProjectDir(): string
    {
        $dir = sys_get_temp_dir() . '/tina4-generate-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true), "could not create {$dir}");
        $this->createdDirs[] = $dir;
        return $dir;
    }

    /**
     * Run REAL bin/tina4php as a subprocess in $cwd. Returns [stdout, stderr, exit].
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(array $args, string $cwd): array
    {
        // display_errors=0 / display_startup_errors=0 match the production /
        // CI ini shape (errors go to STDERR only, never mirrored on STDOUT).
        // A dev Mac may have display_errors=On, which would leak host-side
        // "Warning: PHP Startup: Unable to load ..." onto STDOUT and break
        // the JSON contract this suite enforces. The FRAMEWORK's contract is
        // "one clean JSON envelope on STDOUT" — the test asserts that against
        // a production-shaped subprocess, not the caller's ini.
        $cmd = array_merge(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', self::$bin],
            $args
        );
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        $this->assertIsResource($process, 'proc_open failed');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /**
     * 1. --json + --dry-run — envelope is valid JSON, dry_run true, empty
     *    transformations for a non-reserved name, NO files created.
     */
    public function testGenerateModelJsonDryRunReturnsEnvelope(): void
    {
        $cwd = $this->freshProjectDir();
        $result = $this->runCli(['generate', 'model', 'Product', '--json', '--dry-run'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $envelope = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('generate', $envelope['command']);
        $this->assertSame('model', $envelope['target']);
        $this->assertSame('Product', $envelope['input']['name']);
        $this->assertTrue($envelope['dry_run']);
        $this->assertSame([], $envelope['actions_taken']);
        $this->assertSame([], $envelope['resolution']['transformations']);
        $this->assertSame('Product', $envelope['resolution']['class_name']);
        $this->assertSame('product', $envelope['resolution']['table_name']);
        $this->assertSame('src/orm/Product.php', $envelope['resolution']['file_path']);

        // NO files written under the temp cwd — the whole point of dry-run.
        $this->assertFalse(is_dir("{$cwd}/src"), 'dry-run must not create src/');
        $this->assertFalse(is_dir("{$cwd}/migrations"), 'dry-run must not create migrations/');
    }

    /**
     * 2. Reserved word — `Order` names the reserved_word_pluralize
     *    transformation, showing the from/to and a "SQL reserved word"
     *    reason a caller can act on.
     */
    public function testGenerateModelReservedWordNamesTransformation(): void
    {
        $cwd = $this->freshProjectDir();
        $result = $this->runCli(['generate', 'model', 'Order', '--json', '--dry-run'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $envelope = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('orders', $envelope['resolution']['table_name']);
        $this->assertNotEmpty($envelope['resolution']['transformations']);
        $transform = $envelope['resolution']['transformations'][0];
        $this->assertSame('reserved_word_pluralize', $transform['kind']);
        $this->assertSame('order', $transform['from']);
        $this->assertSame('orders', $transform['to']);
        $this->assertStringContainsString('SQL reserved word', $transform['reason']);
    }

    /**
     * 3. Bare form — files land on disk AND the STDERR resolution block
     *    names the class, the file path, and the writes it made.
     *
     *    "Generated model Foo" is the human-readable header the resolution
     *    block emits before the writer runs (dry-run flips it to "Would
     *    generate"; the bare form always uses "Generated").
     */
    public function testGenerateModelBareWritesFilesAndPrintsResolutionToStderr(): void
    {
        $cwd = $this->freshProjectDir();
        $result = $this->runCli(['generate', 'model', 'Foo'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $this->assertStringContainsString('Generated model Foo', $result['stderr']);
        $this->assertStringContainsString('src/orm/Foo.php', $result['stderr']);
        $this->assertFileExists("{$cwd}/src/orm/Foo.php");
        // Bare form also writes the matching migration under migrations/.
        $migrations = glob("{$cwd}/migrations/*_create_foo.sql");
        $this->assertNotEmpty($migrations, 'expected matching migration under migrations/');
    }

    /**
     * 4. Bare + reserved — the STDERR block names both `auto-pluralized`
     *    and `SQL reserved word` so the operator sees WHY the table is not
     *    the raw class name.
     */
    public function testGenerateModelReservedNamePrintsPluralizeNoteToStderr(): void
    {
        $cwd = $this->freshProjectDir();
        $result = $this->runCli(['generate', 'model', 'Order'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $this->assertStringContainsString('auto-pluralized', $result['stderr']);
        $this->assertStringContainsString('SQL reserved word', $result['stderr']);
        // Model file DOES land on disk — bare form still writes.
        $this->assertFileExists("{$cwd}/src/orm/Order.php");
        // And the generated tableName carries the safe plural, not `order`.
        $modelSrc = file_get_contents("{$cwd}/src/orm/Order.php");
        $this->assertStringContainsString("\$tableName = 'orders'", $modelSrc);
    }

    /**
     * 5. Manifest — `commands --json` advertises the wire contract, so a
     *    tool that discovers this CLI knows the envelope shape it will
     *    parse. Version bump is a breaking change, gated by this test.
     */
    public function testCommandsManifestAdvertisesResolutionContract(): void
    {
        $cwd = $this->freshProjectDir();
        $result = $this->runCli(['commands', '--json'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $manifest = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('resolution_contract', $manifest, 'manifest must advertise resolution_contract');
        $this->assertSame('1', $manifest['resolution_contract']['version']);
        $this->assertSame('generate_v1', $manifest['resolution_contract']['envelope']);
    }
}
