<?php

/**
 * Parity tests for features added/changed in 3.10.99:
 * 1. ORM::toDict() with case parameter (snake/camel)
 * 2. ORM::$autoMap default is true
 * 3. Frond replace filter (dict and positional forms)
 * 4. App::background() registration
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;
use Tina4\ORM;
use Tina4\App;

/**
 * Minimal ORM subclass for toDict / autoMap testing.
 */
class ParityProduct extends ORM
{
    public string $tableName = 'parity_products';
    public string $primaryKey = 'id';
    public array $fieldMapping = [
        'productName' => 'product_name',
        'unitPrice' => 'unit_price',
    ];
}

/**
 * Bare ORM subclass — no properties overridden except table/pk.
 */
class ParityBare extends ORM
{
    public string $tableName = 'parity_bare';
    public string $primaryKey = 'id';
}

class Parity31099Test extends TestCase
{
    private Frond $engine;
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/tina4-parity-31099';
        if (!is_dir($this->templateDir)) {
            mkdir($this->templateDir, 0777, true);
        }
        $this->engine = new Frond($this->templateDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->templateDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) unlink($f);
        }
    }

    /* ═══════════ ORM::toDict case parameter ═══════════ */

    public function testToDictCaseSnake(): void
    {
        $model = new ParityProduct();
        $model->productName = 'Widget';
        $model->unitPrice = 9.99;

        $dict = $model->toDict(case: 'snake');

        $this->assertArrayHasKey('product_name', $dict);
        $this->assertArrayHasKey('unit_price', $dict);
        $this->assertSame('Widget', $dict['product_name']);
        $this->assertSame(9.99, $dict['unit_price']);
    }

    public function testToDictCaseCamel(): void
    {
        $model = new ParityProduct();
        $model->productName = 'Widget';
        $model->unitPrice = 9.99;

        // Default (camel) — keys should be camelCase property names
        $dict = $model->toDict();

        $this->assertArrayHasKey('productName', $dict);
        $this->assertArrayHasKey('unitPrice', $dict);
        $this->assertSame('Widget', $dict['productName']);
        $this->assertSame(9.99, $dict['unitPrice']);

        // Explicit camel gives the same result
        $dict2 = $model->toDict(case: 'camel');
        $this->assertSame($dict, $dict2);
    }

    /* ═══════════ ORM::$autoMap default ═══════════ */

    public function testAutoMapDefaultTrue(): void
    {
        $model = new ParityBare();
        $this->assertTrue($model->autoMap);
    }

    /* ═══════════ Frond replace filter ═══════════ */

    public function testReplaceFilterDict(): void
    {
        $result = $this->engine->renderString(
            '{{ val|replace({"T": " ", "-": "/"}) }}',
            ['val' => '2024-01-15T10:30:00']
        );
        $this->assertSame('2024/01/15 10:30:00', $result);
    }

    public function testReplaceFilterPositional(): void
    {
        $result = $this->engine->renderString(
            '{{ val|replace("hello", "world") }}',
            ['val' => 'say hello']
        );
        $this->assertSame('say world', $result);
    }

    /* ═══════════ App::background() ═══════════ */

    public function testBackgroundRegistration(): void
    {
        // Save the current error handler so we can restore it after App sets its own
        $prev = set_error_handler(fn() => false);
        restore_error_handler();

        $app = new App();
        $called = false;
        $cb = function () use (&$called) { $called = true; };

        $returned = $app->background($cb, 5.0);

        // background() returns $this for chaining
        $this->assertSame($app, $returned);

        // Verify callback is stored in the private tickCallbacks array
        $ref = new \ReflectionProperty(App::class, 'tickCallbacks');
        $ref->setAccessible(true);
        $ticks = $ref->getValue($app);

        $this->assertCount(1, $ticks);
        $this->assertSame($cb, $ticks[0]['callback']);
        $this->assertSame(5.0, $ticks[0]['interval']);

        // Restore the previous error handler to avoid PHPUnit "risky test" warning
        restore_error_handler();
    }
}
