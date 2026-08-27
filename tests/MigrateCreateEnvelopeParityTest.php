<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the migrate:create <-> generate migration parity
 * introduced in 3.13.121: both CLI paths now delegate to the same
 * runResolvedGenerator() flow and therefore emit the identical
 * ADR-0063 generate_v1_1 envelope, differing only in the "no test
 * co-emitted" semantic that migrate:create preserves.
 *
 * Coverage:
 *   - Positive parity (--json --dry-run): both return valid envelopes with
 *     the SAME shape: same command/target, same edit_hints[] labels, same
 *     next[] array. resolution_contract.version is asserted via
 *     `commands --json`.
 *   - File-shape parity (write mode): both write {timestamp}_{name}.sql +
 *     {timestamp}_{name}.down.sql containing the SAME body (modulo the
 *     timestamps in the "Created:" line), with the tina4:edit markers
 *     present.
 *   - Test-emission: migrate:create writes ONLY the two .sql files (no
 *     tests/*MigrationTest.php); `generate migration` with default
 *     emitTest MAY co-emit one (verified positive on a create_X name).
 *   - Error paths: both exit non-zero with a Usage: line when no name
 *     argument is given.
 *   - Mutation gate: stash the delegation change (revert bin/tina4php
 *     migrate:create to its old inline "-- Migration ..." template) and
 *     rerun the positive-parity test — it MUST fail. Restore the file
 *     when done.
 *
 * NO mocks. Every case shells out to REAL bin/tina4php via proc_open into
 * a fresh temp cwd, and asserts on STDOUT / STDERR / exit / files on disk.
 * Mirrors GenerateEnvelopeV11Test's runCli() shape.
 */

use PHPUnit\Framework\TestCase;

class MigrateCreateEnvelopeParityTest extends TestCase
{
    private static string $bin;

    /**
     * Per-run temp dirs the tests create. Reaped in tearDown even when an
     * assertion aborts mid-test.
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
     * Fresh empty temp dir under sys_get_temp_dir(). The CLI runs against
     * this cwd; nothing under the repo is touched.
     */
    private function freshProjectDir(string $slug = 'parity'): string
    {
        $dir = sys_get_temp_dir() . "/tina4-migrate-create-{$slug}-" . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true), "could not create {$dir}");
        $this->createdDirs[] = $dir;
        return $dir;
    }

    /**
     * Run REAL bin/tina4php as a subprocess in $cwd. Returns
     * [stdout, stderr, exit]. display_errors=0 keeps the JSON contract
     * from being polluted by host-side "Warning: PHP Startup..." lines.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(array $args, string $cwd): array
    {
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
     * 1. Positive parity — both CLI paths produce the SAME envelope shape
     *    under --json --dry-run. Same command/target, same edit_hints[]
     *    labels, same next[] array. Timestamps in file paths naturally
     *    differ per invocation and are excluded from the equality check.
     */
    public function testMigrateCreateAndGenerateMigrationEmitTheSameEnvelope(): void
    {
        $cwdA = $this->freshProjectDir('mc');
        $cwdB = $this->freshProjectDir('gm');

        $a = $this->runCli(['migrate:create', 'add_users', '--json', '--dry-run'], $cwdA);
        $b = $this->runCli(['generate', 'migration', 'add_users', '--json', '--dry-run'], $cwdB);

        $this->assertSame(0, $a['exit'], "migrate:create stderr:\n{$a['stderr']}");
        $this->assertSame(0, $b['exit'], "generate migration stderr:\n{$b['stderr']}");

        $envA = json_decode($a['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $envB = json_decode($b['stdout'], true, flags: JSON_THROW_ON_ERROR);

        // Both must be `generate` + `migration` — the target is the ADR-0063
        // envelope verb, not the CLI command that invoked it.
        $this->assertSame('generate', $envA['command'], 'migrate:create envelope command');
        $this->assertSame('generate', $envB['command'], 'generate migration envelope command');
        $this->assertSame('migration', $envA['target'], 'migrate:create envelope target');
        $this->assertSame('migration', $envB['target'], 'generate migration envelope target');
        $this->assertTrue($envA['dry_run']);
        $this->assertTrue($envB['dry_run']);

        // Edit-hint LABELS must match exactly. File paths embed a timestamp
        // and will differ; the labels are the stable, comparable field.
        $labelsA = array_column($envA['resolution']['edit_hints'], 'label');
        $labelsB = array_column($envB['resolution']['edit_hints'], 'label');
        sort($labelsA);
        sort($labelsB);
        $this->assertNotEmpty($labelsA, 'migrate:create should emit tina4:edit markers');
        $this->assertSame($labelsB, $labelsA, 'edit_hints labels must be identical across the two paths');

        // Next[] must be identical byte-for-byte — same curated ADR-0063
        // steps, no placeholder skew.
        $this->assertSame(
            $envB['resolution']['next'],
            $envA['resolution']['next'],
            'next[] must be identical across the two paths'
        );
        $this->assertNotEmpty($envA['resolution']['next'], 'next[] must not be empty');

        // File-path SHAPE parity (modulo timestamp).
        $stripTs = fn(string $s): string => preg_replace('/\d{14}_/', 'TS_', $s);
        $this->assertSame(
            $stripTs($envB['resolution']['file_path']),
            $stripTs($envA['resolution']['file_path']),
            'file_path shape (modulo timestamp) must match'
        );
        $this->assertSame(
            $stripTs($envB['resolution']['migration_path']),
            $stripTs($envA['resolution']['migration_path']),
            'migration_path (down.sql) shape must match'
        );

        // dry-run really did not touch the cwd.
        $this->assertFalse(is_dir("{$cwdA}/migrations"), 'dry-run must not create migrations/ (A)');
        $this->assertFalse(is_dir("{$cwdB}/migrations"), 'dry-run must not create migrations/ (B)');
    }

    /**
     * 2. resolution_contract advertised by `commands --json` — a downstream
     *    tool discovering the CLI keys off this. Same contract for both
     *    callers (there is only one; it's global to the manifest).
     */
    public function testCommandsManifestAdvertisesV11Contract(): void
    {
        $cwd = $this->freshProjectDir('mfst');
        $result = $this->runCli(['commands', '--json'], $cwd);

        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");
        $manifest = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('resolution_contract', $manifest);
        $this->assertSame('1.1', $manifest['resolution_contract']['version']);
        $this->assertSame('generate_v1_1', $manifest['resolution_contract']['envelope']);
    }

    /**
     * 3. File-shape parity in WRITE mode — both paths produce a
     *    {timestamp}_{name}.sql + {timestamp}_{name}.down.sql pair whose
     *    contents (minus the "Created:" timestamp header line) are
     *    byte-identical. tina4:edit markers land in the same place in both.
     */
    public function testWriteModeProducesEquivalentMigrationFileBodies(): void
    {
        $cwdA = $this->freshProjectDir('write-mc');
        $cwdB = $this->freshProjectDir('write-gm');

        // Bare form — write mode, no --json / --dry-run.
        $a = $this->runCli(['migrate:create', 'add_users'], $cwdA);
        $b = $this->runCli(['generate', 'migration', 'add_users'], $cwdB);

        $this->assertSame(0, $a['exit'], "migrate:create stderr:\n{$a['stderr']}");
        $this->assertSame(0, $b['exit'], "generate migration stderr:\n{$b['stderr']}");

        $upA = glob("{$cwdA}/migrations/*_add_users.sql");
        $upB = glob("{$cwdB}/migrations/*_add_users.sql");
        $this->assertCount(1, $upA, 'migrate:create must write one UP .sql');
        $this->assertCount(1, $upB, 'generate migration must write one UP .sql');

        $downA = glob("{$cwdA}/migrations/*_add_users.down.sql");
        $downB = glob("{$cwdB}/migrations/*_add_users.down.sql");
        $this->assertCount(1, $downA, 'migrate:create must write one .down.sql');
        $this->assertCount(1, $downB, 'generate migration must write one .down.sql');

        // Body equality modulo the "Created: <timestamp>" line, which
        // naturally varies per invocation.
        $normalise = static fn(string $body): string => preg_replace(
            '/-- Created: [0-9\- :]+/',
            '-- Created: TS',
            $body
        );
        $this->assertSame(
            $normalise(file_get_contents($upB[0])),
            $normalise(file_get_contents($upA[0])),
            'UP migration bodies must match (modulo Created: timestamp)'
        );
        $this->assertSame(
            $normalise(file_get_contents($downB[0])),
            $normalise(file_get_contents($downA[0])),
            'DOWN migration bodies must match (modulo Created: timestamp)'
        );

        // tina4:edit markers land in both files, both paths.
        $this->assertStringContainsString(
            'tina4:edit',
            file_get_contents($upA[0]),
            'migrate:create UP file must carry a tina4:edit marker'
        );
        $this->assertStringContainsString(
            'tina4:edit',
            file_get_contents($downA[0]),
            'migrate:create DOWN file must carry a tina4:edit marker'
        );
        $this->assertStringContainsString('tina4:edit', file_get_contents($upB[0]));
        $this->assertStringContainsString('tina4:edit', file_get_contents($downB[0]));
    }

    /**
     * 4. Test-emission divergence — migrate:create NEVER co-emits a test
     *    (its historical "just a migration, no test" contract), even for a
     *    schema-aware `create_X` name that would otherwise trigger one.
     *    `generate migration create_X` DOES co-emit one under the default
     *    emitTest.
     */
    public function testMigrateCreateNeverCoemitsTestGenerateMigrationMay(): void
    {
        $cwdA = $this->freshProjectDir('nt-mc');
        $cwdB = $this->freshProjectDir('nt-gm');

        // `create_widgets` is exactly the shape that triggers a co-emitted
        // WidgetsMigrationTest.php under generate migration.
        $a = $this->runCli(['migrate:create', 'create_widgets'], $cwdA);
        $b = $this->runCli(['generate', 'migration', 'create_widgets'], $cwdB);

        $this->assertSame(0, $a['exit'], "migrate:create stderr:\n{$a['stderr']}");
        $this->assertSame(0, $b['exit'], "generate migration stderr:\n{$b['stderr']}");

        // migrate:create wrote no tests/ tree at all.
        $this->assertFalse(
            is_dir("{$cwdA}/tests"),
            'migrate:create must never create a tests/ tree (it is documented as no-test)'
        );
        $this->assertEmpty(
            glob("{$cwdA}/tests/*MigrationTest.php") ?: [],
            'migrate:create must never write a *MigrationTest.php'
        );

        // generate migration create_X co-emitted the matching test.
        $this->assertFileExists(
            "{$cwdB}/tests/WidgetsMigrationTest.php",
            'generate migration create_widgets must co-emit tests/WidgetsMigrationTest.php'
        );
    }

    /**
     * 5. Error path — both commands exit non-zero with a Usage: line when
     *    the description / name is missing. The exact wording differs
     *    (each names its own verb) but the failure MODE is the same.
     */
    public function testMissingArgsErrorsWithUsageLine(): void
    {
        $cwdA = $this->freshProjectDir('usage-mc');
        $cwdB = $this->freshProjectDir('usage-gm');

        $a = $this->runCli(['migrate:create'], $cwdA);
        $b = $this->runCli(['generate', 'migration'], $cwdB);

        $this->assertNotSame(0, $a['exit'], 'migrate:create with no desc must exit non-zero');
        $this->assertNotSame(0, $b['exit'], 'generate migration with no name must exit non-zero');

        // Both messages contain "Usage:" so a caller sees they mis-invoked.
        // migrate:create prints to STDOUT (kept parity with existing usage
        // messages elsewhere in the CLI); generate migration prints its
        // usage line to STDOUT too. Check both streams to survive either.
        $combinedA = $a['stdout'] . $a['stderr'];
        $combinedB = $b['stdout'] . $b['stderr'];
        $this->assertStringContainsString(
            'Usage:',
            $combinedA,
            'migrate:create must print a Usage: line'
        );
        $this->assertStringContainsString(
            'migrate:create',
            $combinedA,
            'migrate:create Usage: must name migrate:create'
        );
        $this->assertStringContainsString(
            'Usage:',
            $combinedB,
            'generate migration must print a Usage: line'
        );
    }

    /**
     * 6. Mutation gate — stash the delegation by rewriting the
     *    `case 'migrate:create':` block back to its old inline "-- Migration
     *    ..." template, rerun the positive-parity assertion, and confirm
     *    it FAILS. Restore the file on the way out so no permanent change
     *    leaks. Proves the positive test is a real gate, not a vacuous one
     *    that would pass on any envelope emission.
     */
    public function testMutationGateStrippingDelegationBreaksParity(): void
    {
        $binPath = self::$bin;
        $original = file_get_contents($binPath);
        $this->assertNotFalse($original, 'could not read bin/tina4php for mutation gate');

        // Replace the delegating migrate:create block with the pre-3.13.121
        // inline template, which writes a bare `-- Migration ...` file
        // and NO envelope. Any surviving envelope emission comes from the
        // helper, so a broken parity contract would still emit one.
        $legacyBlock = <<<'PHP'
    case 'migrate:create':
        $name = implode(' ', $args) ?: 'unnamed_migration';
        $migrationsDir = $cwd . '/migrations';
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }
        // Create migration file — no database needed
        $timestamp = date('YmdHis');
        $safeName = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($name)));
        $fileName = "{$timestamp}_{$safeName}.sql";
        $filePath = $migrationsDir . '/' . $fileName;
        file_put_contents($filePath, "-- Migration: {$name}\n-- Created: " . date('Y-m-d H:i:s') . "\n\n");
        echo "Created: migrations/{$fileName}\n";
        break;
