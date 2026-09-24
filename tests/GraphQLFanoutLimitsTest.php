<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Medium security finding F2 — GraphQL fan-out limits.
 *
 * The depth guard bounds NESTING but not WIDTH. This pins the two controls that
 * close the gap, at parity with tina4-python/tests/test_graphql_fanout_limits.py,
 * tina4-nodejs/test/graphqlFanoutLimits.test.ts and
 * tina4-ruby/spec/graphql_fanout_limits_spec.rb:
 *   - a total expanded-node (complexity) budget (TINA4_GRAPHQL_MAX_NODES) that
 *     rejects a fragment bomb and an alias explosion before any resolver runs;
 *   - a parser recursion bound so a deeply nested query fails with a clean error
 *     instead of exhausting the process.
 */

use PHPUnit\Framework\TestCase;
use Tina4\GraphQL;

class GraphQLFanoutLimitsTest extends TestCase
{
    private function makeGql(int $maxNodes = 100): GraphQL
    {
        $gql = new GraphQL();
        $gql->addQuery('ping', [], 'String', fn ($root, $args, $ctx) => 'pong');
        $gql->maxNodes = $maxNodes;
        return $gql;
    }

    private function errText(array $result): string
    {
        return implode(' ', array_map(static fn ($e) => $e['message'] ?? '', $result['errors'] ?? []));
    }

    public function testFragmentBombIsRejected(): void
    {
        $gql = $this->makeGql(100);
        $frags = "fragment f0 on Query { ping }\n";
        $prev = 'f0';
        for ($i = 1; $i < 8; $i++) {
            $frags .= "fragment f{$i} on Query { ...{$prev} ...{$prev} }\n";
            $prev = "f{$i}";
        }
        $result = $gql->execute($frags . '{ ...f7 }');
        $this->assertStringContainsStringIgnoringCase('complexity', $this->errText($result),
            'fragment bomb not bounded');
    }

    public function testAliasExplosionIsRejected(): void
    {
        $gql = $this->makeGql(100);
        $aliases = implode(' ', array_map(static fn ($i) => "a{$i}: ping", range(0, 199)));
        $result = $gql->execute("{ {$aliases} }");
        $this->assertStringContainsStringIgnoringCase('complexity', $this->errText($result),
            'alias explosion not bounded');
    }

    public function testDeeplyNestedQueryFailsGracefully(): void
    {
        putenv('TINA4_GRAPHQL_MAX_DEPTH=20');
        $_ENV['TINA4_GRAPHQL_MAX_DEPTH'] = '20';
        try {
            $gql = $this->makeGql(100000);
            $inner = 'x';
            for ($i = 0; $i < 3000; $i++) {
                $inner = "ping { {$inner} }";
            }
            $result = $gql->execute("{ {$inner} }");
            $this->assertStringContainsStringIgnoringCase('maximum depth', $this->errText($result),
                'deep nesting not bounded by the parser');
        } finally {
            putenv('TINA4_GRAPHQL_MAX_DEPTH');
            unset($_ENV['TINA4_GRAPHQL_MAX_DEPTH']);
        }
    }

    public function testOrdinaryQueryStillWorks(): void
    {
        $gql = $this->makeGql(100);
        $result = $gql->execute('{ ping }');
        $this->assertSame('pong', $result['data']['ping'] ?? null);
    }
}
