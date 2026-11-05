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
use Tina4\FrondSyntaxError;

/**
 * Parity guard for tina4#271 + tina4#286/#290. A child template whose
 * {% block %} is never closed — or whose last {% endblock %} is cut short to
 * `{% endblock %` (its closing `}` missing, so the tokenizer never produces the
 * tag) — must FAIL LOUD (tina4#286/#290), and must still be bounded so a
 * reintroduced hang cannot lock the render (tina4#271). PHP's template compiler
 * parses a finite token array, so it was already hang-immune; the change here is
 * that an absent endblock now raises instead of silently dropping the body.
 * No mocks: real .twig files in a real temp dir, real engine.
 */
class FrondMalformedEndblockHangTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/frond_malformed_' . uniqid('', true);
        mkdir($dir);
        return $dir;
    }

    public function testMalformedEndblockInChildFailsLoud(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/base.twig", '<html>{% block content %}default{% endblock %}</html>');
        // The final tag is missing its closing brace: `{% endblock %` not `%}`.
        file_put_contents("$dir/page.twig", "{% extends \"base.twig\" %}\n{% block content %}\n<p>hi</p>\n{% endblock %");
        $this->expectException(FrondSyntaxError::class);
        $this->expectExceptionMessageMatches('/content/');
        (new Frond($dir))->render('page.twig', []);
    }

    public function testUnclosedBlockFailsLoud(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/base.twig", '<html>{% block content %}default{% endblock %}</html>');
        // No {% endblock %} at all.
        file_put_contents("$dir/page.twig", "{% extends \"base.twig\" %}\n{% block content %}\n<p>hi</p>\n");
        $this->expectException(FrondSyntaxError::class);
        (new Frond($dir))->render('page.twig', []);
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
