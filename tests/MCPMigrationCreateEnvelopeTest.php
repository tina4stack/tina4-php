<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the MCP migration_create <-> CLI migrate:create unification
 * introduced in 3.13.121: the MCP tool now delegates to the SAME resolution-
 * aware handler the CLI uses (bin/tina4php migrate:create --json), so the
 * two surfaces produce byte-for-byte identical output — timestamp filename
 * (YYYYMMDDHHMMSS_x.sql, not the old 000001_x.sql sequential prefix) and
 * the ADR-0063 generate_v1_1 envelope with edit_hints[] and next[].
 *
 * Coverage:
 *   1. Positive envelope — MCP `migration_create` returns
 *      {ok:true, created, resolution} with populated edit_hints[] and
 *      next[]; resolution.file_path names a real file the tool wrote.
 *   2. Filename shape — the created filename matches
 *      /^\d{14}_[a-z0-9_]+\.sql$/ (14-digit timestamp, NOT 6-digit sequential).
 *   3. Envelope parity with CLI — the resolution the MCP tool returns is
 *      structurally identical to `migrate:create --json --dry-run` for the
 *      same input (same command/target, same edit_hints[] labels, same
 *      next[]). resolution_contract.envelope is asserted via
 *      `commands --json` = "generate_v1_1".
 *   4. Duplicate-slug guard preserved — a second call for the same
 *      description returns {ok:false, error, existing[...]} instead of
 *      spawning a second migration for the same schema change.
 *   5. No test co-emitted — migration_create keeps the historical
 *      "just a migration, no test" contract; tests/ is not touched even
 *      for a schema-aware create_X name that WOULD trigger a test under
 *      `generate migration`.
 *   6. Mutation gate — stash the delegation (revert MCP `migration_create`
 *      to its old inline sequential-prefix template) and rerun the
 *      positive envelope test; assert it FAILS. Restore on the way out.
 *
 * NO mocks. Every case invokes the REAL MCP tool via $server->callTool(),
 * which internally shells out to REAL bin/tina4php migrate:create. The CLI
 * comparison uses proc_open the same way MigrateCreateEnvelopeParityTest
 * does. Every temp dir is reaped in tearDown even when an assertion aborts.
 */

use PHPUnit\Framework\TestCase;
use Tina4\McpDevTools;
use Tina4\McpServer;

class MCPMigrationCreateEnvelopeTest extends TestCase
{
    private static string $bin;

    /**
     * Per-run temp dirs the tests create. Reaped in tearDown even when an
     * assertion aborts mid-test.
     *
     * @var list<string>
     */
    private array $createdDirs = [];

    /** cwd to restore after each test that chdirs into a temp dir. */
    private string $savedCwd = '';

