<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\GraphQL;

class GraphQLV3Test extends TestCase
{
    private function makeGql(): GraphQL
    {
        $gql = new GraphQL();

        $gql->addType('User', [
            'id' => 'ID',
            'name' => 'String',
            'email' => 'String',
            'age' => 'Int',
        ]);

        $gql->addType('Post', [
            'id' => 'ID',
            'title' => 'String',
            'body' => 'String',
            'authorId' => 'ID',
        ]);

        $users = [
            ['id' => '1', 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30],
            ['id' => '2', 'name' => 'Bob', 'email' => 'bob@test.com', 'age' => 25],
        ];

        $posts = [
            ['id' => '1', 'title' => 'Hello World', 'body' => 'First post', 'authorId' => '1'],
            ['id' => '2', 'title' => 'GraphQL Tips', 'body' => 'Second post', 'authorId' => '2'],
        ];

        $gql->addQuery('user', ['id' => 'ID!'], 'User', function ($root, $args, $ctx) use ($users) {
            foreach ($users as $u) {
                if ($u['id'] === $args['id']) {
                    return $u;
                }
            }
            return null;
        });

        $gql->addQuery('users', ['limit' => 'Int'], '[User]', function ($root, $args, $ctx) use ($users) {
            $limit = $args['limit'] ?? 10;
            return array_slice($users, 0, $limit);
        });

        $gql->addQuery('post', ['id' => 'ID!'], 'Post', function ($root, $args, $ctx) use ($posts) {
            foreach ($posts as $p) {
                if ($p['id'] === $args['id']) {
                    return $p;
                }
            }
            return null;
        });

        $gql->addMutation('createUser', ['name' => 'String!', 'email' => 'String!'], 'User',
            function ($root, $args, $ctx) {
                return ['id' => '3', 'name' => $args['name'], 'email' => $args['email'], 'age' => null];
            });

        $gql->addMutation('deleteUser', ['id' => 'ID!'], 'Boolean',
            function ($root, $args, $ctx) {
                return true;
            });

        return $gql;
    }

    // ── Type definition tests ───────────────────────────────────

    public function testAddType(): void
    {
        $gql = new GraphQL();
        $gql->addType('Widget', ['id' => 'ID', 'name' => 'String']);
        $types = $gql->getTypes();
        $this->assertArrayHasKey('Widget', $types);
        $this->assertEquals(['id' => 'ID', 'name' => 'String'], $types['Widget']);
    }

    public function testAddMultipleTypes(): void
    {
        $gql = new GraphQL();
        $gql->addType('A', ['x' => 'Int']);
        $gql->addType('B', ['y' => 'String']);
        $this->assertCount(2, $gql->getTypes());
    }

    public function testAddTypeChaining(): void
    {
        $gql = new GraphQL();
        $result = $gql->addType('T', ['id' => 'ID']);
        $this->assertSame($gql, $result);
    }

    // ── Query registration ──────────────────────────────────────

    public function testAddQuery(): void
    {
        $gql = new GraphQL();
        $gql->addQuery('hello', [], 'String', fn($r, $a, $c) => 'world');
        $this->assertArrayHasKey('hello', $gql->getQueries());
    }

    public function testAddQueryChaining(): void
    {
        $gql = new GraphQL();
        $result = $gql->addQuery('q', [], 'String', fn() => '');
        $this->assertSame($gql, $result);
    }

    // ── Mutation registration ───────────────────────────────────

    public function testAddMutation(): void
    {
        $gql = new GraphQL();
        $gql->addMutation('doIt', ['x' => 'Int'], 'Boolean', fn($r, $a, $c) => true);
        $this->assertArrayHasKey('doIt', $gql->getMutations());
    }

    public function testAddMutationChaining(): void
    {
        $gql = new GraphQL();
        $result = $gql->addMutation('m', [], 'Boolean', fn() => true);
        $this->assertSame($gql, $result);
    }

    // ── Simple queries ──────────────────────────────────────────

