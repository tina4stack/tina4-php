<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Zero-dependency guard (parity with Node's core-barrel guard and Python's
 * import guard). The framework's composer runtime `require` must name NO
 * third-party package -- only `php` itself and `ext-*` extensions. Optional
 * capabilities (RS256 / outbound HTTPS via ext-openssl, the database drivers,
 * message brokers) are SUGGESTED or loaded on demand, never a hard runtime
 * dependency, so an app that never uses a feature installs nothing extra. A
 * feature that adds a composer package to `require` would install it for every
 * app; this catches that.
 */

use PHPUnit\Framework\TestCase;

class ZeroDependencyRequireTest extends TestCase
{
    public function testComposerRuntimeRequireNamesNoThirdPartyPackage(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($composer, 'composer.json must parse');
        $require = $composer['require'] ?? [];

        $thirdParty = array_values(array_filter(
            array_keys($require),
            static fn (string $pkg): bool => $pkg !== 'php' && !str_starts_with($pkg, 'ext-')
        ));

        self::assertSame(
            [],
            $thirdParty,
            'composer "require" must name only php and ext-* extensions. These are '
                . 'third-party runtime dependencies and break the zero-dependency core: '
                . implode(', ', $thirdParty)
        );
    }
}
