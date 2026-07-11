<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * #59 — stacked Swagger metadata must ALL survive on one route.
 *
 * Reported bug: combining several pieces of Swagger metadata on a route drops
 * all but the last. In Python this was stacked @summary/@description/@tags
 * decorators; Python was already correct (each annotates the handler in place,
 * so every stacked decorator's metadata survives). PHP is the DRIFT: the
 * fluent Router::swagger() OVERWROTE the whole meta array on each call, so
 * chaining ->swagger(['summary'=>...])->swagger(['tags'=>...]) — the natural
 * PHP analog of stacking decorators — dropped the earlier sibling.
 *
 * These lock in that ALL of summary/description/tags survive on the generated
 * OpenAPI operation, whether declared in one swagger() call, across CHAINED
 * swagger() calls (order-independent), or via a docblock. The chained cases are
 * the regression guard: before the Router::swagger() merge fix they went red.
 *
 * The `tags` assertions deliberately use values that DIFFER from the route's
 * path-derived fallback tag (Swagger::inferTag = first path segment), so a
 * dropped `tags` meta cannot be masked by the fallback coincidentally matching.
 *
 * Pure-logic: builds an OpenAPI spec from routes registered in-process — no DB,
 * no network, no doubles. Mirrors tina4-python/tests/test_swagger_stacked_decorators.py.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Swagger;
use Tina4\Router;

/**
 * A handler carrying stacked Swagger metadata in its docblock — the docblock
 * analog of Python's stacked decorators. All three must survive.
 *
 * @summary List widgets
 * @description Returns every widget in the catalogue.
 * @tags Inventory, Catalogue
 */
function swaggerStackedDocblockHandler($request, $response)
{
    return $response;
}

class SwaggerStackedDecoratorsTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // ── Chained swagger() calls — the regression guard (was broken) ──────────

    public function testStackedSwaggerCallsAllSurvive(): void
    {
        Router::get('/widgets', fn($req, $res) => $res)
            ->swagger(['summary' => 'List widgets'])
            ->swagger(['description' => 'Returns every widget in the catalogue.'])
            ->swagger(['tags' => ['Inventory', 'Catalogue']]);

        $op = Swagger::generate()['paths']['/widgets']['get'];
        $this->assertSame('List widgets', $op['summary']);
        $this->assertSame('Returns every widget in the catalogue.', $op['description']);
        $this->assertSame(['Inventory', 'Catalogue'], $op['tags']);
    }

    public function testStackedSwaggerCallsOrderIndependent(): void
    {
        // Swap the order — no piece wins by being last, none is dropped.
        Router::post('/orders', fn($req, $res) => $res)
            ->swagger(['tags' => ['Sales']])
            ->swagger(['summary' => 'Create order'])
            ->swagger(['description' => 'Creates a new order.']);

        $op = Swagger::generate()['paths']['/orders']['post'];
        $this->assertSame('Create order', $op['summary']);
        $this->assertSame('Creates a new order.', $op['description']);
        $this->assertSame(['Sales'], $op['tags']);
    }

    /**
     * A later swagger() call may still OVERRIDE the same key — merge is
     * last-write-wins per key, never a wholesale replace that drops siblings.
     * `tags` here (Inventory) differs from the path fallback (things), so a
     * dropped sibling would surface as the fallback and fail the assertion.
     */
    public function testChainedSameKeyOverridesButKeepsSiblings(): void
    {
        Router::get('/things', fn($req, $res) => $res)
            ->swagger(['summary' => 'Old summary', 'tags' => ['Inventory']])
            ->swagger(['summary' => 'New summary']);

        $op = Swagger::generate()['paths']['/things']['get'];
        $this->assertSame('New summary', $op['summary'], 'same-key later call overrides');
        $this->assertSame(['Inventory'], $op['tags'], 'sibling key from the earlier call survives');
    }

    // ── One swagger() call with all keys — baseline (always worked) ──────────

    public function testSingleSwaggerCallWithAllKeysSurvive(): void
    {
        Router::get('/items', fn($req, $res) => $res)
            ->swagger([
                'summary' => 'List items',
                'description' => 'All items.',
                'tags' => ['Catalogue'],
            ]);

        $op = Swagger::generate()['paths']['/items']['get'];
        $this->assertSame('List items', $op['summary']);
        $this->assertSame('All items.', $op['description']);
        $this->assertSame(['Catalogue'], $op['tags']);
    }

    // ── Docblock annotations — all three survive ─────────────────────────────

    public function testDocblockAnnotationsAllSurvive(): void
    {
        Router::get('/catalogue', 'swaggerStackedDocblockHandler');

        $op = Swagger::generate()['paths']['/catalogue']['get'];
        $this->assertSame('List widgets', $op['summary']);
        $this->assertSame('Returns every widget in the catalogue.', $op['description']);
        $this->assertSame(['Inventory', 'Catalogue'], $op['tags']);
    }
}