    public function testSimpleQuery(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ user(id: "1") { name email } }');

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('Alice', $result['data']['user']['name']);
        $this->assertEquals('alice@test.com', $result['data']['user']['email']);
    }

    public function testQueryNotFoundField(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ user(id: "999") { name } }');
        $this->assertNull($result['data']['user']);
    }

    public function testQueryListType(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ users { name } }');
        $this->assertIsArray($result['data']['users']);
        $this->assertCount(2, $result['data']['users']);
        $this->assertEquals('Alice', $result['data']['users'][0]['name']);
    }

    public function testQueryWithIntArgument(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ users(limit: 1) { name } }');
        $this->assertCount(1, $result['data']['users']);
    }

    public function testQueryAllFields(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ user(id: "1") { id name email age } }');
        $data = $result['data']['user'];
        $this->assertEquals('1', $data['id']);
        $this->assertEquals('Alice', $data['name']);
        $this->assertEquals('alice@test.com', $data['email']);
        $this->assertEquals(30, $data['age']);
    }

    // ── Named operations ────────────────────────────────────────

    public function testNamedQuery(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('query GetUser { user(id: "2") { name } }');
        $this->assertEquals('Bob', $result['data']['user']['name']);
    }

    // ── Mutations ───────────────────────────────────────────────

    public function testMutation(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('mutation { createUser(name: "Eve", email: "eve@test.com") { id name email } }');
        $this->assertEquals('3', $result['data']['createUser']['id']);
        $this->assertEquals('Eve', $result['data']['createUser']['name']);
    }

    public function testMutationBoolean(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('mutation { deleteUser(id: "1") }');
        $this->assertTrue($result['data']['deleteUser']);
    }

    // ── Variables ────────────────────────────────────────────────

    public function testVariables(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute(
            'query GetUser($userId: ID!) { user(id: $userId) { name } }',
            ['userId' => '2']
        );
        $this->assertEquals('Bob', $result['data']['user']['name']);
    }

    public function testVariableDefaults(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute(
            'query GetUser($userId: ID! = "1") { user(id: $userId) { name } }'
        );
        $this->assertEquals('Alice', $result['data']['user']['name']);
    }

    // ── Aliases ─────────────────────────────────────────────────

    public function testAliases(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ first: user(id: "1") { name } second: user(id: "2") { name } }');
        $this->assertEquals('Alice', $result['data']['first']['name']);
        $this->assertEquals('Bob', $result['data']['second']['name']);
    }

    // ── Nested fields ───────────────────────────────────────────

    public function testNestedFields(): void
    {
        $gql = new GraphQL();
        $gql->addType('Author', ['id' => 'ID', 'name' => 'String']);
        $gql->addType('Book', ['id' => 'ID', 'title' => 'String', 'author' => 'Author']);

        $gql->addQuery('book', ['id' => 'ID!'], 'Book', function ($root, $args, $ctx) {
            return [
                'id' => '1',
                'title' => 'GraphQL Guide',
                'author' => ['id' => '10', 'name' => 'Jane'],
            ];
        });

        $result = $gql->execute('{ book(id: "1") { title author { name } } }');
        $this->assertEquals('GraphQL Guide', $result['data']['book']['title']);
        $this->assertEquals('Jane', $result['data']['book']['author']['name']);
    }

    // ── Error handling ──────────────────────────────────────────

    public function testSyntaxError(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ user(id: "1" { name } }');
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testResolverException(): void
    {
        $gql = new GraphQL();
        $gql->addQuery('boom', [], 'String', function ($root, $args, $ctx) {
            throw new \RuntimeException('Kaboom!');
        });

        $result = $gql->execute('{ boom }');
        $this->assertNull($result['data']['boom']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('Kaboom!', $result['errors'][0]['message']);
    }

    public function testEmptyQuery(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('');
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testUnknownResolver(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute('{ unknown { name } }');
        $this->assertNull($result['data']['unknown']);
    }

    // ── Schema SDL ──────────────────────────────────────────────

    public function testSchemaSDL(): void
    {
        $gql = $this->makeGql();
        $sdl = $gql->schema();
        $this->assertStringContainsString('type User', $sdl);
        $this->assertStringContainsString('type Query', $sdl);
        $this->assertStringContainsString('type Mutation', $sdl);
        $this->assertStringContainsString('user(id: ID!): User', $sdl);
        $this->assertStringContainsString('createUser(', $sdl);
    }

    public function testSchemaSDLWithNoMutations(): void
    {
        $gql = new GraphQL();
        $gql->addQuery('hello', [], 'String', fn() => 'world');
        $sdl = $gql->schema();
        $this->assertStringContainsString('type Query', $sdl);
        $this->assertStringNotContainsString('type Mutation', $sdl);
    }

    // ── Directives ──────────────────────────────────────────────

    public function testSkipDirective(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute(
            'query($skip: Boolean!) { user(id: "1") { name email @skip(if: $skip) } }',
            ['skip' => true]
        );
        $this->assertArrayHasKey('name', $result['data']['user']);
        $this->assertArrayNotHasKey('email', $result['data']['user']);
    }

    public function testIncludeDirective(): void
    {
        $gql = $this->makeGql();
        $result = $gql->execute(
            'query($inc: Boolean!) { user(id: "1") { name email @include(if: $inc) } }',
            ['inc' => false]
        );
        $this->assertArrayHasKey('name', $result['data']['user']);
        $this->assertArrayNotHasKey('email', $result['data']['user']);
    }
}
