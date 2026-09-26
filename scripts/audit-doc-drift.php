<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * audit-doc-drift.php -- fail when the in-repo CLAUDE.md documents a Tina4 API
 * the code does not have.
 *
 * This is the machine side of the First Principle ("Documentation Matches Code
 * Reality"), aimed at the AI-assistant document that ships INSIDE this repo and
 * is read by agents building on tina4-php: the top-level ``CLAUDE.md``. It is
 * the PHP sibling of tina4-python's ``scripts/audit_doc_drift.py``.
 *
 * Every claim is checked against the LIVE package -- the real Tina4 classes are
 * autoloaded and introspected with PHP Reflection, never a hand-kept list -- so
 * the gate itself cannot drift. Two things are checked inside every ```php```
 * fence:
 *
 *   1. ``use Tina4\...;`` imports -- each one must resolve to a real class,
 *      interface, or trait. (Catches ``use Tina4\CrudMagic;`` when no such
 *      class exists.)
 *
 *   2. ``Class::method(`` calls -- the base is resolved to a fully-qualified
 *      name (a fence ``use`` alias, then a fully-qualified ``\Tina4\...`` name,
 *      then the ``Tina4\`` namespace). A base that resolves to a real Tina4
 *      class must own the method (Reflection ``hasMethod``); a CapWord base
 *      that resolves to nothing real -- and is neither a fence-local class, a
 *      declared example model, nor a PHP builtin -- is itself flagged.
 *      (Catches ``CRUD::toCrud(`` -- there is no ``Tina4\CRUD``.)
 *
 * A class that defines ``__callStatic`` / ``__call`` (Frond, ORM) can answer a
 * name Reflection cannot see, so a MISSING method on such a class is not
 * flagged -- the gate reports only what it can prove. A method that really
 * exists is still verified even on a magic class.
 *
 * Usage:
 *   php scripts/audit-doc-drift.php            # report (exit 0)
 *   php scripts/audit-doc-drift.php --strict   # CI gate (exit 1 on drift)
 */

/**
 * The whole gate as one class so PHPUnit can drive it against temp directories
 * for the mutation proof, while the CLI wrapper at the bottom runs it against
 * the real repo.
 */
final class Tina4DocDriftAudit
{
    /**
     * Placeholder model names the docs use in examples. A ``CapWord::method(``
     * base that is one of these is application code, not a framework API.
     */
    private const EXAMPLE_MODELS = [
        'User', 'Product', 'Note', 'Order', 'Post', 'Item', 'Customer', 'Article',
        'Task', 'Event', 'Comment', 'Category', 'Visit', 'Author', 'Book', 'Todo',
        'Invoice', 'Account', 'Message', 'Contact', 'Page', 'Tag', 'Role', 'MyModel',
        'Foo', 'Bar', 'Widget', 'MyModel', 'MyCounter', 'MyWidget',
    ];

    /**
     * PHP-level CapWord bases that are language, not Tina4 API. Documented calls
     * on these are never framework claims.
     */
    private const PHP_BUILTINS = [
        'PDO', 'DateTime', 'DateTimeImmutable', 'DateInterval', 'Closure',
        'ArrayObject', 'Exception', 'Throwable', 'Error', 'ReflectionClass',
        'Generator', 'SplStack', 'SplQueue', 'JsonException', 'Stringable',
    ];

    /**
     * Run every check against a repository root and return the problems found.
     *
     * @return string[]
     */
    public static function check(string $repoRoot): array
    {
        return self::checkClaudeMd($repoRoot);
    }

    /**
     * Audit the in-repo CLAUDE.md.
     *
     * @return string[]
     */
    public static function checkClaudeMd(string $repoRoot): array
    {
        $path = rtrim($repoRoot, '/') . '/CLAUDE.md';
        if (!is_file($path)) {
            return ["$path: in-repo CLAUDE.md not found"];
        }

        $text = file_get_contents($path);
        $problems = [];

        foreach (self::iterPhpFences($text) as [$startLine, $code]) {
            $aliases = self::useAliases($code);
            $localClasses = self::localClasses($code);

            // (1) use Tina4\... imports must resolve.
            foreach (self::useImports($code) as [$offset, $fqcn]) {
                if (!str_starts_with($fqcn, 'Tina4\\')) {
                    continue; // only Tina4 imports are ours to verify
                }
                if (!self::typeExists($fqcn)) {
                    $line = $startLine + substr_count($code, "\n", 0, $offset);
                    $problems[] = "CLAUDE.md:$line: `use $fqcn;` -- $fqcn is not a real Tina4 class, interface, or trait";
                }
            }

            // (2) Class::method( calls.
            $pattern = '/(\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)*)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/';
            if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $match) {
                    $base = $match[1][0];
                    $method = $match[2][0];
                    $offset = $match[0][1];
                    $line = $startLine + substr_count($code, "\n", 0, $offset);

                    $short = str_contains($base, '\\')
                        ? substr($base, strrpos($base, '\\') + 1)
                        : ltrim($base, '\\');

                    if (isset($localClasses[$short])
                        || in_array($short, self::EXAMPLE_MODELS, true)
                        || in_array($short, self::PHP_BUILTINS, true)) {
                        continue;
                    }

                    $fqcn = self::resolveBase($base, $short, $aliases);

                    if (!self::typeExists($fqcn)) {
                        $problems[] = "CLAUDE.md:$line: `$base::$method(...)` -- `$base` is not a Tina4 symbol, a fence import, or a declared example model";
                        continue;
                    }

                    $reflection = new ReflectionClass($fqcn);
                    if ($reflection->hasMethod($method)) {
                        continue; // real method -- verified
                    }
                    if ($reflection->hasMethod('__callStatic') || $reflection->hasMethod('__call')) {
                        continue; // magic could answer it; cannot prove drift
                    }
                    $problems[] = "CLAUDE.md:$line: `$fqcn::$method(...)` -- $fqcn has no method `$method`";
                }
            }
        }

        return $problems;
    }

    /**
     * Yield [startLine, code] for every ```php fenced block.
     *
     * @return array<int, array{0:int,1:string}>
     */
    private static function iterPhpFences(string $markdown): array
    {
        $fences = [];
        if (preg_match_all('/```php\b[^\n]*\n(.*?)```/s', $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $codeOffset = $match[1][1];
                $startLine = substr_count($markdown, "\n", 0, $codeOffset) + 1;
                $fences[] = [$startLine, $match[1][0]];
            }
        }
        return $fences;
    }

    /**
     * Map short class name to fully-qualified name for every ``use ...;`` in a
     * fence. Statement form only, so a closure ``function () use ($x)`` is not
     * matched.
     *
     * @return array<string,string>
     */
    private static function useAliases(string $code): array
    {
        $aliases = [];
        foreach (self::useImports($code) as [, $fqcn]) {
            $short = str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;
            $aliases[$short] = $fqcn;
        }
        return $aliases;
    }

    /**
     * Every statement-form ``use Namespace\Class [as Alias];`` in a fence.
     *
     * @return array<int, array{0:int,1:string}>  [offset, fqcn]
     */
    private static function useImports(string $code): array
    {
        $imports = [];
        $pattern = '/\buse\s+(\\\\?[A-Za-z0-9_\\\\]+)(?:\s+as\s+[A-Za-z0-9_]+)?\s*;/';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $imports[] = [$match[0][1], ltrim($match[1][0], '\\')];
            }
        }
        return $imports;
    }

    /**
     * Class names declared inside the fence (``class Foo``) -- application code,
     * not a framework API to verify.
     *
     * @return array<string,bool>
     */
    private static function localClasses(string $code): array
    {
        $local = [];
        if (preg_match_all('/\bclass\s+([A-Za-z0-9_]+)/', $code, $matches)) {
            foreach ($matches[1] as $name) {
                $local[$name] = true;
            }
        }
        return $local;
    }

    /**
     * Resolve a ``Class::`` base to a fully-qualified name: a fully-qualified
     * spelling wins, then a fence ``use`` alias, then the ``Tina4\`` namespace.
     */
    private static function resolveBase(string $base, string $short, array $aliases): string
    {
        if (str_contains($base, '\\')) {
            return ltrim($base, '\\');
        }
        if (isset($aliases[$short])) {
            return $aliases[$short];
        }
        return 'Tina4\\' . $short;
    }

    private static function typeExists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
    }
}

// ------------------------------------------------------------------ CLI ---
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $repoRoot = dirname(__DIR__);
    require $repoRoot . '/vendor/autoload.php';

    $strict = in_array('--strict', $argv, true);
    $problems = Tina4DocDriftAudit::check($repoRoot);

    if ($problems !== []) {
        fwrite(STDERR, 'Doc-drift audit: ' . count($problems) . " problem(s) found:\n\n");
        foreach ($problems as $problem) {
            fwrite(STDERR, "  - $problem\n");
        }
        fwrite(STDERR, "\nFix the docs to match the code (or the code to match the docs).\n");
        exit($strict ? 1 : 0);
    }

    echo "Doc-drift audit: clean -- every documented API resolves against the live code.\n";
    exit(0);
}
