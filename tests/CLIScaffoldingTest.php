<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CLI Scaffolding — tests for the generator helper functions and
 * the generate model/route/migration/middleware commands.
 *
 * The generator functions are defined in bin/tina4php as top-level functions.
 * We require the file once (with $argv set to avoid side effects),
 * then test each generator by pointing it at a temp directory.
 */

use PHPUnit\Framework\TestCase;

class CLIScaffoldingTest extends TestCase
{
    private string $origCwd;
    private string $tempDir;

    /**
     * The field type map from the CLI script — defined here because
     * $FIELD_TYPE_MAP is a local variable in bin/tina4php, not global.
     */
    private static array $fieldTypeMap = [
        'string'   => ['php' => 'string',  'default' => "''",    'sql' => 'VARCHAR(255)', 'nullable' => false],
        'str'      => ['php' => 'string',  'default' => "''",    'sql' => 'VARCHAR(255)', 'nullable' => false],
        'int'      => ['php' => 'int',     'default' => 'null',  'sql' => 'INTEGER',      'nullable' => true],
        'integer'  => ['php' => 'int',     'default' => 'null',  'sql' => 'INTEGER',      'nullable' => true],
        'float'    => ['php' => 'float',   'default' => 'null',  'sql' => 'DECIMAL(10,2)','nullable' => true],
        'numeric'  => ['php' => 'float',   'default' => 'null',  'sql' => 'DECIMAL(10,2)','nullable' => true],
        'decimal'  => ['php' => 'float',   'default' => 'null',  'sql' => 'DECIMAL(10,2)','nullable' => true],
        'bool'     => ['php' => 'bool',    'default' => 'false', 'sql' => 'TINYINT(1)',   'nullable' => false],
        'boolean'  => ['php' => 'bool',    'default' => 'false', 'sql' => 'TINYINT(1)',   'nullable' => false],
        'text'     => ['php' => 'string',  'default' => "''",    'sql' => 'TEXT',         'nullable' => false],
        'datetime' => ['php' => 'string',  'default' => 'null',  'sql' => 'DATETIME',    'nullable' => true],
        'blob'     => ['php' => 'string',  'default' => 'null',  'sql' => 'BLOB',        'nullable' => true],
    ];

