<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\FakeData;
use Tina4\SeedError;

/**
 * ADR-0108: a failing seed file fails loud. Real seed files on disk and the
 * real CLI in a subprocess; no doubles.
 */
class SeedDirFailLoudTest extends TestCase
{
    private string $root;
    private string $tracker;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tina4_seed_fail_loud_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/seeds', 0755, true);
        $this->tracker = $this->root . '/ran.txt';
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function seeds(): string
    {
        return $this->root . '/src/seeds';
    }

    private function record(string $name): string
    {
        return '<?php file_put_contents(' . var_export($this->tracker, true) . ', "' . $name . '\n", FILE_APPEND);';
    }

    private function ran(): array
    {
        return is_file($this->tracker) ? preg_split('/\s+/', trim(file_get_contents($this->tracker))) : [];
    }

    /** @return array<string, string> */
    private function threeFiles(): array
    {
        $files = [
            'ok_first' => $this->seeds() . '/001_ok.php',
            'raises' => $this->seeds() . '/002_raises.php',
            'ok_last' => $this->seeds() . '/003_ok.php',
        ];
        file_put_contents($files['ok_first'], $this->record('001_ok'));
        file_put_contents($files['raises'], '<?php throw new \RuntimeException("seed exploded");');
        file_put_contents($files['ok_last'], $this->record('003_ok'));
        return $files;
    }

    public function testAFailingSeedFileStopsTheRunAndThrowsNamingIt(): void
    {
        $files = $this->threeFiles();

        try {
            (new FakeData())->seedDir($this->seeds());
            $this->fail('seedDir must throw when a seed file throws');
        } catch (SeedError $error) {
            $this->assertSame($files['raises'], $error->seedFile);
            $this->assertSame([$files['ok_first']], $error->completed);
            $this->assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            $this->assertStringContainsString('002_raises.php', $error->getMessage());
            $this->assertStringContainsString('seed exploded', $error->getMessage());
        }
        // 001 ran, 003 never did: the run stopped at the failing file.
        $this->assertSame(['001_ok'], $this->ran());
    }

    public function testACleanFolderReturnsTheExecutedFilesInOrderAndSkipsUnderscore(): void
    {
        $second = $this->seeds() . '/002_second.php';
        $first = $this->seeds() . '/001_first.php';
        file_put_contents($second, $this->record('002_second'));
        file_put_contents($first, $this->record('001_first'));
        file_put_contents($this->seeds() . '/_helper.php', '<?php throw new \RuntimeException("a helper must never run");');

        $executed = (new FakeData())->seedDir($this->seeds());

        $this->assertSame([$first, $second], $executed);
        $this->assertSame(['001_first', '002_second'], $this->ran());
    }

    public function testTheRunnerCallsTheClosureASeedFileReturns(): void
    {
        file_put_contents(
            $this->seeds() . '/001_generated.php',
            '<?php return function (int $count = 20) { file_put_contents('
                . var_export($this->tracker, true) . ', "closure-" . $count . "\n", FILE_APPEND); };'
        );

        (new FakeData())->seedDir($this->seeds());

        $this->assertSame(['closure-20'], $this->ran());
    }

    public function testAMissingFolderReturnsAnEmptyList(): void
    {
        $this->assertSame([], (new FakeData())->seedDir($this->root . '/missing'));
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    private function runCliSeed(): array
    {
        $bin = dirname(__DIR__) . '/bin/tina4php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' seed';
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testCliSeedExits1NamingTheFailingFile(): void
    {
        $this->threeFiles();

        [$code, $output] = $this->runCliSeed();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('002_raises.php', $output);
        $this->assertSame(['001_ok'], $this->ran());
    }

    public function testCliSeedExits0WhenEveryFileRuns(): void
    {
        file_put_contents($this->seeds() . '/001_ok.php', $this->record('001_ok'));

        [$code, $output] = $this->runCliSeed();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['001_ok'], $this->ran());
    }
}
