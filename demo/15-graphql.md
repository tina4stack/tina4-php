# GraphQL

The `GraphQL` class is a zero-dependency GraphQL engine with a recursive-descent parser, schema builder, and query executor. It supports types, queries, mutations, variables, fragments, inline fragments, field aliases, and directives (`@skip`, `@include`).

## Setup

```php
use Tina4\GraphQL;

$gql = new GraphQL();
```

## Defining Types

```php
$gql->addType('User', [
    'id' => 'ID!',
    'name' => 'String!',
    'email' => 'String!',
    'role' => 'String',
]);

$gql->addType('Post', [
    'id' => 'ID!',
    'title' => 'String!',
    'body' => 'String',
    'author' => 'User',
    'createdAt' => 'String',
]);
```

## Adding Query Resolvers

```php
// Simple query
$gql->addQuery(
    name: 'user',
    args: ['id' => 'ID!'],
    returnType: 'User',
    resolver: function ($root, array $args, array $context) {
        // Fetch user from database
        return [
            'id' => $args['id'],
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
            'role' => 'admin',
        ];
    }
);

// List query
$gql->addQuery(
    name: 'users',
    args: ['limit' => 'Int', 'offset' => 'Int'],
    returnType: '[User]',
    resolver: function ($root, array $args, array $context) {
        $limit = $args['limit'] ?? 10;
        return [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
        ];
    }
);

$gql->addQuery(
    name: 'posts',
    args: ['authorId' => 'ID'],
    returnType: '[Post]',
    resolver: function ($root, array $args, array $context) {
        return [
            ['id' => 1, 'title' => 'Hello World', 'body' => 'First post', 'author' => ['name' => 'Alice']],
        ];
    }
);
```

## Adding Mutation Resolvers

```php
$gql->addMutation(
    name: 'createUser',
    args: ['name' => 'String!', 'email' => 'String!'],
    returnType: 'User',
    resolver: function ($root, array $args, array $context) {
        // Insert into database
        return [
            'id' => 42,
            'name' => $args['name'],
            'email' => $args['email'],
            'role' => 'user',
        ];
    }
);

$gql->addMutation(
    name: 'updateUser',
    args: ['id' => 'ID!', 'name' => 'String', 'email' => 'String'],
    returnType: 'User',
    resolver: function ($root, array $args, array $context) {
        return [
            'id' => $args['id'],
            'name' => $args['name'] ?? 'Unchanged',
            'email' => $args['email'] ?? 'unchanged@example.com',
        ];
    }
);
```

## Executing Queries

```php
// Simple query
$result = $gql->execute('{ user(id: 1) { name email } }');
// ['data' => ['user' => ['name' => 'Alice Johnson', 'email' => 'alice@example.com']]]

// Named query with variables
$result = $gql->execute(
    'query GetUser($userId: ID!) { user(id: $userId) { id name email role } }',
    variables: ['userId' => 42]
);

// Mutation
$result = $gql->execute('
    mutation {
        createUser(name: "Charlie", email: "charlie@example.com") {
            id
            name
            email
        }
    }
');
```

## Variables and Defaults

```php
$result = $gql->execute(
    'query ListUsers($limit: Int = 10, $offset: Int = 0) {
        users(limit: $limit, offset: $offset) {
            id
            name
        }
    }',
    variables: ['limit' => 5]
);
```

## Field Aliases

```php
$result = $gql->execute('
    {
        admin: user(id: 1) { name role }
        guest: user(id: 2) { name role }
    }
');
// ['data' => ['admin' => ['name' => '...', 'role' => '...'], 'guest' => [...]]]
```

## Fragments

```php
$result = $gql->execute('
    fragment UserFields on User {
        id
        name
        email
    }

    query {
        user(id: 1) {
            ...UserFields
            role
        }
    }
');
```

## Inline Fragments

```php
$result = $gql->execute('
    {
        user(id: 1) {
            name
            ... on User {
                email
                role
            }
        }
    }
');
```

## Directives

```php
// @skip directive
$result = $gql->execute(
    'query GetUser($skipEmail: Boolean!) {
        user(id: 1) {
            name
            email @skip(if: $skipEmail)
        }
    }',
    variables: ['skipEmail' => true]
);
// email field is excluded

// @include directive
$result = $gql->execute(
    'query GetUser($showRole: Boolean!) {
        user(id: 1) {
            name
            role @include(if: $showRole)
        }
    }',
    variables: ['showRole' => false]
);
```

## Schema Introspection (SDL)

```php
$sdl = $gql->schema();
echo $sdl;
// type User {
//   id: ID!
//   name: String!
//   email: String!
//   role: String
// }
//
// type Query {
//   user(id: ID!): User
//   users(limit: Int, offset: Int): [User]
// }
```

## Integrating with Routes

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::post('/graphql', function (Request $request, Response $response) use ($gql) {
    $body = $request->body;

    $query = $body['query'] ?? '';
    $variables = $body['variables'] ?? null;

    $result = $gql->execute($query, $variables);

    return $response->json($result);
});
```

## Error Handling

```php
$result = $gql->execute('{ invalid syntax');
// ['data' => null, 'errors' => [['message' => 'Expected ..., got ...']]]

// Resolver errors
$gql->addQuery('failing', [], 'String', function () {
    throw new \RuntimeException('Something went wrong');
});

$result = $gql->execute('{ failing }');
// ['data' => ['failing' => null], 'errors' => [['message' => 'Something went wrong', 'path' => ['failing']]]]
```

## Tips

- The GraphQL engine is recursive-descent — it parses the query string directly without external tools.
- Resolver functions receive `($root, $args, $context)` — use `$root` for nested field resolution.
- List types return arrays of items. Each item's sub-fields are resolved individually.
- Use `getTypes()`, `getQueries()`, and `getMutations()` for introspection.
- The `schema()` method generates SDL format — useful for documentation or client code generation.
- Combine with the Auth middleware for authenticated GraphQL endpoints.
