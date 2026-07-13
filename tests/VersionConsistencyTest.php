<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guard against release-bump drift in CLAUDE.md (the AI-facing doc assistants
 * read as ground truth). PHP's version is tag-driven (App::resolveVersion reads
 * the Packagist-installed version), so there is no version file to anchor to;
 * instead we enforce that CLAUDE.md is internally consistent -- every version
 * string agrees with the header. This catches a future stale footer, the class
 * of drift that left the sister frameworks reporting an old "latest version".
 */
final class VersionConsistencyTest extends TestCase
{
    public function testClaudeMdVersionIsInternallyConsistent(): void
    {
        $claude = file_get_contents(__DIR__ . '/../CLAUDE.md');
        $this->assertNotFalse($claude, 'CLAUDE.md is missing');

        $this->assertSame(
            1,
            preg_match('/^Version (\d+\.\d+\.\d+)\b/m', $claude, $h),
            'CLAUDE.md is missing its "Version X" header'
        );
        $header = $h[1];

        preg_match_all('/^- Version:\s*(\d+\.\d+\.\d+)/m', $claude, $f);
        foreach ($f[1] as $footer) {
            $this->assertSame(
                $header,
                $footer,
                'CLAUDE.md "- Version:" footer disagrees with the header (bump both with the release)'
            );
        }
    }
}
