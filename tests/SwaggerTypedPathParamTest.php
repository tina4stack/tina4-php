<?php declare(strict_types=1);

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A typed route param must not leak its type token into the document.
 *
 * PHP's ROUTER supports typed params (`{id:int}` compiles to `\d+` and matches);
 * PHP's swagger GENERATOR did not. Measured 2026-08-06 against a real server:
 *
 *     runtime:  GET /api/typed/42 -> 200 {"id":42}
 *     spec:     path key '/api/typed/{id:int}', parameters: none
 *     validator: Path parameter 'id' for 'get' in '/api/typed/{id:int}'
 *                was not resolved
 *
 * So the template name became `id:int`, no parameter was declared for it, and
 * the document failed OpenAPI validation. convertPath already normalised the
 * catch-all form `{name:.*}` but nothing else, and extractPathParameters
 * matched `{name}` and `{name:.*}` only.
 *
 * Python and Ruby both strip the token and Python maps it to a real schema, so
 * the type set here mirrors Python's _PARAM_TYPE_SCHEMA exactly - the same
 * tokens PHP's own Router::compilePath accepts.
 *
 * No mocks: the real Swagger generator over the real Router.
 */
class SwaggerTypedPathParamTest extends TestCase
{
    protected function tearDown(): void
    {
        \Tina4\Router::clear();
    }

    private function specFor(string $pattern): array
    {
        \Tina4\Router::clear();
        \Tina4\Router::get($pattern, static fn($req, $res) => $res);
        return \Tina4\Swagger::generate();
    }

    private function pathKeys(array $spec): array
    {
        return array_keys($spec['paths'] ?? []);
    }

    public function testTheTypeTokenNeverReachesThePathKey(): void
    {
        $spec = $this->specFor('/api/typed/{id:int}');
        $keys = $this->pathKeys($spec);

        $this->assertContains(
            '/api/typed/{id}',
            $keys,
            'the type token leaked into the path key: ' . implode(', ', $keys)
        );
        foreach ($keys as $key) {
            $this->assertStringNotContainsString(
                ':',
                $key,
                "a path key still carries a type token: {$key}"
            );
        }
    }

    public function testATypedParamIsDeclaredWithItsMappedSchema(): void
    {
        $spec = $this->specFor('/api/typed/{id:int}');
        $params = $spec['paths']['/api/typed/{id}']['get']['parameters'] ?? [];
        $path = array_values(array_filter($params, static fn($p) => ($p['in'] ?? '') === 'path'));

        $this->assertCount(1, $path, 'no in:path parameter was declared for {id:int}');
        $this->assertSame('id', $path[0]['name'], 'the parameter name kept its type token');
        $this->assertTrue($path[0]['required'] ?? false);
        $this->assertSame(
            'integer',
            $path[0]['schema']['type'] ?? null,
            'an int param is documented as ' . ($path[0]['schema']['type'] ?? 'nothing')
        );
    }

    public function testEveryTemplateExpressionHasAParameter(): void
    {
        // The invariant as an OpenAPI validator states it, over every path in
        // the document rather than one route. This is the assertion that fails
        // when a NEW token type is added to the router and forgotten here.
        $spec = $this->specFor('/api/mixed/{id:int}/{slug:slug}/{rest:.*}');

        $unresolved = [];
        foreach (($spec['paths'] ?? []) as $key => $ops) {
            preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', (string)$key, $m);
            foreach ($ops as $verb => $op) {
                if (!is_array($op)) {
                    continue;
                }
                $declared = array_map(
                    static fn($p) => $p['name'] ?? '',
                    array_filter($op['parameters'] ?? [], static fn($p) => ($p['in'] ?? '') === 'path')
                );
                foreach ($m[1] as $want) {
                    if (!in_array($want, $declared, true)) {
                        $unresolved[] = strtoupper((string)$verb) . " {$key} -> {{$want}}";
                    }
                }
            }
        }

        $this->assertSame([], $unresolved, "template expressions with no path parameter:\n  "
            . implode("\n  ", $unresolved));
    }

    public function testAnUntypedParamIsStillAString(): void
    {
        // The control. A change that mapped everything to integer would satisfy
        // the typed cases above and break every ordinary route.
        $spec = $this->specFor('/api/plain/{id}');
        $params = $spec['paths']['/api/plain/{id}']['get']['parameters'] ?? [];
        $path = array_values(array_filter($params, static fn($p) => ($p['in'] ?? '') === 'path'));

        $this->assertCount(1, $path);
        $this->assertSame('string', $path[0]['schema']['type'] ?? null);
    }
}
