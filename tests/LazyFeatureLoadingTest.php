<?php

/**
 * Lock-in: a never-referenced subsystem is never loaded.
 *
 * PHP is the one framework that already satisfied this contract for free --
 * PSR-4 autoloading is lazy by definition, so `Tina4\Queue` is not read off
 * disk until something names it. That is exactly why it needs a test: nothing
 * was defending the property, so a single `require_once` or a class_alias() in
 * a bootstrap file could quietly make the whole feature set eager again and no
 * suite would notice.
 *
 * Measured on this change (macOS, PHP 8.5): vendor/autoload.php declares 20
 * Tina4 classes and none of the 8 optional subsystems below.
 *
 * Each assertion runs in a FRESH php process: PHPUnit's own bootstrap loads a
 * large part of the framework, so checking get_declared_classes() in-process
 * would prove nothing. No mocks -- the real composer autoloader.
 *
 * Parity: Python tests/test_lazy_feature_loading.py (PEP 562 module
 * __getattr__), Ruby spec/lazy_feature_loading_spec.rb (Module#autoload),
 * Node test/lazyFeatureLoading.test.ts (static ESM re-exports are eager by
 * spec, so Node gets its granularity from the package split instead).
 */
class LazyFeatureLoadingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Subsystems that must NOT load unless the app references them, named by a
     * real class each one owns.
     *
     * DocStore is deliberately absent: it is a function-first module (there is
     * no Tina4\DocStore class) and it is included EAGERLY via composer's "files"
     * map so that Tina4\getCollection() exists without a class reference. Its
     * hazard is covered by testNoEagerFileCollidesWithItsPsr4ClassPath below.
     */
    private const LAZY_SUBSYSTEMS = [
        'Queue',
        'Messenger',
        'GraphQL',
        'WSDL',
        'Mqtt',
        'Swagger',
        'AutoCrud',
    ];

    private function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Run a snippet in a fresh PHP process with only the composer autoloader
     * bootstrapped, and return its trimmed stdout.
     *
     * stdout and stderr are captured SEPARATELY (not `2>&1`-merged): the
     * boolean-shaped assertions in test methods below need a clean stdout
     * that is not polluted by diagnostic side-channels — the last-resort
     * ImportHelper writes a hint to error_log() (which lands on stderr in
     * CLI) on any unresolved Tina4\ miss, and PHP startup warnings (e.g.
     * a missing pecl extension on a dev Mac) do the same. Both are correct
     * behaviour and must not fail a test that asks whether class_exists
     * returned a clean 'true'/'false'. On a non-zero exit, the failure
     * message includes stderr so a real fatal is still diagnosable.
     */
    private function inFreshPhp(string $code): string
    {
        $autoload = $this->projectRoot() . '/vendor/autoload.php';
        $script = \TempPath::file('t4lazy', '.php');
        file_put_contents(
            $script,
            "<?php\nrequire " . var_export($autoload, true) . ";\n" . $code . "\n"
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // display_errors=stderr keeps warnings off stdout so the caller can
        // assert a clean 'true'/'false'; display_startup_errors=0 silences
        // host-level startup warnings (e.g. a missing pecl extension on a
        // dev Mac) that otherwise land on stdout under some SAPI configs.
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=0',
            $script,
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($process, 'failed to start php subprocess');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($script);

        $stdoutText = trim((string) $stdout);
        $stderrText = trim((string) $stderr);
        $this->assertSame(
            0,
            $status,
            "snippet failed (exit={$status})\nstdout:\n{$stdoutText}\nstderr:\n{$stderrText}"
        );

        return $stdoutText;
    }

    public function testAutoloadingDoesNotDeclareOptionalSubsystems(): void
    {
        // class_exists(..., false) is the whole trick: the second argument
        // disables autoloading, so asking the question cannot itself load the
        // class and invalidate the answer.
        $out = $this->inFreshPhp(
            '$eager = [];' .
            'foreach (' . var_export(self::LAZY_SUBSYSTEMS, true) . ' as $c) {' .
            '  if (class_exists("Tina4\\\\$c", false) || interface_exists("Tina4\\\\$c", false)) { $eager[] = $c; }' .
            '}' .
            'echo implode(",", $eager);'
        );

        $this->assertSame(
            '',
            $out,
            "these optional subsystems were declared by vendor/autoload.php alone: {$out}. " .
            'Something added a require/require_once for them, or a composer "files" ' .
            'autoload entry references the class at load time -- move that reference ' .
            'into the method that uses it.'
        );
    }

    public function testReferencingASubsystemLoadsItOnDemand(): void
    {
        $out = $this->inFreshPhp(
            '$before = class_exists("Tina4\\\\Queue", false) ? "y" : "n";' .
            '$resolved = class_exists("Tina4\\\\Queue") ? "y" : "n";' .   // autoload allowed here
            '$after = class_exists("Tina4\\\\Queue", false) ? "y" : "n";' .
            'echo "$before|$resolved|$after";'
        );

        [$before, $resolved, $after] = explode('|', $out);
        $this->assertSame('n', $before, 'Tina4\Queue was already declared -- laziness is broken');
        $this->assertSame('y', $resolved, 'the autoloader could not resolve Tina4\Queue at all');
        $this->assertSame('y', $after, 'referencing Tina4\Queue did not actually load it');
    }

    public function testEveryLazySubsystemIsStillResolvable(): void
    {
        // Laziness must not hide a class that no longer exists: proving each
        // name resolves when asked is what stops this test from passing simply
        // because a subsystem was deleted.
        $out = $this->inFreshPhp(
            '$missing = [];' .
            'foreach (' . var_export(self::LAZY_SUBSYSTEMS, true) . ' as $c) {' .
            '  if (!class_exists("Tina4\\\\$c")) { $missing[] = $c; }' .
            '}' .
            'echo $missing ? implode(",", $missing) : "all resolvable";'
        );

        $this->assertSame('all resolvable', $out, "the autoloader cannot resolve: {$out}");
    }

    /**
     * Every composer "files" entry must sit OUTSIDE the path PSR-4 would derive
     * for a class of the same name.
     *
     * This is the bug this test was born from. A "files" entry is included
     * eagerly, but if its path is also what PSR-4 derives for a class name it
     * does not declare (Tina4/DocStore.php <-> class Tina4\DocStore), then
     * referencing that name sends composer's ClassLoader back to the same file
     * for a SECOND plain include() -- and PHP EARLY-BINDS unconditional
     * top-level functions and interfaces at compile time, so the fatal
     * "Cannot redeclare ..." fires before any runtime guard inside the file
     * could return. It cannot be defended from within the file; the path has to
     * be unreachable. Hence Tina4/Bootstrap/ (mirroring the already-safe
     * Tina4/Middleware/CacheFunctions.php).
     *
     * `class_exists('Tina4\MCP')` -- ordinary feature detection -- killed the
     * process before this. Driving the check off composer.json means a NEW
     * "files" entry added at a colliding path fails here rather than in
     * somebody's app.
     */
    public function testNoEagerFileCollidesWithItsPsr4ClassPath(): void
    {
        $composer = json_decode(file_get_contents($this->projectRoot() . '/composer.json'), true);
        $files = $composer['autoload']['files'] ?? [];
        $this->assertNotEmpty($files, 'composer.json declares no "files" entries -- did the key move?');

        $collisions = [];
        foreach ($files as $file) {
            // PSR-4 maps Tina4\<Name> to Tina4/<Name>.php. A "files" entry that
            // IS that path is the hazard; one nested deeper (Tina4/Bootstrap/X.php,
            // Tina4/Middleware/X.php) is not reachable that way.
            $stem = basename($file, '.php');
            $derived = 'Tina4/' . $stem . '.php';
            if ($file === $derived) {
                $collisions[] = $file;
            }
        }

        $this->assertSame(
            [],
            $collisions,
            "these eagerly-included files sit at their own PSR-4 class path: " .
            implode(', ', $collisions) . '. Referencing the matching class name ' .
            '(e.g. class_exists("Tina4\\' . 'Whatever")) will re-include the file ' .
            'and fatal on a redeclare. Move the file one directory down ' .
            '(Tina4/Bootstrap/) so PSR-4 cannot derive its path.'
        );
    }

    /**
     * The behavioural half of the test above: asking for these names must
     * return false, not kill the process.
     */
    public function testReferencingAnEagerFilesNameDoesNotFatal(): void
    {
        $composer = json_decode(file_get_contents($this->projectRoot() . '/composer.json'), true);
        $stems = array_map(
            static fn(string $f): string => basename($f, '.php'),
            $composer['autoload']['files'] ?? []
        );

        foreach ($stems as $stem) {
            // inFreshPhp() asserts a zero exit status, so a fatal here fails the
            // test with the redeclare message rather than passing silently.
            $out = $this->inFreshPhp(
                'echo class_exists("Tina4\\\\' . $stem . '") ? "true" : "false";'
            );
            $this->assertContains(
                $out,
                ['true', 'false'],
                "class_exists('Tina4\\{$stem}') did not return a clean boolean"
            );
        }
    }

    public function testCoreSurfaceIsAvailable(): void
    {
        $out = $this->inFreshPhp(
            '$missing = [];' .
            'foreach (["Tina4\\\\Router", "Tina4\\\\Request", "Tina4\\\\Response", "Tina4\\\\ORM",' .
            '          "Tina4\\\\Database\\\\Database", "Tina4\\\\Frond", "Tina4\\\\Auth"] as $c) {' .
            '  if (!class_exists($c)) { $missing[] = $c; }' .
            '}' .
            'echo $missing ? implode(",", $missing) : "core ok";'
        );

        $this->assertSame('core ok', $out, "core classes missing: {$out}");
    }
}
