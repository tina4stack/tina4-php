<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Medium security finding F4 — the built-in GraphQL POST route must not accept a
 * JSON body sent as text/plain. A cross-site HTML form can POST text/plain
 * without a CORS preflight, so accepting it lets a forged form drive a mutation.
 * Requiring an application/json content-type forces the preflight (and its
 * same-origin/allow-list check) for any cross-site caller. Case names match the
 * sibling regression in tina4-ruby/spec/graphql_csrf_spec.rb.
 *
 * Real Router::dispatch through the registered route. No mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\GraphQL;
use Tina4\Request;
use Tina4\Response;

class GraphQLCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        \Tina4\Router::clear();
        $gql = new GraphQL();
        $gql->addQuery('ping', [], 'String', fn ($root, $args, $ctx) => 'pong');
        $gql->addMutation('bump', [], 'String', fn ($root, $args, $ctx) => 'bumped');
        $gql->register('/graphql');
    }

    protected function tearDown(): void
    {
        \Tina4\Router::clear();
    }

    private function dispatchPost(string $contentType): Response
    {
        $body = json_encode(['query' => 'mutation { bump }']);
        $request = new Request('POST', '/graphql', null, $body, ['content-type' => $contentType]);
        return \Tina4\Router::dispatch($request, new Response());
    }

    public function testTextPlainPostIsRejected(): void
    {
        $result = $this->dispatchPost('text/plain');
        $this->assertSame(
            415,
            $result->getStatusCode(),
            'a JSON body sent as text/plain was accepted (CSRF)'
        );
    }

    public function testApplicationJsonPostIsAccepted(): void
    {
        // Positive twin: a same-origin XHR / server client sets application/json.
        $result = $this->dispatchPost('application/json');
        $this->assertNotSame(415, $result->getStatusCode());
        $this->assertSame(200, $result->getStatusCode());
    }
}
