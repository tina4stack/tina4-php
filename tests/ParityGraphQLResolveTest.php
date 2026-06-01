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

    public function testResolveStaticMethodExists(): void
    {
        $this->assertTrue(method_exists(GraphQL::class, 'resolve'));
    }

    public function testRegisterQueryBeforeInstantiation(): void
    {
        GraphQL::resolve("Query", "hello", fn($root, $args) => "world");

        // New instance drains the class-level registry into its schema
        $gql = new GraphQL();
        $result = $gql->execute('{ hello }');
        $this->assertSame("world", $result['data']['hello'] ?? null);
    }

    public function testRegisterMutation(): void
    {
        GraphQL::resolve("Mutation", "createWidget", function ($root, $args) {
            return ['id' => 1, 'name' => $args['name'] ?? 'unknown'];
        });

        $gql = new GraphQL();
        // The executor needs the type to be declared too; provide a stub
        $gql->addType('Widget', ['id' => 'Int', 'name' => 'String']);
        // We don't fully execute here — just verify registration landed
        // by inspecting via reflection.
        $reflection = new \ReflectionClass($gql);
        $mutationsProp = $reflection->getProperty('mutations');
        $mutationsProp->setAccessible(true);
        $mutations = $mutationsProp->getValue($gql);
        $this->assertArrayHasKey('createWidget', $mutations);
        $this->assertIsCallable($mutations['createWidget']['resolve']);
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

    public function testPostInstantiationRegistrationViaDefault(): void
    {
        $gql = new GraphQL();
        GraphQL::setDefault($gql);

        // Register AFTER instantiation — should land in $gql immediately
        GraphQL::resolve("Query", "lateBound", fn($root, $args) => "registered after init");

        $reflection = new \ReflectionClass($gql);
        $queriesProp = $reflection->getProperty('queries');
        $queriesProp->setAccessible(true);
        $queries = $queriesProp->getValue($gql);
        $this->assertArrayHasKey('lateBound', $queries);
    }

    public function testMultipleResolversAccumulate(): void
    {
        GraphQL::resolve("Query", "first", fn() => "a");
        GraphQL::resolve("Query", "second", fn() => "b");
        GraphQL::resolve("Mutation", "third", fn() => "c");

        $gql = new GraphQL();
        $reflection = new \ReflectionClass($gql);
        $queriesProp = $reflection->getProperty('queries');
        $queriesProp->setAccessible(true);
        $queries = $queriesProp->getValue($gql);
        $this->assertArrayHasKey('first', $queries);
        $this->assertArrayHasKey('second', $queries);

        $mutationsProp = $reflection->getProperty('mutations');
        $mutationsProp->setAccessible(true);
        $mutations = $mutationsProp->getValue($gql);
        $this->assertArrayHasKey('third', $mutations);
    }
}
