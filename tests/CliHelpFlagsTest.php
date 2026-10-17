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
 * `--help` / `-h` print usage and change nothing, for every command (PHP mirror
 * of the Python master's tests/test_cli_help_flags.py).
 *
 * Handlers never looked for a help flag, so it ran the command for real:
 * `generate model --help` wrote a model named `--help`, `lint --help` installed
 * phpcs, `serve --help` started a server. NO MOCKS: each case runs the REAL
 * `bin/tina4php` as a subprocess in a real temp project and diffs the directory.
 * The command and generator lists come from the real `commands --json` manifest,
 * so a new command is covered the moment it is registered.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CliHelpFlagsTest extends TestCase
{
    /** Seconds before a run is killed: an unfixed CLI starts servers and workers. */
    private const TIMEOUT = 30;

    /** An option no command has, used to probe which options a command accepts. */
    private const BOGUS = '--zz-not-an-option';

    private static ?array $manifest = null;
    private string $dir;
    private string $home;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/tina4-clihelp-' . getmypid() . '-' . uniqid();
        $this->dir = $base . '/project';
        $this->home = $base . '/home';
        mkdir($this->dir, 0755, true);
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->dir));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item !== '.' && $item !== '..') {
                self::removeDir($dir . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($dir);
    }

    private static function bin(): string
    {
        $bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse($bin, 'bin/tina4php not found');
        return $bin;
    }

    /** The real `commands --json` manifest, read once. */
    private static function manifest(): array
    {
        if (self::$manifest === null) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::bin()) . ' commands --json';
            $json = (string)shell_exec('cd ' . escapeshellarg(sys_get_temp_dir()) . ' && ' . $cmd);
            self::$manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }
        return self::$manifest;
    }

    /** @return array<string, array> name => manifest entry, framework commands only. */
    private static function commands(): array
    {
        $commands = [];
        foreach (self::manifest()['commands'] as $entry) {
            if (empty($entry['delegated'])) {
                $commands[$entry['name']] = $entry;
            }
        }
        return $commands;
    }

    /** @return list<string> */
    private static function generators(): array
    {
        return self::commands()['generate']['subcommands'];
    }

    /**
     * Run the REAL `bin/tina4php <args...>` in the temp project, with stdin closed,
     * HOME pointed at a temp dir and a hard timeout.
     *
     * @param list<string> $args
     * @return array{0: string, 1: string, 2: int} [stdout, stderr, exitCode]
     */
    private function runCli(array $args): array
    {
        $env = getenv();
        unset($env['PHPRC'], $env['PHP_INI_SCAN_DIR']);
        $env['TINA4_NO_BROWSER'] = 'true';
        $env['HOME'] = $this->home;

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(array_merge([PHP_BINARY, self::bin()], $args), $descriptors, $pipes, $this->dir, $env);
        self::assertIsResource($process, 'failed to spawn bin/tina4php');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = (string)fread($pipe, 65536);
                    $pipe === $pipes[1] ? $out .= $chunk : $err .= $chunk;
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err .= (string)stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [$out, $err, $status['exitcode']];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [$out, $err . "\n[killed after " . self::TIMEOUT . "s]", -1];
            }
        }
    }

    /** A minimal project: an index.php and a composer.json that lint could rewrite. */
    private function project(): string
    {
        file_put_contents($this->dir . '/index.php', "<?php\n// demo app\n");
        file_put_contents($this->dir . '/composer.json', "{\n    \"name\": \"demo/app\",\n    \"require\": {}\n}\n");
        return $this->dir;
    }

    /** @return array<string, string> every path under $root => sha256 of its bytes ("dir" for dirs). */
    private static function snapshot(string $root): array
    {
        $seen = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $path => $info) {
            $relative = substr($path, strlen($root) + 1);
            $seen[$relative] = $info->isDir() ? 'dir' : hash_file('sha256', $path);
        }
        ksort($seen);
        return $seen;
    }

    // ── --help / -h on every command and generator ───────────────────────

    public static function helpCases(): array
    {
        $cases = [];
        foreach (['--help', '-h'] as $flag) {
            foreach (array_keys(self::commands()) as $name) {
                if ($name !== 'help') {
                    $cases["{$name} {$flag}"] = [[$name], $flag];
                }
            }
            foreach (self::generators() as $name) {
                $cases["generate {$name} {$flag}"] = [['generate', $name], $flag];
            }
        }
        return $cases;
    }

    #[DataProvider('helpCases')]
    public function testHelpFlagPrintsUsageAndWritesNothing(array $argv, string $flag): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli([...$argv, $flag]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Usage: tina4php ' . implode(' ', $argv), $out);
        $this->assertSame($before, self::snapshot($project), 'a --help run changed the project');
    }

    #[DataProvider('flags')]
    public function testHelpFlagAfterTheNameStillWins(string $flag): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli(['generate', 'model', 'Product', '--fields', 'name:string', $flag]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Usage: tina4php generate model', $out);
        $this->assertSame($before, self::snapshot($project));
    }

    #[DataProvider('flags')]
    public function testTopLevelHelpFlagPrintsTheReference(string $flag): void
    {
        [$out, $err, $exit] = $this->runCli([$flag]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Usage: tina4php <command>', $out);
    }

    public static function helpCommandForms(): array
    {
        return ['help' => [['help']], 'help --help' => [['help', '--help']], 'help -h' => [['help', '-h']]];
    }

    #[DataProvider('helpCommandForms')]
    public function testTheHelpCommandAlwaysPrintsTheReference(array $argv): void
    {
        [$out, $err, $exit] = $this->runCli($argv);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Usage: tina4php <command>', $out);
        $this->assertStringNotContainsString('Unknown option', $out);
    }

    public static function flags(): array
    {
        return ['--help' => ['--help'], '-h' => ['-h']];
    }

    public function testGenerateHelpListsEveryGenerator(): void
    {
        [$out, $err, $exit] = $this->runCli(['generate', '--help']);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringContainsString('Usage: tina4php generate <what> <name> [options]', $out);
        $this->assertStringContainsString('Generators:', $out);
        foreach (self::generators() as $name) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $out);
        }
    }

    public static function flagLikeNames(): array
    {
        return [
            'model, name forgotten' => [['generate', 'model', '--fields', 'name:string']],
            'route, name forgotten' => [['generate', 'route', '--public']],
        ];
    }

    #[DataProvider('flagLikeNames')]
    public function testANameThatLooksLikeAnOptionIsRefused(array $argv): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli($argv);
        $this->assertSame(1, $exit, $out . $err);
        $this->assertStringContainsString("can't start with '-'", $out);
        $this->assertSame($before, self::snapshot($project));
    }

    // ── options: unknown ones, missing values, --name=value, ai --all ─────

    public static function misspellings(): array
    {
        return [
            '--feilds' => [['generate', 'model', 'Product', '--feilds', 'name:string'], '--feilds', '--fields', 'generate model'],
            '--publc'  => [['generate', 'route', 'products', '--publc'], '--publc', '--public', 'generate route'],
            '--prot'   => [['serve', '--prot', '9000'], '--prot', '--port', 'serve'],
            '--onse'   => [['queue', 'work', '--onse'], '--onse', '--once', 'queue work'],
        ];
    }

    #[DataProvider('misspellings')]
    public function testAMisspelledOptionIsRefusedWithASuggestion(array $argv, string $wrong, string $suggestion, string $label): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli($argv);
        $this->assertSame(1, $exit, $out . $err);
        $this->assertStringContainsString(
            "Unknown option {$wrong} for \"{$label}\". Did you mean {$suggestion}?\n"
            . "Run \"tina4php {$label} --help\" for its options.",
            $out
        );
        $this->assertSame($before, self::snapshot($project));
    }

    public static function foreignOptions(): array
    {
        return [
            'short option'      => [['generate', 'model', 'Product', '-f', 'name:string'], '-f', 'generate model'],
            'lint --everything' => [['lint', '--everything'], '--everything', 'lint'],
            'migrate --force'   => [['migrate', '--force'], '--force', 'migrate'],
            '--x=y form'        => [['build', '--platform=linux/amd64'], '--platform', 'build'],
        ];
    }

    #[DataProvider('foreignOptions')]
    public function testAnOptionTheCommandDoesNotHaveIsRefused(array $argv, string $option, string $label): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli($argv);
        $this->assertSame(1, $exit, $out . $err);
        $this->assertStringContainsString("Unknown option {$option} for \"{$label}\".", $out);
        $this->assertStringNotContainsString('Did you mean', $out);
        $this->assertSame($before, self::snapshot($project));
    }

    /**
     * `test` hands its arguments to PHPUnit, as `tina4python test` does to
     * pytest. It used to drop them, so `test --filter x` ran the whole suite.
     * The project's vendor/bin/phpunit is this repo's real PHPUnit.
     */
    public function testTestPassesItsArgumentsToPhpunit(): void
    {
        $project = $this->project();
        mkdir($project . '/tests');
        mkdir($project . '/vendor/bin', 0755, true);
        symlink(dirname(__DIR__) . '/vendor/bin/phpunit', $project . '/vendor/bin/phpunit');
        foreach (['One', 'Two'] as $name) {
            file_put_contents(
                "{$project}/tests/{$name}Test.php",
                "<?php\nuse PHPUnit\\Framework\\TestCase;\n"
                . "final class {$name}Test extends TestCase\n{\n"
                . "    public function test{$name}(): void { \$this->assertTrue(true); }\n}\n"
            );
        }

        [$all, , $allExit] = $this->runCli(['test']);
        $this->assertSame(0, $allExit, $all);
        $this->assertStringContainsString('OK (2 tests', $all);

        [$out, $err, $exit] = $this->runCli(['test', '--filter', 'testOne']);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringNotContainsString('Unknown option', $out);
        $this->assertStringContainsString('OK (1 test', $out);
    }

    public static function missingValues(): array
    {
        return [
            'bare --fields'           => [['generate', 'model', 'Product', '--fields'], 'fields', 'generate model'],
            'bare --every'            => [['generate', 'service', 'Nightly', '--every'], 'every', 'generate service'],
            '--fields then an option' => [['generate', 'model', 'Product', '--fields', '--no-migration'], 'fields', 'generate model'],
            'bare --poll'             => [['queue', 'work', '--poll'], 'poll', 'queue work'],
        ];
    }

    #[DataProvider('missingValues')]
    public function testAValueOptionWithoutAValueIsRefused(array $argv, string $option, string $label): void
    {
        $project = $this->project();
        $before = self::snapshot($project);
        [$out, $err, $exit] = $this->runCli($argv);
        $this->assertSame(1, $exit, $out . $err);
        $this->assertStringContainsString(
            "Option --{$option} for \"{$label}\" needs a value: --{$option} <value>",
            $out
        );
        $this->assertDoesNotMatchRegularExpression('/Fatal error|Stack trace|Uncaught/', $out . $err);
        $this->assertSame($before, self::snapshot($project));
    }

    public static function fieldSpellings(): array
    {
        return [
            '--fields value' => [['--fields', 'price:float']],
            '--fields=value' => [['--fields=price:float']],
        ];
    }

    #[DataProvider('fieldSpellings')]
    public function testAValueOptionWorksInBothSpellings(array $fieldsArg): void
    {
        $project = $this->project();
        [$out, $err, $exit] = $this->runCli(['generate', 'model', 'Product', ...$fieldsArg]);
        $this->assertSame(0, $exit, $out . $err);
        $model = $project . '/src/orm/Product.php';
        $this->assertFileExists($model, $out . $err);
        $this->assertStringContainsString('price', (string)file_get_contents($model));
    }

    public static function aiAllForms(): array
    {
        return ['--all' => ['--all'], 'all (old form)' => ['all']];
    }

    #[DataProvider('aiAllForms')]
    public function testAiAllInstallsWithoutTheMenu(string $form): void
    {
        [$out, $err, $exit] = $this->runCli(['ai', $form]);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringNotContainsString('Select (comma-separated', $out);
        $this->assertFileExists($this->dir . '/CLAUDE.md');
        $this->assertFileExists($this->dir . '/AGENTS.md');
    }

    /** `generate model` suggests `generate crud <Name> --skip-model`; it must work. */
    public function testCrudSkipModelFollowsTheModelGeneratorsHint(): void
    {
        $project = $this->project();
        [$out, $err, $exit] = $this->runCli(['generate', 'model', 'Product', '--fields', 'price:float']);
        $this->assertSame(0, $exit, $out . $err);
        $model = (string)file_get_contents($project . '/src/orm/Product.php');

        [$out, $err, $exit] = $this->runCli(['generate', 'crud', 'Product', '--skip-model']);
        $this->assertSame(0, $exit, $out . $err);
        $this->assertStringNotContainsString('already exists', $out);
        $this->assertSame($model, (string)file_get_contents($project . '/src/orm/Product.php'));
        $this->assertNotEmpty(glob($project . '/src/routes/*.php'), 'crud wrote no admin route');
    }

    // ── the usage text and the accepted options can't drift apart ────────

    /**
     * Every `--option` a usage line documents is an option the command accepts.
     *
     * Black-box: `<command> --opt=x --zz-not-an-option` is checked left to right
     * before any handler runs, so the error names --opt when it is NOT accepted
     * and the bogus option when it is. Nothing runs either way.
     */
    public function testEveryOptionInTheHelpIsAnAcceptedOption(): void
    {
        $labels = [];
        foreach (array_keys(self::commands()) as $name) {
            if (!in_array($name, ['help', 'generate'], true)) {
                $labels[] = [$name];
            }
        }
        foreach (self::generators() as $name) {
            $labels[] = ['generate', $name];
        }

        $checked = 0;
        foreach ($labels as $argv) {
            [$out, , ] = $this->runCli([...$argv, '--help']);
            $usage = strtok($out, "\n");
            $label = implode(' ', $argv);
            $this->assertStringStartsWith("Usage: tina4php {$label}", (string)$usage);
            preg_match_all('/--([a-z][a-z-]*)/', (string)$usage, $matches);
            foreach (array_unique($matches[1]) as $option) {
                $probe = $argv[0] === 'generate' ? [...$argv, 'Probe'] : $argv;
                [$probeOut, $probeErr, $exit] = $this->runCli([...$probe, "--{$option}=x", self::BOGUS]);
                $this->assertSame(1, $exit, $probeOut . $probeErr);
                $this->assertStringContainsString(
                    'Unknown option ' . self::BOGUS . " for \"{$label}\"",
                    $probeOut,
                    "{$label}: --{$option} documented, not accepted"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked, 'the usage strings should document options');

        // Every queue subcommand has its own option map (an unmapped one skips the check).
        foreach (self::commands()['queue']['subcommands'] as $sub) {
            [$out, , $exit] = $this->runCli(['queue', $sub, self::BOGUS]);
            $this->assertSame(1, $exit, $out);
            $this->assertStringContainsString('Unknown option ' . self::BOGUS . " for \"queue {$sub}\"", $out);
        }
    }
}
