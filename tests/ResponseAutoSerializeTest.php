<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Response;

/**
 * Response auto-serialization: $response($model) / $response->json($model)
 * serialize an ORM model (toDict), a list of models, and a DatabaseResult
 * (toArray) without the caller calling ->toDict() by hand. Plain arrays /
 * strings must behave exactly as before.
 */
class ResponseAutoSerializeTest extends TestCase
{
    /** A duck-typed ORM model — exposes toDict(). */
    private function fakeModel(array $data): object
    {
        return new class($data) {
            public function __construct(private array $data) {}
            public function toDict(): array { return $this->data; }
        };
    }

    /** A duck-typed DatabaseResult — records property + toArray(). */
    private function fakeResult(array $rows): object
    {
        return new class($rows) {
            public array $records;
            public function __construct(array $rows) { $this->records = $rows; }
            public function toArray(): array { return $this->records; }
        };
    }

    public function testModelSerialized(): void
    {
        $r = (new Response())($this->fakeModel(['id' => 1, 'name' => 'alpha', 'active' => true]));
        $this->assertSame('application/json', $r->getContentType());
        $this->assertSame(['id' => 1, 'name' => 'alpha', 'active' => true], json_decode($r->getBody(), true));
    }

    public function testListOfModelsSerialized(): void
    {
        $r = (new Response())([$this->fakeModel(['id' => 1]), $this->fakeModel(['id' => 2])]);
        $this->assertSame([['id' => 1], ['id' => 2]], json_decode($r->getBody(), true));
    }

    public function testDatabaseResultSerialized(): void
    {
        $r = (new Response())($this->fakeResult([['id' => 1], ['id' => 2]]));
        $this->assertSame([['id' => 1], ['id' => 2]], json_decode($r->getBody(), true));
    }

    public function testJsonMethodSerializesModel(): void
    {
        $r = (new Response())->json($this->fakeModel(['id' => 9]));
        $this->assertSame('application/json', $r->getContentType());
        $this->assertSame(['id' => 9], json_decode($r->getBody(), true));
    }

    public function testPlainArrayUnchanged(): void
    {
        $r = (new Response())(['ok' => true]);
        $this->assertSame('application/json', $r->getContentType());
        $this->assertSame(['ok' => true], json_decode($r->getBody(), true));
    }

    public function testStringUnchanged(): void
    {
        $r = (new Response())('<h1>hi</h1>');
        $this->assertStringStartsWith('text/html', $r->getContentType());
        $this->assertSame('<h1>hi</h1>', $r->getBody());
    }
}
