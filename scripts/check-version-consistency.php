<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Pre-tag version-consistency precheck.
 *
 * A release bumps the framework version in more than one place. This script
 * asserts every version-bearing file agrees on the intended version BEFORE the
 * tag is cut, so a partial bump (a file left behind) fails locally instead of
 * on CI after the tag has already been pushed.
 *
 * Version-bearing files in tina4-php — each carries the version as a LITERAL and
 * is bumped on every release:
 *   - Tina4/App.php   public static string $VERSION = 'X';   (the single source
 *                     of truth — see the docblock on that constant)
 *   - CLAUDE.md       footer line:  Version X - ...
 *
 * composer.json carries NO `version` key by design — Packagist derives the
 * version from the git tag (see the App::$VERSION docblock). Its absence is NOT
 * a failure. If a `version` key is ever added, it is checked too.
 *
 * Deliberately NOT checked — these carry version literals that are NOT the
 * current release version, so asserting them would wrongly fail at HEAD:
 *   - CHANGELOG.md (historical record of every release)
 *   - llms.txt / Dockerfile FROM examples (drift on purpose, not release-bumped)
 *   - the .agents/.claude/.cursor skill `updated_for_version` field
 *   - the `vX.Y.Z` "changed in" docblock annotations throughout Tina4/*.php
 *
 * Usage:
 *   php scripts/check-version-consistency.php <expected-version> [root-dir]
 *
 *   <expected-version>  the version the release intends to tag, e.g. 3.13.121
 *   [root-dir]          repo root to check (default: the repo this script lives
 *                       in). Lets a test point the check at a fixture tree.
 *
 * Exit code:
 *   0  every version-bearing file agrees with <expected-version>
 *   1  one or more files drifted / are missing / are unparseable (each named)
 *   2  usage error (missing or malformed <expected-version>)
 *
 * Pure PHP, zero Composer dependencies.
 */

$expected = $argv[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $expected)) {
    fwrite(STDERR, "usage: php scripts/check-version-consistency.php <expected-version> [root-dir]\n");
    fwrite(STDERR, "  <expected-version> must be a semver like 3.13.121\n");
    exit(2);
}

$root = rtrim($argv[2] ?? dirname(__DIR__), '/');

/**
 * Read $path and pull the version literal out of it via $pattern (capture 1).
 * Returns [pass, found|null, reason|null]: pass is true only when the file
 * exists, is readable, matches, AND the captured version equals $expected.
 */
$check = function (string $rel, string $path, string $pattern) use ($expected): array {
    if (!is_file($path)) {
        return [$rel, false, null, 'file not found'];
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return [$rel, false, null, 'could not read file'];
    }
    if (!preg_match($pattern, $contents, $matches)) {
        return [$rel, false, null, 'no version literal found'];
    }
    $found = $matches[1];
    if ($found !== $expected) {
        return [$rel, false, $found, "expected {$expected}, found {$found}"];
    }
    return [$rel, true, $found, null];
};

$results = [];
$notes   = [];

// 1. Tina4/App.php — public static string $VERSION = '3.13.121';
//    (double-quoted string keeps the single quotes literal; \\\$ -> \$ so PCRE
//     matches a literal dollar sign, not the end-of-string anchor).
$results[] = $check('Tina4/App.php', $root . '/Tina4/App.php', "~\\\$VERSION\\s*=\\s*'([^']*)'~");

// 2. CLAUDE.md — footer line:  Version 3.13.121 - ...
$results[] = $check('CLAUDE.md', $root . '/CLAUDE.md', '/^Version\s+(\d+\.\d+\.\d+)\b/m');

// 3. composer.json — checked ONLY if a `version` key is present (absent by
//    design; Packagist derives the version from the git tag).
$composerPath = $root . '/composer.json';
if (is_file($composerPath)) {
    $json = json_decode((string) file_get_contents($composerPath), true);
    if (is_array($json) && array_key_exists('version', $json)) {
        $found = (string) $json['version'];
        $results[] = ['composer.json', $found === $expected, $found,
            $found === $expected ? null : "expected {$expected}, found {$found}"];
    } else {
        $notes[] = 'composer.json has no "version" key (tag-driven, intentional) — skipped';
    }
} else {
    $notes[] = 'composer.json not present — skipped';
}

$failed = [];
foreach ($results as [$rel, $pass, $found, $reason]) {
    if ($pass) {
        echo "PASS  {$rel}  {$found}\n";
    } else {
        echo "FAIL  {$rel}  " . ($reason ?? 'mismatch') . "\n";
        $failed[] = [$rel, $reason ?? 'mismatch'];
    }
}
foreach ($notes as $note) {
    echo "note  {$note}\n";
}

echo "\n";

if ($failed === []) {
    echo "OK    all " . count($results) . " version-bearing file(s) agree on {$expected}\n";
    exit(0);
}

echo "DRIFT  version mismatch — do NOT tag {$expected}:\n";
foreach ($failed as [$rel, $reason]) {
    echo "  - {$rel}: {$reason}\n";
}
exit(1);
