<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Parity guard for tina4#271. A child template whose last {% endblock %} is
 * malformed (its closing } missing) must not hang the render.
 *
 * Python's extractBlocks looped forever on this (its inner `while...else`
 * skipped the `else` that advances past a malformed open tag on `break`). PHP's
 * template compiler parses the token stream with a `while ($pos < $count)` loop
 * bounded by the finite token array -- a malformed `{% endblock %` is not
 * tokenised as an endblock tag, so `parse(until: 'endblock')` simply runs to the
 * end and returns -- so PHP is structurally immune. The contract must stay locked
 * in all four frameworks, so this renders the exact malformed template and
 * asserts it returns. No mocks: real .twig files in a real temp dir, real engine.
 */
class FrondMalformedEndblockHangTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/frond_malformed_' . uniqid('', true);
        mkdir($dir);
        return $dir;
    }

    public function testMalformedEndblockInChildDoesNotHang(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/base.twig", '<html>{% block content %}default{% endblock %}</html>');
        // The final tag is missing its closing brace: `{% endblock %` not `%}`.
        file_put_contents("$dir/page.twig", "{% extends \"base.twig\" %}\n{% block content %}\n<p>hi</p>\n{% endblock %");
        $out = (new Frond($dir))->render('page.twig', []);
        $this->assertIsString($out);
    }

    public function testWellFormedChildStillSubstitutes(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/base.twig", '<html>{% block content %}default{% endblock %}</html>');
        file_put_contents("$dir/page.twig", "{% extends \"base.twig\" %}\n{% block content %}<p>hi</p>{% endblock %}");
        $out = (new Frond($dir))->render('page.twig', []);
        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringNotContainsString('default', $out);
    }
}
