<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Meta-tests: every code-producing generator co-emits a REAL, green test.
 *
 * For each generator we scaffold into a fresh temp project via the ACTUAL
 * `php bin/tina4php generate <what> <name>` command (a real subprocess through
 * the real CLI entry point), then RUN the co-emitted *Test.php file with real
 * phpunit in a real subprocess and assert it passes (exit 0). No mocks anywhere:
 * real generate, real phpunit run of the emitted file, real SQLite / Router +
 * dispatch / Middleware / ServiceRunner / file-backed Queue / Validator /
 * FakeData::seedOrm / Events / \Tina4\Migration underneath.
 *
 * This proves each co-emitted test is (a) actually written next to the code and
 * (b) GREEN on generation — a scaffolded test that fails on creation is bad DX.
 *
 * Mirrors the Python master's tests/test_cli_generate_coemits.py (PR #87).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CliGenerateCoemitsTest extends TestCase
{
    private static string $bin;
    private static string $phpunit;
    private static string $bootstrap;
    private string $origCwd;
    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::$phpunit = realpath(__DIR__ . '/../vendor/bin/phpunit');
        self::$bootstrap = realpath(__DIR__ . '/bootstrap.php');
    }

    protected function setUp(): void
    {
        $this->origCwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4-coemit-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
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
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Run `php bin/tina4php <args>` in the temp project; require success. */
    private function cli(string $args): void
    {
        $cmd = 'php ' . escapeshellarg(self::$bin) . ' ' . $args . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, "`tina4php {$args}` failed:\n" . implode("\n", $out));
    }

    /** Run the co-emitted test file with real phpunit; require it to pass. */
    private function runEmitted(string $relPath): void
    {
        $testFile = $this->tempDir . '/' . $relPath;
        $this->assertFileExists($testFile, "generator did not co-emit {$relPath}");
        $cmd = escapeshellarg(self::$phpunit)
            . ' --no-configuration --bootstrap ' . escapeshellarg(self::$bootstrap)
            . ' ' . escapeshellarg($testFile) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, "co-emitted {$relPath} did NOT pass on generation:\n" . implode("\n", $out));
    }

    /**
     * (id => [pre-generate commands[], generate command, co-emitted test file]).
     * Mirrors the Python master's CASES list.
     */
    public static function coemitCases(): array
    {
        return [
            'model'            => [[], 'generate model Product --fields "name:string,price:float"', 'tests/ProductModelTest.php'],
            'route_with_model' => [['generate model Product'], 'generate route products --model Product', 'tests/ProductsTest.php'],
            'route_public'     => [['generate model Widget'], 'generate route widgets --model Widget --public', 'tests/WidgetsTest.php'],
            'route_no_model'   => [[], 'generate route notes', 'tests/NotesTest.php'],
            'middleware'       => [[], 'generate middleware AuthLog', 'tests/AuthLogMiddlewareTest.php'],
            'service'          => [[], 'generate service Reaper --every 5m', 'tests/ReaperServiceTest.php'],
            'queue'            => [[], 'generate queue order-emails', 'tests/OrderEmailsQueueTest.php'],
            'validator'        => [[], 'generate validator CreateUser', 'tests/CreateUserValidatorTest.php'],
            'seeder'           => [['generate model Product'], 'generate seeder Product', 'tests/ProductSeederTest.php'],
            'websocket'        => [[], 'generate websocket chat', 'tests/WsChatTest.php'],
            'listener'         => [[], 'generate listener user.created', 'tests/UserCreatedListenerTest.php'],
            'auth'             => [[], 'generate auth', 'tests/AuthTest.php'],
            'migration'        => [[], 'generate migration create_widget --fields "name:string"', 'tests/WidgetMigrationTest.php'],
            'crud'             => [[], 'generate crud Trinket --fields "name:string,qty:int"', 'tests/TrinketsTest.php'],
        ];
    }

    #[DataProvider('coemitCases')]
    public function testGeneratorCoEmitsAGreenTest(array $preCommands, string $generateCommand, string $emitted): void
    {
        foreach ($preCommands as $pre) {
            $this->cli($pre);
        }
        $this->cli($generateCommand);
        $this->runEmitted($emitted);
    }

    /** The generated UP migration (PHP writes UP and DOWN as separate files). */
    private function upSql(): string
    {
        $ups = array_filter(
            glob($this->tempDir . '/migrations/*.sql') ?: [],
            static fn(string $f): bool => !str_ends_with($f, '.down.sql')
        );
        $this->assertNotEmpty($ups, 'no UP migration was generated');
        return (string)file_get_contents((string)reset($ups));
    }

    /**
     * Column names the generated ORM model declares, as the migration must
     * spell them. `$tableName` / `$primaryKey` are ORM configuration, not
     * columns, and Tina4's ORM maps a camelCase property ($createdAt) onto a
     * snake_case column (created_at) — so compare on the mapped name.
     */
    private function modelColumns(string $model): array
    {
        $src = (string)file_get_contents($this->tempDir . "/src/orm/{$model}.php");
        preg_match_all('/^\s{4}public \??\w+ \$(\w+)\s*=/m', $src, $m);
        $props = array_diff($m[1], ['tableName', 'primaryKey']);
        return array_values(array_map(
            static fn(string $p): string => strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $p)),
            $props
        ));
    }

    /**
     * Regression (php#186 / tina4-python#101): `generate model/crud` WITHOUT
     * --fields wrote a model declaring `name` while its migration created only
     * id + createdAt, so the first write died with "no column named name".
     * Every case in coemitCases() passes --fields explicitly, which is exactly
     * why the bug survived — these exercise the no-fields path.
     */
    public function testModelWithoutFieldsMigrationHasEveryDeclaredColumn(): void
    {
        $this->cli('generate model Todo');
        $declared = $this->modelColumns('Todo');
        $up = $this->upSql();
        $this->assertContains('name', $declared, 'model should declare the default name field');
        foreach ($declared as $field) {
            $this->assertStringContainsString(
                $field,
                $up,
                "model declares '{$field}' but the migration omits it:\n{$up}"
            );
        }
    }

    /** Same contract through `generate crud` — the path llms.txt recommends. */
    public function testCrudWithoutFieldsMigrationHasEveryDeclaredColumn(): void
    {
        $this->cli('generate crud Todo');
        $up = $this->upSql();
        foreach ($this->modelColumns('Todo') as $field) {
            $this->assertStringContainsString(
                $field,
                $up,
                "model declares '{$field}' but the migration omits it:\n{$up}"
            );
        }
    }

    /** Positive control: the explicit --fields path must not regress. */
    public function testExplicitFieldsStillAllReachTheMigration(): void
    {
        $this->cli('generate model Product --fields "title:string,price:float,in_stock:bool"');
        $up = $this->upSql();
        foreach (['title', 'price', 'in_stock'] as $field) {
            $this->assertStringContainsString($field, $up, "'{$field}' missing from migration:\n{$up}");
        }
    }

    /**
     * END-TO-END against REAL sqlite: run the generated DDL, then insert a row
     * using the model's OWN property names. This is precisely what used to 500.
     */
    public function testGeneratedSchemaAcceptsARowUsingTheModelsFields(): void
    {
        $this->cli('generate model Todo');
        $cols = array_values(array_diff($this->modelColumns('Todo'), ['id', 'created_at']));
        $db = new \SQLite3(':memory:');
        try {
            $this->assertTrue($db->exec($this->upSql()), 'generated DDL did not execute');
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare('INSERT INTO todo (' . implode(', ', $cols) . ") VALUES ({$placeholders})");
            foreach ($cols as $i => $_) {
                $stmt->bindValue($i + 1, 'x', SQLITE3_TEXT);
            }
            $this->assertNotFalse($stmt->execute(), 'insert using the model\'s own fields failed');
            $this->assertSame(1, (int)$db->querySingle('SELECT COUNT(*) FROM todo'));
        } finally {
            $db->close();
        }
    }

    /**
     * Lock-in: the generated create/update routes must check save() before
     * serialising. PHP already did this (unlike Python/Ruby/Node, which had to
     * be fixed) — this pins it so the guard cannot be dropped later.
     */
    public function testGeneratedRoutesCheckSaveBeforeSerialising(): void
    {
        $this->cli('generate crud Todo');
        $route = (string)file_get_contents($this->tempDir . '/src/routes/todos.php');
        $this->assertStringContainsString('if (!$item->save())', $route);
        $this->assertLessThan(
            strpos($route, '$item->toDict(), 201'),
            strpos($route, 'if (!$item->save())'),
            'the guard must come before the success serialisation'
        );
    }

    /**
     * form / view scaffold Frond templates (no PHP logic), so they are exempt
     * from co-emitting a test — assert they produce a .twig and NO test file.
     */
    public function testFormAndViewAreTemplateOnlyAndExempt(): void
    {
        $this->cli('generate form Product --fields "name:string"');
        $this->cli('generate view Product --fields "name:string"');
        $this->assertFileExists($this->tempDir . '/src/templates/forms/product.twig');
        $this->assertFileExists($this->tempDir . '/src/templates/pages/products.twig');

        // No test files emitted for pure-template generators.
        $emitted = glob($this->tempDir . '/tests/*Test.php') ?: [];
        $this->assertSame([], $emitted, 'template-only generators should emit no test');
    }
}
