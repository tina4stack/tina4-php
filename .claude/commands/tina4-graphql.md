# Set Up Tina4 GraphQL Endpoint

Create a GraphQL API endpoint with schema, resolvers, and route integration.

## Instructions

1. Define your schema with types, queries, and mutations
2. Register resolvers
3. Create a route that handles GraphQL requests

## Schema & Resolvers (`src/app/GraphQLSchema.php`)

```php
<?php

use Tina4\GraphQL;
use Tina4\Schema;

$schema = new Schema();

// Define types
$schema->addType("User", [
    "id" => "ID!",
    "name" => "String!",
    "email" => "String!",
    "role" => "String",
]);

// Define queries
$schema->addQuery("user", "User", ["id" => "ID!"]);
$schema->addQuery("users", "[User]", ["limit" => "Int", "offset" => "Int"]);

// Define mutations
$schema->addMutation("createUser", "User", ["name" => "String!", "email" => "String!"]);

// Create engine and register resolvers
$gql = new GraphQL($schema);

$gql->resolver("user", function ($args, $context) {
    $user = new User();
    if ($user->load("id = ?", [$args["id"]])) {
        return $user->asArray();
    }
    return null;
});

$gql->resolver("users", function ($args, $context) {
    $limit = $args["limit"] ?? 20;
    $offset = $args["offset"] ?? 0;
    $user = new User();
    return $user->select("*", $limit, $offset)->asArray();
});

$gql->resolver("createUser", function ($args, $context) {
    $user = new User(["name" => $args["name"], "email" => $args["email"]]);
    $user->save();
    return $user->asArray();
});
```

## Route (`src/routes/graphql.php`)

```php
<?php

use Tina4\Router;

Router::post("/graphql", function ($request, $response) {
    global $gql;
    $result = $gql->executeJson($request->rawBody);
    return $response->json($result);
})->noAuth();
```

## Auto-Generate Schema from ORM

```php
<?php

use Tina4\Schema;

$schema = new Schema();
$schema->fromOrm(new Product());  // Auto-creates type, query, list query
```

## GraphQL Query Syntax Reference

```graphql
# Simple query
{ users { id name email } }

# Named query with variables
query GetUser($id: ID!) {
    user(id: $id) { id name email role }
}

# Mutation
mutation CreateUser($name: String!, $email: String!) {
    createUser(name: $name, email: $email) { id name }
}

# Aliases
{ admins: users(role: "admin") { name } guests: users(role: "guest") { name } }

# Fragments
fragment UserFields on User { id name email }
query { user(id: 1) { ...UserFields } }

# Directives
query ($showEmail: Boolean!) {
    user(id: 1) { name email @include(if: $showEmail) }
}
```

## Key Rules

- Put schema definition in `src/app/`, not in routes
- Resolvers receive `($args, $context)` — use context for auth info
- Use `executeJson()` for raw JSON string input, `execute()` for parsed arrays
- For protected GraphQL, remove `->noAuth()` and pass token payload as context
