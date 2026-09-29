<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Real-subprocess tests for scripts/check-no-symlinks.sh — the guard that keeps
 * committed symlinks (git mode 120000) out of the repo. A leaked container
 * conf.d snapshot once committed 134 absolute symlinks; 7-Zip refuses those on
 * Windows `composer install` as a "Dangerous link path", so extraction exits 2
 * and composer fails. The guard runs `git ls-files -s`, so it is meaningful
 * only inside a git working tree.
 *
 * NO MOCKS: every test runs the REAL shell script as a child process via
 * proc_open and asserts on its real stdout/stderr and real exit code.
 *   - the pass case runs against the REAL repo at HEAD, proving the tree that
 *     this checkout ships carries no committed symlink;
 *   - the fail case builds a REAL throwaway git repo, stages a REAL symlink, and
 *     proves the guard exits non-zero AND names the offending path. Staging is
 *     asserted to have actually landed a mode-120000 entry, so the negative test
 *     can never turn into a ghost that passes because nothing was staged.
 */

use PHPUnit\Framework\TestCase;

class NoSymlinksGuardTest extends TestCase
{
    private static string $script;
    private static string $repoRoot;
    private ?string $fixtureRoot = null;

    public static function setUpBeforeClass(): void
    {
        $script = realpath(__DIR__ . '/../scripts/check-no-symlinks.sh');
        self::assertNotFalse($script, 'scripts/check-no-symlinks.sh not found');
        self::$script   = $script;
        self::$repoRoot = dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null && is_dir($this->fixtureRoot)) {
            self::removeTree($this->fixtureRoot);
        }
        $this->fixtureRoot = null;
    }

    /**
     * Run the guard as a real subprocess inside $cwd (a git working tree).
     * Returns [stdout, stderr, exitCode].
     */
    private function runGuard(string $cwd): array
    {
        $cmd = 'sh ' . escapeshellarg(self::$script);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        self::assertIsResource($process, 'failed to start the guard subprocess');

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$out, $err, $exit];
    }

    /** Run a git command in $cwd, asserting it succeeds. */
    private static function git(string $cwd, string $args): void
    {
        $cmd = 'git -C ' . escapeshellarg($cwd) . ' ' . $args . ' 2>&1';
        exec($cmd, $output, $exit);
        self::assertSame(0, $exit, "git {$args} failed:\n" . implode("\n", $output));
    }

    /**
     * Build a throwaway git repo with one ordinary committed file, so the guard
     * has a real working tree to inspect. Returns its path.
     */
    private function makeGitFixture(): string
    {
        $root = sys_get_temp_dir() . '/tina4-nosymlink-' . getmypid() . '-' . uniqid();
        mkdir($root, 0755, true);
        self::git($root, 'init -q');
        // A local identity so `git commit` works without the host's global config.
        self::git($root, 'config user.email test@example.com');
        self::git($root, 'config user.name Test');
        // Some real symlink support probes need core.symlinks on explicitly.
        self::git($root, 'config core.symlinks true');
        file_put_contents($root . '/README.md', "fixture\n");
        self::git($root, 'add README.md');
        self::git($root, 'commit -q -m initial');
        return $root;
    }

    /** Count the mode-120000 entries git currently has staged/tracked in $cwd. */
    private static function committedSymlinkCount(string $cwd): int
    {
        $cmd = 'git -C ' . escapeshellarg($cwd)
             . ' ls-files -s | awk \'$1=="120000"{c++} END{print c+0}\'';
        return (int) trim((string) shell_exec($cmd));
    }

    private static function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            // Do NOT descend through a symlink; unlink the link itself.
            (is_dir($path) && !is_link($path)) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── positive: the shipped tree carries no committed symlink at HEAD ──────

    public function testRealRepoHasNoCommittedSymlinks(): void
    {
        [$out, , $exit] = $this->runGuard(self::$repoRoot);

        self::assertSame(0, $exit, "guard must pass at HEAD:\n" . $out);
        self::assertStringContainsString('OK: no committed symlinks', $out);
    }

    // ── negative: a staged symlink makes the guard fail and names the path ───

    public function testStagedSymlinkFailsAndNamesThePath(): void
    {
        // Skip only where the platform genuinely cannot stage a symlink (e.g. a
        // Windows checkout without privilege). CI (Linux) stages it for real.
        if (function_exists('symlink') === false) {
            self::markTestSkipped('[needs:symlink-support] symlink() unavailable on this host');
        }

        $this->fixtureRoot = $this->makeGitFixture();

        $link = $this->fixtureRoot . '/20-pdo.ini';
        if (@symlink('/etc/php/8.3/cli/conf.d/20-pdo.ini', $link) === false) {
            self::markTestSkipped('[needs:symlink-support] cannot create a symlink on this filesystem');
        }
        self::git($this->fixtureRoot, 'add 20-pdo.ini');

        // Prove the fixture really staged a mode-120000 entry — otherwise this
        // negative test would be a ghost passing on an empty tree.
        self::assertSame(1, self::committedSymlinkCount($this->fixtureRoot),
            'fixture failed to stage a symlink as git mode 120000');

        [$out, $err, $exit] = $this->runGuard($this->fixtureRoot);
        $combined = $out . $err;

        self::assertNotSame(0, $exit, "guard must exit non-zero on a staged symlink:\n" . $combined);
        self::assertStringContainsString('committed symlinks are not allowed', $combined);
        self::assertStringContainsString('20-pdo.ini', $combined); // names the offender
    }

    // ── negative: removing the symlink again makes the same tree pass ────────

    public function testTreePassesOnceTheSymlinkIsRemoved(): void
    {
        if (function_exists('symlink') === false) {
            self::markTestSkipped('[needs:symlink-support] symlink() unavailable on this host');
        }

        $this->fixtureRoot = $this->makeGitFixture();

        $link = $this->fixtureRoot . '/20-pdo.ini';
        if (@symlink('/etc/php/8.3/cli/conf.d/20-pdo.ini', $link) === false) {
            self::markTestSkipped('[needs:symlink-support] cannot create a symlink on this filesystem');
        }
        self::git($this->fixtureRoot, 'add 20-pdo.ini');
        self::assertSame(1, self::committedSymlinkCount($this->fixtureRoot),
            'fixture failed to stage a symlink as git mode 120000');

        // Unstage and delete the link — the guard must now go green on the very
        // same tree, proving it reacts to the symlink and not to the fixture.
        self::git($this->fixtureRoot, 'rm -q --cached 20-pdo.ini');
        @unlink($link);
        self::assertSame(0, self::committedSymlinkCount($this->fixtureRoot),
            'fixture still has a staged symlink after removal');

        [$out, , $exit] = $this->runGuard($this->fixtureRoot);
        self::assertSame(0, $exit, "guard must pass once the symlink is gone:\n" . $out);
        self::assertStringContainsString('OK: no committed symlinks', $out);
    }
}
