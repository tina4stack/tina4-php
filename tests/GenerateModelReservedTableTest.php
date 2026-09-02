<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * `generate model` on a reserved-word class name (issue #123).
 *
 * The scaffolder no longer renames silently: it auto-pluralises a reserved-word
 * table (`Order` -> `orders`, the SAFE choice, because Tina4 interpolates table
 * names UNQUOTED) but says so out loud, and `--table-name` lets the developer
 * force their own name (owning the quoting in raw SQL if it is itself reserved).
 * No ORM quoting change -- identifier quoting is a global storage invariant, not
 * a local fix, so that footgun stays shut.
 *
 * NO mocks. `resolveTable` is a pure function whose REAL source is lifted out of
 * bin/tina4php with the tokenizer (same trick as CLIScaffoldingTest); the
 * end-to-end tests generate a REAL model file — once by calling the extracted
 * generateModel() into a temp cwd and reading it back, and once by shelling out
 * to REAL bin/tina4php as a subprocess (the does-it-run proof).
 *
 * Mirrors tina4-python/tests/test_gen_model_reserved_table.py.
 */

use PHPUnit\Framework\TestCase;

class GenerateModelReservedTableTest extends TestCase
{
    private string $origCwd;
    private string $tempDir;
    private static string $bin;

    /**
     * The field type map generateModel() needs to render property lines. Copied
     * from bin/tina4php's $FIELD_TYPE_MAP (a local, not a global) — only `string`
     * is exercised here (the default single `name` column).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $fieldTypeMap = [
        'string' => ['php' => 'string', 'default' => "''", 'sql' => 'VARCHAR(255)', 'nullable' => false],
    ];

    /**
     * Lift the REAL function source (and the two consts they read) out of
     * bin/tina4php without executing the CLI. Per-function / per-const guards
     * make this idempotent and safe to run alongside CLIScaffoldingTest, which
     * extracts an overlapping set the same way.
     */
    public static function setUpBeforeClass(): void
    {
        $bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse($bin, 'bin/tina4php not found');
        self::$bin = $bin;

        $source = file_get_contents($bin);

        // Consts resolveTable()/fieldsOrDefault() read — lifted verbatim so this
        // harness can never drift from the reserved-word list or default fields.
        if (!defined('SQL_RESERVED_TABLE_NAMES')
            && preg_match('/^const SQL_RESERVED_TABLE_NAMES\s*=\s*(\[[\s\S]*?\]);\s*$/m', $source, $rw)) {
            eval('define(\'SQL_RESERVED_TABLE_NAMES\', ' . $rw[1] . ');');
        }
        if (!defined('DEFAULT_FIELDS')
            && preg_match('/^const DEFAULT_FIELDS\s*=\s*(.+);$/m', $source, $df)) {
            eval('define(\'DEFAULT_FIELDS\', ' . $df[1] . ');');
        }

        // resolveTable is the entry point; the rest are its (and generateModel's)
        // transitive deps for the no-migration / no-test generate path.
        $functionNames = [
            'tableNameFromClass', 'pluralizeTable', 'toTableNameWithTransform',
            'resolveTable', 'parseFields', 'fieldsOrDefault', 'generateModel',
        ];

        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $nameIdx = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nameIdx = $j;
                }
                break;
            }
            if ($nameIdx === null) {
                continue;
            }
            $fname = $tokens[$nameIdx][1];
            if (!in_array($fname, $functionNames, true) || function_exists($fname)) {
                continue;
            }

            $braceDepth = 0;
            $foundOpen = false;
            $endIdx = $i;
            for ($j = $i; $j < $count; $j++) {
                $ch = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($ch === '{') {
                    $braceDepth++;
                    $foundOpen = true;
                } elseif ($ch === '}') {
                    $braceDepth--;
                    if ($foundOpen && $braceDepth === 0) {
                        $endIdx = $j;
                        break;
                    }
                }
            }
            $funcSource = '';
            for ($j = $i; $j <= $endIdx; $j++) {
                $funcSource .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
            eval($funcSource);
        }
    }

    protected function setUp(): void
    {
        $this->origCwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4-gen-reserved-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Call resolveTable() capturing whatever it echoes.
     *
     * @param array<string, mixed> $flags
     * @return array{0: string, 1: string} [table, printedOutput]
     */
    private function resolve(string $name, array $flags = [], bool $announce = false): array
    {
        ob_start();
        $table = resolveTable($name, $flags, $announce);
        return [$table, (string) ob_get_clean()];
    }

    // ── Pure resolver ──────────────────────────────────────────────────────

    public function testNonReservedStaysSingularAndSilent(): void
    {
        [$table, $out] = $this->resolve('Product', [], true);
        $this->assertSame('product', $table);
        $this->assertSame('', $out);
    }

    public function testReservedPluralisedWithALoudNote(): void
    {
        [$table, $out] = $this->resolve('Order', [], true);
        $this->assertSame('orders', $table);
        $this->assertStringContainsString('order', $out);
        $this->assertStringContainsString('reserved', $out);
        $this->assertStringContainsString('--table-name', $out);
    }

    public function testReservedIsSilentWhenNotAnnouncing(): void
    {
        // Composite / existing-table generators resolve the same name without
        // repeating the note.
        [$table, $out] = $this->resolve('Order', []);
        $this->assertSame('orders', $table);
        $this->assertSame('', $out);
    }

    public function testTableNameOverrideWinsVerbatim(): void
    {
        [$table, $out] = $this->resolve('Order', ['table-name' => 'customer_orders'], true);
        $this->assertSame('customer_orders', $table);
        $this->assertSame('', $out, 'a non-reserved override needs no warning');
    }

    public function testForcingAReservedOverrideWarnsButObeys(): void
    {
        [$table, $out] = $this->resolve('Order', ['table-name' => 'select'], true);
        $this->assertSame('select', $table);
        $this->assertStringContainsString('select', $out);
        $this->assertStringContainsString('reserved', $out);
        $this->assertStringContainsString('UNQUOTED', $out);
    }

    public function testBareTableNameFlagIsIgnored(): void
    {
        // `--table-name` with no value parses to boolean true; it must not become
        // the table. It falls through to the snake+pluralise, so `Order` -> `orders`
        // (and with announce=true it prints the ordinary reserved-word note — this
        // asserts only the return value, matching the Python master).
        [$table, ] = $this->resolve('Order', ['table-name' => true], true);
        $this->assertSame('orders', $table);
    }

    // ── End-to-end via the extracted generateModel() ───────────────────────

    public function testReservedClassGetsPluralTableAndANote(): void
    {
        chdir($this->tempDir);
        ob_start();
        generateModel('Order', ['no-migration' => true], self::$fieldTypeMap, false, false);
        $out = (string) ob_get_clean();

        $content = file_get_contents($this->tempDir . '/src/orm/Order.php');
        $this->assertStringContainsString("\$tableName = 'orders'", $content);
        $this->assertStringContainsString('reserved', $out);
    }

    public function testTableNameOverrideIsUsedVerbatim(): void
    {
        chdir($this->tempDir);
        ob_start();
        generateModel('Order', ['no-migration' => true, 'table-name' => 'my_orders'], self::$fieldTypeMap, false, false);
        ob_end_clean();

        $content = file_get_contents($this->tempDir . '/src/orm/Order.php');
        $this->assertStringContainsString("\$tableName = 'my_orders'", $content);
    }

    // ── End-to-end via REAL bin/tina4php subprocess (does-it-run) ───────────

    public function testRealCliGenerateModelReservedWritesPluralAndPrintsNote(): void
    {
        $result = $this->runCli(['generate', 'model', 'Order', '--no-migration'], $this->tempDir);
        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");

        $model = $this->tempDir . '/src/orm/Order.php';
        $this->assertFileExists($model);
        $this->assertStringContainsString("\$tableName = 'orders'", file_get_contents($model));
        // The note lands on the writer's STDOUT (bare form) — reserved + escape hatch.
        $this->assertStringContainsString('reserved', $result['stdout']);
        $this->assertStringContainsString('--table-name', $result['stdout']);
    }

    public function testRealCliGenerateModelHonoursTableNameOverride(): void
    {
        $result = $this->runCli(
            ['generate', 'model', 'Order', '--table-name', 'my_orders', '--no-migration'],
            $this->tempDir
        );
        $this->assertSame(0, $result['exit'], "stderr:\n{$result['stderr']}");

        $model = $this->tempDir . '/src/orm/Order.php';
        $this->assertFileExists($model);
        $this->assertStringContainsString("\$tableName = 'my_orders'", file_get_contents($model));
    }

    /**
     * Run REAL bin/tina4php as a subprocess in $cwd. display_errors are pinned
     * off so a host-side "Unable to load grpc.so" startup warning never leaks
     * onto the pipes (mirrors GenerateResolutionTest::runCli).
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
}
