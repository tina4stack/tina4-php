<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\GraphQL;

/**
 * Verify GraphQL::resolve() decorator-style API shipped in 3.13.1.
 *
 * Chapter 22 of the PHP docs teaches:
 *
 *     GraphQL::resolve("Query", "products", function ($root, $args) { ... });
 *
 * Until 3.13.1 this static method didn't exist — every example crashed
 * with "Call to undefined method Tina4\\GraphQL::resolve". Ship the
 * decorator + class-level registry so the docs match code.
 *
 * Parity with Python tina4_python.graphql.GraphQL.resolve (3.13.0).
 */
final class ParityGraphQLResolveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GraphQL::_clearClassResolvers();
    }

    protected function tearDown(): void
    {
        GraphQL::_clearClassResolvers();
        parent::tearDown();
    }

    /**
     * Consolidated parity-rename guard: the decorator-style static surface
     * the docs teach must exist by these exact names. One existence loop
     * replaces the per-method method_exists smoke tests; the real behaviour
     * of each is exercised by the executing tests below.
     */
    public function testDecoratorSurfaceContract(): void
    {
        foreach (['resolve', 'setDefault', '_clearClassResolvers'] as $method) {
            $this->assertTrue(
                method_exists(GraphQL::class, $method),
                "GraphQL::{$method}() is part of the documented decorator API"
            );
        }
    }

    public function testRegisterQueryBeforeInstantiation(): void
    {
        GraphQL::resolve("Query", "hello", fn($root, $args) => "world");

        // New instance drains the class-level registry into its schema
        $gql = new GraphQL();
        $result = $gql->execute('{ hello }');
        $this->assertSame("world", $result['data']['hello'] ?? null);
        $this->assertArrayNotHasKey('errors', $result);
    }

    public function testRegisterMutationActuallyExecutes(): void
    {
        GraphQL::resolve("Mutation", "createWidget", function ($root, $args) {
            return ['id' => 1, 'name' => $args['name'] ?? 'unknown'];
        });

        $gql = new GraphQL();
        $gql->addType('Widget', ['id' => 'Int', 'name' => 'String']);

        // Run the mutation for real and assert the resolved payload, not the
        // presence of a callable in a private property.
        $result = $gql->execute('mutation { createWidget(name: "Gizmo") { id name } }');
        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame(1, $result['data']['createWidget']['id'] ?? null);
        $this->assertSame('Gizmo', $result['data']['createWidget']['name'] ?? null);
    }

    public function testFieldResolverOnObjectType(): void
    {
        GraphQL::resolve("Product", "reviews", function ($product, $args) {
            return [['id' => 1, 'rating' => 5]];
        });

        $gql = new GraphQL();
        $resolver = $gql->getFieldResolver("Product", "reviews");
        $this->assertIsCallable($resolver);
        $result = $resolver(['id' => 42], []);
        $this->assertSame(5, $result[0]['rating']);
    }

    public function testPostInstantiationRegistrationViaDefaultExecutes(): void
    {
        $gql = new GraphQL();
        GraphQL::setDefault($gql);

        // Register AFTER instantiation — should land in $gql immediately and
        // be queryable without re-instantiating.
        GraphQL::resolve("Query", "lateBound", fn($root, $args) => "registered after init");

        $result = $gql->execute('{ lateBound }');
        $this->assertSame('registered after init', $result['data']['lateBound'] ?? null);
        $this->assertArrayNotHasKey('errors', $result);
    }

    public function testMultipleResolversAccumulateAndResolve(): void
    {
        GraphQL::resolve("Query", "first", fn() => "a");
        GraphQL::resolve("Query", "second", fn() => "b");
        GraphQL::resolve("Mutation", "third", fn() => "c");

        $gql = new GraphQL();

        // Both queries resolve in one document — proves accumulation lands in
        // the live schema, not just a private registry.
        $q = $gql->execute('{ first second }');
        $this->assertArrayNotHasKey('errors', $q);
        $this->assertSame('a', $q['data']['first'] ?? null);
        $this->assertSame('b', $q['data']['second'] ?? null);

        // The mutation resolves too.
        $m = $gql->execute('mutation { third }');
        $this->assertArrayNotHasKey('errors', $m);
        $this->assertSame('c', $m['data']['third'] ?? null);
    }
}