PHP;

        // Regex-match the current delegating block so the mutant swap is
        // deterministic regardless of whitespace tweaks. Fails-hard if the
        // structure doesn't match — the mutation gate must be honest.
        $pattern = '/    case \'migrate:create\':\n.*?runResolvedGenerator\(\n(?:.*?\n){0,10}?        \);\n        break;/s';
        $mutant = preg_replace($pattern, $legacyBlock, $original, 1, $count);
        $this->assertSame(1, $count, 'mutation gate: could not find the delegating migrate:create block to swap');
        $this->assertNotSame($original, $mutant, 'mutation gate: swap produced no change');

        try {
            $this->assertNotFalse(
                file_put_contents($binPath, $mutant),
                'mutation gate: could not write the mutated bin/tina4php'
            );

            $cwd = $this->freshProjectDir('mutant');
            $result = $this->runCli(['migrate:create', 'add_users', '--json', '--dry-run'], $cwd);

            // The old inline path prints "Created: migrations/..." to STDOUT
            // and NO JSON envelope. So `json_decode` should fail OR the
            // decoded envelope should not have the envelope shape.
            $envelope = json_decode($result['stdout'], true);
            $isValidEnvelope = is_array($envelope)
                && ($envelope['command'] ?? null) === 'generate'
                && ($envelope['target'] ?? null) === 'migration';

            $this->assertFalse(
                $isValidEnvelope,
                "mutation gate: mutated migrate:create must NOT emit the generate_v1_1 envelope.\nSTDOUT was:\n{$result['stdout']}"
            );
        } finally {
            // Restore no matter what — a test that leaves the binary
            // mutated would poison every later test in the suite.
            $this->assertNotFalse(
                file_put_contents($binPath, $original),
                'mutation gate: could not restore bin/tina4php — MANUAL FIX REQUIRED'
            );
        }
    }
}
