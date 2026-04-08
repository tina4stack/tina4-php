<?php

use PHPUnit\Framework\TestCase;

class AutoCrudFlagTest extends TestCase
{
    public function testAutoCrudFlagDefaultsFalse(): void
    {
        // Create a test model without autoCrud
        $model = new class extends \Tina4\ORM {
            public string $tableName = 'test_no_crud';
            public string $primaryKey = 'id';
        };
        $this->assertFalse($model->autoCrud);
    }

    public function testAutoCrudFlagCanBeSetTrue(): void
    {
        $model = new class extends \Tina4\ORM {
            public string $tableName = 'test_crud';
            public string $primaryKey = 'id';
            public bool $autoCrud = true;
        };
        $this->assertTrue($model->autoCrud);
    }
}
