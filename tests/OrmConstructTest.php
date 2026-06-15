<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/** Test-only ORM model fixture. */
class CtorWidget extends \Tina4\ORM
{
    public string $tableName = 'ctor_widget';
    public int $id = 0;
    public string $name = '';
    public bool $active = false;
}

/**
 * ORM constructor accepts an array (data-first), a JSON object string, or the
 * legacy data: named arg (one record), and rejects a list with a clear message.
 */
class OrmConstructTest extends TestCase
{
    public function testFromArrayDataFirst(): void
    {
        $w = new CtorWidget(['id' => 1, 'name' => 'alpha', 'active' => true]);
        $this->assertSame(1, $w->id);
        $this->assertSame('alpha', $w->name);
        $this->assertTrue($w->active);
    }

    public function testFromJsonObjectString(): void
    {
        $w = new CtorWidget('{"id":2,"name":"beta","active":false}');
        $this->assertSame(2, $w->id);
        $this->assertSame('beta', $w->name);
    }

    public function testDataNamedArgStillWorks(): void
    {
        $w = new CtorWidget(data: ['id' => 5, 'name' => 'eps']);
        $this->assertSame(5, $w->id);
        $this->assertSame('eps', $w->name);
    }

    public function testListRaisesClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one record');
        new CtorWidget(['a', 'b']);
    }

    public function testJsonArrayStringRaisesClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CtorWidget('[{"id":4}]');
    }

    public function testInvalidJsonStringRaises(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CtorWidget('not json');
    }
}
