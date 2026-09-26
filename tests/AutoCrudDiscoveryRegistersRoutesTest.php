<?php

/**
 * Tina4 — AutoCrud routes register at DISCOVERY, not on the first new Model().
 *
 * Bug 5 (book review). `$autoCrud = true` registered no routes until the first
 * `new Model()` because registration lived only in ORM::__construct(). After
 * ModelDiscovery loaded the class, GET /api/<table> 404'd until an instance
 * existed. ModelDiscovery now calls ORM::registerAutoCrudForClass() at
 * discovery time (reading class defaults by reflection, no instance).
 *
 * This test discovers a model file and asserts the CRUD routes are registered
 * WITHOUT ever instantiating the model. Real filesystem + real SQLite.
 */

use PHPUnit\Framework\TestCase;
use Tina4\ModelDiscovery;
use Tina4\Router;
use Tina4\Database\SQLite3Adapter;

final class AutoCrudDiscoveryRegistersRoutesTest extends TestCase
{
    private string $dir = '';
    private ?SQLite3Adapter $db = null;

    protected function setUp(): void
    {
        Router::clear();
        ModelDiscovery::reset();
        $this->db = new SQLite3Adapter(':memory:');
        \Tina4\ORM::bindDatabase($this->db);
        $this->dir = sys_get_temp_dir() . '/tina4_disc_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        Router::clear();
        ModelDiscovery::reset();
        foreach (glob($this->dir . '/*.php') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $this->db?->close();
    }

    /**
     * Write a model file with a UNIQUE class + table (a fixed class name would
     * redeclare-fatal on the second test in the same PHP process).
     */
    private function writeModel(string $class, string $table, bool $autoCrud): void
    {
        $flag = $autoCrud ? "\n    public bool \$autoCrud = true;" : '';
        file_put_contents($this->dir . "/{$class}.php", <<<PHP
<?php
class {$class} extends \\Tina4\\ORM {
    public string \$tableName = '{$table}';
    public string \$primaryKey = 'id';{$flag}
}
PHP);
    }

    public function testDiscoveryRegistersCrudRoutesWithoutInstantiation(): void
    {
        $class = 'WidgetDisc_' . bin2hex(random_bytes(4));
        $table = 'widget_' . bin2hex(random_bytes(4));
        $this->db->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
        $this->writeModel($class, $table, true);

        self::assertNull(
            Router::match('GET', "/api/{$table}"),
            'precondition: the CRUD route must not exist before discovery'
        );

        $discovered = ModelDiscovery::scan($this->dir);
        self::assertNotEmpty($discovered, 'the model file should be discovered');

        // The CRUD routes answer with NO `new $class()` anywhere.
        self::assertNotNull(
            Router::match('GET', "/api/{$table}"),
            'GET /api/<table> must be registered at discovery time (bug 5)'
        );
        foreach ([['POST', "/api/{$table}"], ['PUT', "/api/{$table}/1"], ['DELETE', "/api/{$table}/1"]] as [$m, $p]) {
            self::assertNotNull(Router::match($m, $p), "{$m} {$p} must be registered at discovery");
        }
    }

    public function testNonAutoCrudModelRegistersNoRoutes(): void
    {
        // Control: a model WITHOUT autoCrud gets no routes from discovery.
        $class = 'PlainDisc_' . bin2hex(random_bytes(4));
        $table = 'plain_' . bin2hex(random_bytes(4));
        $this->writeModel($class, $table, false);

        ModelDiscovery::scan($this->dir);
        self::assertNull(
            Router::match('GET', "/api/{$table}"),
            'a model without autoCrud must not get CRUD routes'
        );
    }
}
