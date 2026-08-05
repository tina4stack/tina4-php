<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseResult;

/**
 * toPaginate() with NO arguments describes the page the query returned.
 *
 * REGRESSION, measured 2026-08-05 across all four frameworks on a real 250-row
 * table read with limit=20 offset=40 (page 3 of 13). PHP was the ONLY one of
 * the four that got this right - Python and Node reported page 1, and Ruby
 * returned zero rows. This test exists so PHP's correct behaviour is pinned
 * rather than incidental, since it is the shape the other three were converged
 * onto.
 *
 * Pure value object, no dependency and no double.
 */
final class DatabaseResultPaginateTest extends TestCase
{
    private function pageThree(): DatabaseResult
    {
        $rows = [];
        for ($i = 40; $i < 60; $i++) {   // the rows page 3 holds
            $rows[] = ['id' => $i];
        }

        return new DatabaseResult($rows, ['id'], 250, 20, 40);
    }

    public function testPageIsDerivedFromTheOffset(): void
    {
        $pag = $this->pageThree()->toPaginate();
        $this->assertSame(3, $pag['page'], 'offset 40 / limit 20 is page 3');
        $this->assertSame(20, $pag['per_page']);
        $this->assertSame(13, $pag['total_pages']);
    }

    public function testRecordsAreTheRowsTheQueryReturned(): void
    {
        $pag = $this->pageThree()->toPaginate();
        $this->assertCount(20, $pag['records'], 'the envelope must not re-slice the page');
        $this->assertSame(40, $pag['records'][0]['id']);
    }

    public function testTotalIsTheTrueTotalNotRowsReturned(): void
    {
        $this->assertSame(250, $this->pageThree()->toPaginate()['total']);
    }

    public function testSlicingAnAlreadyOffsetPageIsRefused(): void
    {
        // NEGATIVE: slicing from row 0 a result whose rows start at 40 is the
        // measured Ruby zero-rows bug. It must raise, not answer wrongly.
        $this->expectException(\InvalidArgumentException::class);
        $this->pageThree()->toPaginate(2, 10);
    }
}