    public static function setUpBeforeClass(): void
    {
        $bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse($bin, 'bin/tina4php not found');
        self::$bin = $bin;
    }

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->savedCwd = $cwd !== false ? $cwd : sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        // Restore cwd BEFORE reaping — reaping a dir we are cwd'd into
        // would leave the process in a phantom directory.
        @chdir($this->savedCwd);
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
     * Fresh empty temp dir under sys_get_temp_dir(). The MCP tool + the
     * CLI subprocess both run against this cwd; nothing under the repo
     * is touched.
     */
    private function freshProjectDir(string $slug = 'mcp'): string
    {
        $dir = sys_get_temp_dir() . "/tina4-mcp-migcreate-{$slug}-" . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700, true), "could not create {$dir}");
        $this->createdDirs[] = $dir;
        return $dir;
    }

    /**
     * Register the built-in dev tools on a fresh MCP server and return it.
     * Uses a per-test route path so parallel isolation is guaranteed.
     */
    private function makeServerWithDevTools(string $suffix): McpServer
    {
        $server = new McpServer('/mcp-migcreate-' . $suffix, 'MCP MigrationCreate Test');
        McpDevTools::register($server);
        return $server;
    }

    /**
     * Run REAL bin/tina4php as a subprocess in $cwd. Returns
     * [stdout, stderr, exit]. display_errors=0 keeps host-side PHP startup
     * chatter (e.g. missing pecl extensions) out of the JSON contract.
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
     * 1. Positive envelope — MCP migration_create returns
     *    {ok:true, created, resolution} carrying the ADR-0063 generate_v1_1
     *    shape: populated edit_hints[] (each a {file,line,label}) and a
     *    populated next[] with curated steps. resolution.file_path names
     *    the real file the tool wrote (with a 14-digit timestamp prefix).
     */
    public function testMcpToolReturnsGenerateV11EnvelopeWithEditHintsAndNext(): void
    {
        $cwd = $this->freshProjectDir('pos');
        chdir($cwd);
        $server = $this->makeServerWithDevTools('pos');

        $result = $server->callTool('migration_create', ['description' => 'add users']);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok'] ?? false, 'MCP migration_create must report ok=true: ' . json_encode($result));
        $this->assertArrayHasKey('created', $result, 'legacy {created: filename} contract preserved');
        $this->assertArrayHasKey('resolution', $result, 'ADR-0063 envelope resolution surfaced');

        $resolution = $result['resolution'];
        $this->assertIsArray($resolution);

        // edit_hints[] — each hint is {file,line,label}, line > 0.
        $this->assertArrayHasKey('edit_hints', $resolution);
        $this->assertNotEmpty($resolution['edit_hints'], 'migration template must bake at least one tina4:edit marker');
        foreach ($resolution['edit_hints'] as $hint) {
            $this->assertArrayHasKey('file', $hint);
            $this->assertArrayHasKey('line', $hint);
            $this->assertArrayHasKey('label', $hint);
            $this->assertIsInt($hint['line']);
            $this->assertGreaterThan(0, $hint['line']);
        }

        // next[] — populated (never null / never absent), each entry a string.
        $this->assertArrayHasKey('next', $resolution);
        $this->assertIsArray($resolution['next']);
        $this->assertNotEmpty($resolution['next'], 'migration next[] must not be empty');
        foreach ($resolution['next'] as $step) {
            $this->assertIsString($step);
        }

        // resolution.file_path names the real file the writer landed on disk.
        $this->assertArrayHasKey('file_path', $resolution);
        $filePath = $cwd . '/' . $resolution['file_path'];
        $this->assertFileExists($filePath, 'the resolution.file_path must exist on disk after write mode');

        // The tina4:edit markers really ARE in the file at the hinted lines.
        $pattern = '~(?://|--|\{#)\s*tina4:edit\s+(.+?)(?:\s*#\})?\s*$~';
        foreach ($resolution['edit_hints'] as $hint) {
            $hintedPath = $cwd . '/' . $hint['file'];
            $this->assertFileExists($hintedPath, "edit_hint points at missing file {$hint['file']}");
            $lines = file($hintedPath, FILE_IGNORE_NEW_LINES);
            $this->assertNotFalse($lines);
            $line = $lines[$hint['line'] - 1] ?? '';
            $this->assertMatchesRegularExpression(
                $pattern,
                $line,
                "{$hint['file']}:{$hint['line']} — expected tina4:edit marker, got:\n{$line}"
            );
        }
    }

    /**
     * 2. Filename shape — the created filename matches
     *    /^\d{14}_[a-z0-9_]+\.sql$/. This is the fix: the old MCP tool
     *    wrote 000001_x.sql (sequential), the CLI writes YYYYMMDDHHMMSS_x.sql;
     *    two conventions in one project didn't sort together. Post-fix,
     *    MCP mirrors the CLI's timestamp convention.
     */
    public function testCreatedFilenameCarriesFourteenDigitTimestampPrefix(): void
    {
        $cwd = $this->freshProjectDir('shape');
        chdir($cwd);
        $server = $this->makeServerWithDevTools('shape');

        $result = $server->callTool('migration_create', ['description' => 'add orders']);

        $this->assertTrue($result['ok'] ?? false, 'ok=true expected: ' . json_encode($result));
        $created = (string) ($result['created'] ?? '');
        $this->assertNotSame('', $created, 'created filename must be present');

        // The fix: 14-digit timestamp (YYYYMMDDHHMMSS), not 6-digit sequential.
        $this->assertMatchesRegularExpression(
            '/^\d{14}_[a-z0-9_]+\.sql$/',
            $created,
            "created filename must match the CLI's timestamp shape, got: {$created}"
        );

        // And the anti-pattern — the OLD sequential prefix — is gone.
        $this->assertDoesNotMatchRegularExpression(
            '/^\d{6}_[a-z0-9_]+\.sql$/',
            $created,
            "created filename must NOT match the pre-3.13.121 6-digit sequential shape, got: {$created}"
        );

        // Real file on disk carries the same name.
        $this->assertFileExists($cwd . '/migrations/' . $created);
    }

    /**
     * 3. Envelope parity with CLI — the resolution the MCP tool returns is
     *    structurally identical to what `tina4php migrate:create <slug>
     *    --json --dry-run` produces for the same input. Same command/target,
     *    same edit_hints[] labels (sorted), same next[] byte-for-byte.
     *
     *    Also verifies the contract advertised by `commands --json` is
     *    generate_v1_1 v1.1 — the manifest is the machine-discoverable
     *    version that a downstream tool would key off.
     */
    public function testMcpEnvelopeMatchesCliEnvelopeForSameInput(): void
    {
        // MCP side — a real invocation into a fresh cwd.
        $mcpCwd = $this->freshProjectDir('parity-mcp');
        chdir($mcpCwd);
        $server = $this->makeServerWithDevTools('parity-mcp');
        $mcpResult = $server->callTool('migration_create', ['description' => 'add invoices']);
        $this->assertTrue($mcpResult['ok'] ?? false, 'MCP call must succeed: ' . json_encode($mcpResult));
        $mcpResolution = $mcpResult['resolution'];

        // CLI side — dry-run parity. Same slug the MCP tool would have
        // computed from "add invoices" ("add_invoices"). Dry-run so the
        // parity check doesn't need a second write cycle.
        $cliCwd = $this->freshProjectDir('parity-cli');
        $cli = $this->runCli(
            ['migrate:create', 'add_invoices', '--json', '--dry-run'],
            $cliCwd
        );
        $this->assertSame(0, $cli['exit'], "CLI stderr:\n{$cli['stderr']}");
        $cliEnvelope = json_decode($cli['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $cliResolution = $cliEnvelope['resolution'];

        // command/target parity — both sides speak the ADR-0063 verb.
        $this->assertSame('generate', $cliEnvelope['command']);
        $this->assertSame('migration', $cliEnvelope['target']);

        // edit_hints[] labels must match exactly (file paths embed a
        // timestamp and will differ per invocation, labels are stable).
        $mcpLabels = array_column($mcpResolution['edit_hints'] ?? [], 'label');
        $cliLabels = array_column($cliResolution['edit_hints'] ?? [], 'label');
        sort($mcpLabels);
        sort($cliLabels);
        $this->assertNotEmpty($mcpLabels, 'MCP must emit non-empty edit_hints');
        $this->assertSame($cliLabels, $mcpLabels, 'edit_hints labels must be byte-identical to CLI');

        // next[] must be byte-identical to CLI — the whole point of the
        // unification is that no consumer sees drift between the two.
        $this->assertSame(
            $cliResolution['next'],
            $mcpResolution['next'],
            'next[] must match CLI byte-for-byte'
        );

        // File-path SHAPE parity (modulo the timestamp that varies per call).
        $stripTs = static fn(string $s): string => preg_replace('/\d{14}_/', 'TS_', $s);
        $this->assertSame(
            $stripTs((string) $cliResolution['file_path']),
            $stripTs((string) $mcpResolution['file_path']),
            'file_path shape (modulo timestamp) must match CLI'
        );
        $this->assertSame(
            $stripTs((string) ($cliResolution['migration_path'] ?? '')),
            $stripTs((string) ($mcpResolution['migration_path'] ?? '')),
            'migration_path (down.sql) shape must match CLI'
        );

        // Contract discoverability — commands --json advertises v1.1
        // generate_v1_1. Downstream tools key off this.
        $mfstCwd = $this->freshProjectDir('parity-mfst');
        $mfst = $this->runCli(['commands', '--json'], $mfstCwd);
        $this->assertSame(0, $mfst['exit'], "commands --json stderr:\n{$mfst['stderr']}");
        $manifest = json_decode($mfst['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('resolution_contract', $manifest);
        $this->assertSame('1.1', $manifest['resolution_contract']['version']);
        $this->assertSame('generate_v1_1', $manifest['resolution_contract']['envelope']);
    }

    /**
     * 4. Duplicate-slug guard preserved — a second call for the same
     *    description returns {ok:false, error, existing:[...]} pointing at
     *    the file the first call created, instead of spawning a duplicate.
     *    Mirrors the Python master's create_migration(description) guard.
     */
    public function testDuplicateSlugGuardPointsAtExistingMigration(): void
    {
        $cwd = $this->freshProjectDir('dup');
        chdir($cwd);
        $server = $this->makeServerWithDevTools('dup');

        $first = $server->callTool('migration_create', ['description' => 'add customers']);
        $this->assertTrue($first['ok'] ?? false, 'first call must succeed: ' . json_encode($first));
        $firstCreated = (string) $first['created'];

        // Second call — same description. Must be refused with a helpful
        // pointer at the existing file, not silently create a second one.
        $second = $server->callTool('migration_create', ['description' => 'add customers']);
        $this->assertIsArray($second);
        $this->assertFalse($second['ok'] ?? true, 'second call must report ok=false');
        $this->assertArrayHasKey('error', $second);
        $this->assertStringContainsString(
            'already exists',
            (string) $second['error'],
            'duplicate-slug error must say "already exists"'
        );
        $this->assertArrayHasKey('existing', $second);
        $this->assertNotEmpty($second['existing']);
        $this->assertStringContainsString(
            $firstCreated,
            (string) $second['existing'][0],
            'existing[] must name the file the first call created'
        );

        // The .sql file count on disk stays at ONE primary migration
        // (plus its .down.sql sibling) — no duplicate landed.
        $primary = array_values(array_filter(
            glob("{$cwd}/migrations/*.sql") ?: [],
            static fn(string $p): bool => !str_ends_with($p, '.down.sql')
        ));
        $this->assertCount(1, $primary, 'duplicate must not spawn a second primary .sql');
    }

    /**
     * 5. No test co-emitted — migration_create keeps its "just a migration,
     *    no test" contract, even for a schema-aware create_X description
     *    that WOULD normally trigger a co-emitted *MigrationTest.php under
     *    `generate migration create_X`. The tests/ tree must not appear.
     */
    public function testMcpToolNeverCoemitsMigrationTest(): void
    {
        $cwd = $this->freshProjectDir('notest');
        chdir($cwd);
        $server = $this->makeServerWithDevTools('notest');

        // "create widgets" slugs to "create_widgets" — the shape that
        // triggers a co-emitted WidgetsMigrationTest.php under
        // `generate migration create_widgets`.
        $result = $server->callTool('migration_create', ['description' => 'create widgets']);
        $this->assertTrue($result['ok'] ?? false, 'ok=true expected: ' . json_encode($result));

        $this->assertFalse(
            is_dir($cwd . '/tests'),
            'MCP migration_create must never create a tests/ tree'
        );
        $this->assertEmpty(
            glob($cwd . '/tests/*MigrationTest.php') ?: [],
            'MCP migration_create must never write a *MigrationTest.php'
        );

        // Sanity: the migration DID land — the "no tests" check above
        // must not silently pass on an equally quiet "no write happened".
        $this->assertNotEmpty(
            glob($cwd . '/migrations/*_create_widgets.sql') ?: [],
            'the migration itself must land on disk (envelope success is not enough)'
        );
    }

    /**
     * 6. Mutation gate — stash the delegation by rewriting the MCP
     *    `migration_create` block back to its pre-3.13.121 inline
     *    "-- $description\n" + sequential-prefix template, rerun the
     *    positive envelope assertion, and confirm it FAILS. Restore the
     *    file on the way out so no permanent change leaks.
     *
     *    Proves the positive test in #1 is a real gate: on the OLD MCP
     *    implementation it must NOT pass (no envelope, no edit_hints, no
     *    next), so a regression that reintroduces the inline write would
     *    be caught by the positive test.
     */
    public function testMutationGateStrippingDelegationBreaksEnvelope(): void
    {
        $mcpPath = realpath(__DIR__ . '/../Tina4/Bootstrap/MCP.php');
        $this->assertNotFalse($mcpPath, 'MCP.php not found for mutation gate');
        $original = file_get_contents($mcpPath);
        $this->assertNotFalse($original, 'could not read MCP.php for mutation gate');

        // The pre-3.13.121 inline block — sequential prefix + bare
        // `-- $description\n` write + `{created: filename}` return with
        // NO envelope, NO edit_hints, NO next.
        $legacyBlock = <<<'PHP'
        $server->registerTool('migration_create', function (string $description) {
            try {
                $migrationPath = 'migrations';
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($description)), '_');
                if (!is_dir($migrationPath)) {
                    mkdir($migrationPath, 0755, true);
                }
                $existing = glob($migrationPath . '/*.sql') ?: [];
                $nextId = str_pad((string) (count($existing) + 1), 6, '0', STR_PAD_LEFT);
                $safeName = $slug !== '' ? $slug : 'migration';
                $filename = "{$nextId}_{$safeName}.sql";
                file_put_contents($migrationPath . '/' . $filename, "-- $description\n");
                return ['created' => $filename];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }, 'Create a new migration file');
PHP;

        // Regex-match the current delegating block so the swap is
        // deterministic regardless of whitespace tweaks. The block starts
        // at the `registerTool('migration_create', ...)` call and ends at
        // the closing `}, 'Create a new migration file');` line.
        $pattern = "/        \\\$server->registerTool\\('migration_create'.+?}, 'Create a new migration file'\\);/s";
        $mutant = preg_replace($pattern, $legacyBlock, $original, 1, $count);
        $this->assertSame(1, $count, 'mutation gate: could not find the delegating migration_create block');
        $this->assertNotSame($original, $mutant, 'mutation gate: swap produced no change');

        try {
            $this->assertNotFalse(
                file_put_contents($mcpPath, $mutant),
                'mutation gate: could not write the mutated MCP.php'
            );

            // Force a subprocess so the mutated MCP.php is actually loaded
            // (this process already has the original class cached, and
            // PHP has no unload). A tiny throwaway script invokes the MCP
            // tool the same way the positive test would, then prints the
            // result as JSON. We assert on that.
            $probeScript = $this->freshProjectDir('mutant-probe') . '/probe.php';
            file_put_contents($probeScript, <<<PHP
<?php
require '{$this->composerAutoload()}';
\$server = new \\Tina4\\McpServer('/mutant-probe', 'Mutant Probe');
\\Tina4\\McpDevTools::register(\$server);
\$result = \$server->callTool('migration_create', ['description' => 'add gate']);
echo json_encode(\$result);
PHP
            );
            $probeCwd = $this->freshProjectDir('mutant-run');

            // Run the probe directly (proc_open — the probe script IS the
            // command, no bin/tina4php involvement here).
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', $probeScript],
                $descriptors,
                $pipes,
                $probeCwd
            );
            $this->assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            $decoded = json_decode((string) $stdout, true);
            $this->assertIsArray($decoded, "mutant probe stdout was not JSON:\n{$stdout}\nstderr:\n{$stderr}");

            // The old inline path returns {created: filename} with NO
            // 'resolution' key AND the filename carries the OLD 6-digit
            // sequential prefix. Positive test #1 would FAIL on this.
            $hasEnvelope = isset($decoded['resolution'])
                && is_array($decoded['resolution'])
                && !empty($decoded['resolution']['edit_hints'] ?? [])
                && !empty($decoded['resolution']['next'] ?? []);
            $this->assertFalse(
                $hasEnvelope,
                "mutation gate: mutated migration_create must NOT emit the envelope."
                . " Decoded was: " . json_encode($decoded)
            );

            // Extra proof: mutated filename matches the OLD sequential shape.
            if (isset($decoded['created'])) {
                $this->assertMatchesRegularExpression(
                    '/^\d{6}_/',
                    (string) $decoded['created'],
                    'mutation gate: mutated created filename must carry the pre-3.13.121 6-digit prefix'
                );
            }
        } finally {
            // Restore no matter what — a test that leaves MCP.php mutated
            // would poison every later test in the suite.
            $this->assertNotFalse(
                file_put_contents($mcpPath, $original),
                'mutation gate: could not restore MCP.php — MANUAL FIX REQUIRED'
            );
        }
    }

    /**
     * Resolve the vendor/autoload.php path so the mutation-probe script
     * can bootstrap the framework classes.
     */
    private function composerAutoload(): string
    {
        $path = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertNotFalse($path, 'vendor/autoload.php not found — run composer install first');
        return $path;
    }
}