    /**
     * Load the generator functions from bin/tina4php without executing the CLI.
     * Uses the PHP tokenizer to extract function definitions, avoiding conflicts
     * with PortConfigTest which also loads resolveHostPort separately.
     */
    public static function setUpBeforeClass(): void
    {
        if (function_exists('tableNameFromClass')) {
            return;
        }

        $binPath = __DIR__ . '/../bin/tina4php';
        $source = file_get_contents($binPath);
        $tokens = token_get_all($source);

        $functionNames = [
            'tableNameFromClass', 'parseFields', 'parseFlags',
            'generateModel', 'generateRoute', 'generateMigration',
            'generateMiddleware', 'generateTest', 'generateForm',
            'generateView', 'generateCrud', 'generateAuth',
        ];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            // Find function name
            $nameIdx = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nameIdx = $j;
                }
                break;
            }
            if ($nameIdx === null) continue;
            $fname = $tokens[$nameIdx][1];
            if (!in_array($fname, $functionNames, true)) continue;
            if (function_exists($fname)) continue;

            // Extract from 'function' keyword to matching closing brace
            $startLine = $tokens[$i][2];
            // Find opening brace
            $braceDepth = 0;
            $foundOpen = false;
            $endIdx = $i;
            for ($j = $i; $j < $count; $j++) {
                $t = $tokens[$j];
                $ch = is_array($t) ? $t[1] : $t;
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

            // Reconstruct function source from tokens
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
        $this->tempDir = sys_get_temp_dir() . '/tina4-cli-test-' . getmypid() . '-' . uniqid();
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
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ── tableNameFromClass ─────────────────────────────────────────

    public function testTableNameSimple(): void
    {
        $this->assertEquals('product', tableNameFromClass('Product'));
    }

    public function testTableNameCamelCase(): void
    {
        $this->assertEquals('product_category', tableNameFromClass('ProductCategory'));
    }

    public function testTableNameSingleWord(): void
    {
        $this->assertEquals('user', tableNameFromClass('User'));
    }

    public function testTableNameMultipleWords(): void
    {
        $this->assertEquals('order_line_item', tableNameFromClass('OrderLineItem'));
    }

    public function testTableNameAllCaps(): void
    {
        // "API" => "api" (heuristic)
        $result = tableNameFromClass('API');
        $this->assertEquals(strtolower($result), $result);
    }

    // ── parseFields ────────────────────────────────────────────────

    public function testParseFieldsSimple(): void
    {
        $result = parseFields('name:string,price:float');
        $this->assertCount(2, $result);
        $this->assertEquals(['name', 'string'], $result[0]);
        $this->assertEquals(['price', 'float'], $result[1]);
    }

    public function testParseFieldsWithSpaces(): void
    {
        $result = parseFields(' name : string , price : float ');
        $this->assertCount(2, $result);
        $this->assertEquals(['name', 'string'], $result[0]);
        $this->assertEquals(['price', 'float'], $result[1]);
    }

    public function testParseFieldsDefaultsToString(): void
    {
        $result = parseFields('name,age');
        $this->assertCount(2, $result);
        $this->assertEquals(['name', 'string'], $result[0]);
        $this->assertEquals(['age', 'string'], $result[1]);
    }

    public function testParseFieldsEmpty(): void
    {
        $result = parseFields('');
        $this->assertEmpty($result);
    }

    public function testParseFieldsWhitespaceOnly(): void
    {
        $result = parseFields('   ');
        $this->assertEmpty($result);
    }

    public function testParseFieldsAllTypes(): void
    {
        $result = parseFields('a:string,b:int,c:float,d:bool,e:text,f:datetime,g:blob');
        $this->assertCount(7, $result);
        $this->assertEquals('int', $result[1][1]);
        $this->assertEquals('bool', $result[3][1]);
        $this->assertEquals('datetime', $result[5][1]);
    }

    public function testParseFieldsSingleField(): void
    {
        $result = parseFields('name:string');
        $this->assertCount(1, $result);
        $this->assertEquals(['name', 'string'], $result[0]);
    }

    // ── parseFlags ─────────────────────────────────────────────────

    public function testParseFlagsKeyValue(): void
    {
        [$flags, $positional] = parseFlags(['--fields', 'name:string']);
        $this->assertEquals('name:string', $flags['fields']);
        $this->assertEmpty($positional);
    }

    public function testParseFlagsEqualsSyntax(): void
    {
        [$flags, $positional] = parseFlags(['--fields=name:string,price:float']);
        $this->assertEquals('name:string,price:float', $flags['fields']);
    }

    public function testParseFlagsBooleanFlag(): void
    {
        [$flags, $positional] = parseFlags(['--no-migration']);
        $this->assertTrue($flags['no-migration']);
    }

    public function testParseFlagsPositionalArgs(): void
    {
        [$flags, $positional] = parseFlags(['model', 'Product', '--fields', 'name:string']);
        $this->assertEquals('name:string', $flags['fields']);
        $this->assertEquals(['model', 'Product'], $positional);
    }

    public function testParseFlagsEmpty(): void
    {
        [$flags, $positional] = parseFlags([]);
        $this->assertEmpty($flags);
        $this->assertEmpty($positional);
    }

    public function testParseFlagsMultipleFlags(): void
    {
        [$flags, ] = parseFlags(['--fields', 'name:string', '--model', 'Product', '--verbose']);
        $this->assertEquals('name:string', $flags['fields']);
        $this->assertEquals('Product', $flags['model']);
        $this->assertTrue($flags['verbose']);
    }

    // ── generateModel ──────────────────────────────────────────────

    public function testGenerateModelCreatesFile(): void
    {
        ob_start();
        generateModel('Product', ['no-migration' => true], self::$fieldTypeMap);
        ob_end_clean();

        $this->assertFileExists('src/orm/Product.php');
        $content = file_get_contents('src/orm/Product.php');
        $this->assertStringContainsString('class Product', $content);
        $this->assertStringContainsString("tableName = 'product'", $content);
        $this->assertStringContainsString('primaryKey', $content);
    }

    public function testGenerateModelWithFields(): void
    {
        ob_start();
        generateModel('Invoice', ['fields' => 'amount:float,status:string', 'no-migration' => true], self::$fieldTypeMap);
        ob_end_clean();

        $content = file_get_contents('src/orm/Invoice.php');
        $this->assertStringContainsString('$amount', $content);
        $this->assertStringContainsString('$status', $content);
        $this->assertStringContainsString('float', $content);
    }

    public function testGenerateModelSkipsDuplicate(): void
    {
        ob_start();
        generateModel('Existing', ['no-migration' => true], self::$fieldTypeMap);
        $out1 = ob_get_clean();

        ob_start();
        generateModel('Existing', ['no-migration' => true], self::$fieldTypeMap);
        $out2 = ob_get_clean();

        $this->assertStringContainsString('already exists', $out2);
    }

    // ── generateRoute ──────────────────────────────────────────────

    public function testGenerateRouteCreatesFile(): void
    {
        ob_start();
        generateRoute('products', []);
        ob_end_clean();

        $this->assertFileExists('src/routes/products.php');
        $content = file_get_contents('src/routes/products.php');
        $this->assertStringContainsString('Router::get', $content);
        $this->assertStringContainsString('Router::post', $content);
        $this->assertStringContainsString('/products', $content);
    }

    public function testGenerateRouteWithModel(): void
    {
        ob_start();
        generateRoute('orders', ['model' => 'Order']);
        ob_end_clean();

        $content = file_get_contents('src/routes/orders.php');
        $this->assertStringContainsString('Order', $content);
        $this->assertStringContainsString('/orders', $content);
    }

    public function testGenerateRouteSkipsDuplicate(): void
    {
        ob_start();
        generateRoute('users', []);
        $out1 = ob_get_clean();

        ob_start();
        generateRoute('users', []);
        $out2 = ob_get_clean();

        $this->assertStringContainsString('already exists', $out2);
    }

    // ── generateMigration ──────────────────────────────────────────

    public function testGenerateMigrationCreatesFile(): void
    {
        ob_start();
        generateMigration('create_task', [], self::$fieldTypeMap);
        ob_end_clean();

        $files = glob('migrations/*.sql');
        $this->assertNotEmpty($files);

        // Find the main migration (not the .down.sql rollback)
        $mainFile = null;
        foreach ($files as $f) {
            if (!str_contains($f, '.down.sql')) {
                $mainFile = $f;
                break;
            }
        }
        $this->assertNotNull($mainFile, 'No main migration file found');
        $content = file_get_contents($mainFile);
        $this->assertStringContainsString('CREATE TABLE', $content);
    }

    public function testGenerateMigrationWithFields(): void
    {
        $fields = parseFields('title:string,done:bool');
        ob_start();
        generateMigration('create_todo', [], self::$fieldTypeMap, $fields, 'todo');
        ob_end_clean();

        $files = glob('migrations/*.sql');
        $this->assertNotEmpty($files);

        // Find the main migration (not the .down.sql rollback)
        $mainFile = null;
        foreach ($files as $f) {
            if (!str_contains($f, '.down.sql')) {
                $mainFile = $f;
                break;
            }
        }
        $this->assertNotNull($mainFile, 'No main migration file found');
        $content = file_get_contents($mainFile);
        $this->assertStringContainsString('title', $content);
    }

    // ── generateMiddleware ─────────────────────────────────────────

    public function testGenerateMiddlewareCreatesFile(): void
    {
        ob_start();
        generateMiddleware('AuthCheck', []);
        ob_end_clean();

        $this->assertFileExists('src/middleware/AuthCheck.php');
        $content = file_get_contents('src/middleware/AuthCheck.php');
        $this->assertStringContainsString('class AuthCheck', $content);
    }

    public function testGenerateMiddlewareSkipsDuplicate(): void
    {
        ob_start();
        generateMiddleware('DupeCheck', []);
        ob_end_clean();

        ob_start();
        generateMiddleware('DupeCheck', []);
        $out = ob_get_clean();

        $this->assertStringContainsString('already exists', $out);
    }

    // ── generateTest ───────────────────────────────────────────────

    public function testGenerateTestCreatesFile(): void
    {
        ob_start();
        generateTest('products', []);
        ob_end_clean();

        $this->assertFileExists('tests/ProductsTest.php');
        $content = file_get_contents('tests/ProductsTest.php');
        $this->assertStringContainsString('class ProductsTest', $content);
        $this->assertStringContainsString('TestCase', $content);
    }

    // ── Field type map ─────────────────────────────────────────────

    public function testFieldTypeMapHasAllTypes(): void
    {
        $expectedTypes = ['string', 'str', 'int', 'integer', 'float', 'numeric',
                          'decimal', 'bool', 'boolean', 'text', 'datetime', 'blob'];
        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey($type, self::$fieldTypeMap, "Missing field type: $type");
        }
    }

    public function testFieldTypeMapHasRequiredKeys(): void
    {
        foreach (self::$fieldTypeMap as $type => $info) {
            $this->assertArrayHasKey('php', $info, "Type '$type' missing 'php' key");
            $this->assertArrayHasKey('sql', $info, "Type '$type' missing 'sql' key");
            $this->assertArrayHasKey('default', $info, "Type '$type' missing 'default' key");
        }
    }
}
