<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Router;

/**
 * The dispatch pipeline CONTRACT (feature 6, group B).
 *
 * The characterisation suite proves the extraction changed no behaviour. This
 * proves the extraction STAYS extracted. A refactor with no gate regrows: the
 * next person to inline a stage should get a red test, not a slightly worse
 * number in a report nobody reads.
 *
 * Every assertion is derived from the code or from `tina4 metrics`, never from
 * a hand-maintained copy of the answer - a list duplicated into a test drifts
 * from the list it is meant to guard.
 *
 * Twin of tina4-ruby/spec/dispatch_pipeline_spec.rb and
 * tina4-nodejs/test/dispatchPipeline.test.ts.
 */
class DispatchPipelineTest extends TestCase
{
    /** @return array<int, string> Every stage, all four groups. */
    private function allStages(): array
    {
        return array_merge(
            Router::PROLOGUE_STAGES,
            Router::REQUEST_STAGES,
            Router::ROUTE_STAGES,
            Router::RESPONSE_STAGES
        );
    }

    // ── The stage list is DATA ───────────────────────────────────

    public function testThePipelineDeclaresItsStagesInOrder(): void
    {
        $this->assertSame(['startNativeSession'], Router::PROLOGUE_STAGES);
        $this->assertSame(['trailingSlashRedirect', 'dispatchNoMatch'], Router::REQUEST_STAGES);
        $this->assertSame(
            ['enforceRouteAuth', 'runRouteMiddleware', 'invokeRouteHandler'],
            Router::ROUTE_STAGES,
            'ADR-0012 order: post-match globals -> auth gate -> the route\'s own middleware'
        );
        $this->assertSame(
            ['finaliseResponse', 'saveSessionAndSetCookie', 'stripHeadBody', 'logRequest'],
            Router::RESPONSE_STAGES
        );
    }

    /**
     * NEGATIVE: a name in the list with no method behind it, or a stage quietly
     * deleted, must fail here rather than at 3am on a real request.
     */
    public function testItHasNoUnnamedStage(): void
    {
        foreach ($this->allStages() as $stage) {
            $this->assertTrue(
                method_exists(Router::class, $stage),
                "stage {$stage} is listed but not defined"
            );
        }
    }

    /**
     * A stage is an internal step, not public API. If one leaks into the public
     * surface it becomes something callers depend on, and the list stops being
     * the only thing that decides ordering.
     */
    public function testItKeepsEveryStagePrivate(): void
    {
        foreach ($this->allStages() as $stage) {
            $method = new \ReflectionMethod(Router::class, $stage);
            $this->assertTrue($method->isPrivate(), "stage {$stage} is not private - stages are internals");
        }
    }

    /**
     * NEGATIVE: ordering lives in the lists, not in calls between stages.
     *
     * A stage calling another stage directly hides an edge the list does not
     * show - exactly the coupling the extraction removed.
     */
    public function testAStageDoesNotReachIntoAnotherStage(): void
    {
        $source = file_get_contents(__DIR__ . '/../Tina4/Router.php');
        // Strip comments: they NAME the stages when explaining the order, and
        // matching those would report an offence that does not exist.
        $code = preg_replace('!/\*.*?\*/!s', '', $source);
        $code = preg_replace('!//.*!', '', (string) $code);

        $offenders = [];
        foreach ($this->allStages() as $stage) {
            $body = $this->methodBody((string) $code, $stage);
            if ($body === null) {
                continue;
            }
            foreach ($this->allStages() as $other) {
                if ($other !== $stage && str_contains($body, "self::{$other}(")) {
                    $offenders[] = "{$stage} calls {$other}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ordering must live in the stage lists, not in calls between stages: '
            . implode(', ', $offenders)
        );
    }

    /** Extract one method's body from the (comment-stripped) source. */
    private function methodBody(string $code, string $name): ?string
    {
        $start = strpos($code, "function {$name}(");
        if ($start === false) {
            return null;
        }
        $next = strpos($code, 'private static function ', $start + 1);
        return substr($code, $start, $next === false ? null : $next - $start);
    }
}
