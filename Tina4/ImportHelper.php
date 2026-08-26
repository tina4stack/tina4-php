<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Last-resort autoloader that HELPS a caller who asked for a Tina4 class that
 * does not exist — the common AI-agent failure of grabbing the wrong casing
 * (`Tina4\Route` vs `Tina4\Router`, `Tina4\Api` vs `Tina4\API`) or an invented
 * name.
 *
 * Registered AFTER Composer's PSR-4 loader (append, never prepend), so it fires
 * only when every earlier autoloader has already declined the class. It then
 * emits a helpful "Did you mean X?" hint to error_log() naming the real Tina4
 * class the missing short name most resembles (levenshtein-ranked), and
 * returns SILENTLY — PHP autoloaders MUST NOT throw (see handle() docblock).
 *
 *   [Tina4] Class 'Tina4\Route' not found. Did you mean 'Tina4\Router'?
 *
 *   [Tina4] Class 'Tina4\Zzz' not found. No close match in the Tina4
 *   namespace; real classes include: Tina4\Router, Tina4\ServiceRunner,
 *   Tina4\Auth, ...
 *
 * SCOPED to the `Tina4\` namespace. A class in any other namespace falls
 * through untouched — a legitimate PSR-4 miss elsewhere (e.g. a `use
 * NonExistent\NotAClass` inside a real Tina4\ class) still produces its own
 * ORIGINAL "Class 'X' not found" from PHP, never masked by our hint.
 */

namespace Tina4;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class ImportHelper
{
    /**
     * True once install() has registered the last-resort callback.
     *
     * install() is idempotent: repeated calls are a no-op so that boot,
     * hot-reload, and re-boot inside a test all leave exactly ONE callback
     * registered. Guarded by this static and by an spl_autoload_functions()
     * membership check for the same singleton closure.
     */
    private static bool $installed = false;

    /**
     * The one closure registered with spl_autoload_register.
     *
     * Kept as a class-level singleton so a duplicate install() call has a
     * stable value to compare against with spl_autoload_functions().
     */
    private static ?\Closure $callback = null;

    /**
     * Cached FQCN list under the Tina4/ tree, computed once on first miss.
     *
     * Walking the tree is real disk I/O; caching means the second miss is a
     * cheap in-memory scan. Reset by resetCache() (test-only).
     *
     * @var list<string>|null
     */
    private static ?array $knownClasses = null;

    /**
     * Register the last-resort autoloader (idempotent).
     *
     * Wired eagerly by composer's `files` autoload map via
     * Tina4/Bootstrap/Constants.php — no application code has to call this by
     * hand. Registered with prepend=false so Composer's PSR-4 loader still
     * gets first crack at every class; our callback only sees classes NOBODY
     * else could resolve.
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$callback ??= static function (string $className): void {
            self::handle($className);
        };
        // prepend=false: run AFTER composer's PSR-4 loader has already failed.
        // The $throw parameter is deprecated in PHP 8.0+ (always throws on an
        // invalid callable), but harmless — the positional signature stays
        // the most portable across PHP 8.2+.
        spl_autoload_register(self::$callback, true, false);
        self::$installed = true;
    }

    /**
     * True when this class's callback is registered.
     *
     * Membership check runs against spl_autoload_functions() so a stale
     * static after opcache reset can't lie about registration.
     */
    public static function isInstalled(): bool
    {
        if (!self::$installed || self::$callback === null) {
            return false;
        }
        foreach (spl_autoload_functions() ?: [] as $registered) {
            if ($registered === self::$callback) {
                return true;
            }
        }
        return false;
    }

    /**
     * How many copies of this class's callback are on the autoload stack.
     *
     * Test hook — a second install() call must not add a second registration.
     */
    public static function registrationCount(): int
    {
        if (self::$callback === null) {
            return 0;
        }
        $count = 0;
        foreach (spl_autoload_functions() ?: [] as $registered) {
            if ($registered === self::$callback) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Drop the cached FQCN list (test-only reset hook).
     *
     * A test that adds/removes files under Tina4/ must invalidate the cache
     * for the follow-up miss to see the new state.
     */
    public static function resetCache(): void
    {
        self::$knownClasses = null;
    }

    /**
     * The last-resort miss handler — emits a helpful hint to error_log().
     *
     * Bounded to the `Tina4\` namespace so a non-Tina4 miss (e.g. a `use
     * NonExistent\NotAClass` inside a Tina4 class) is NOT intercepted —
     * PHP's native "Class 'X' not found" fatal wins, unmasked.
     *
     * PHP autoloaders MUST NOT throw. A throw from inside
     * spl_autoload_register breaks `class_exists('X', true)` (that call is
     * supposed to return false when nobody can load the class) and any
     * snippet that catches "class not found". Instead we write the hint to
     * error_log (visible in server logs and in phpunit output) and return
     * silently. If the caller genuinely needed the class (new X, extends X),
     * PHP's own "Class 'X' not found" fatal fires cleanly afterwards.
     */
    private static function handle(string $className): void
    {
        // Bounded: intervene only for classes in the framework namespace.
        // Nothing raised for a foreign namespace — PHP's own fatal wins.
        if (!str_starts_with($className, 'Tina4\\')) {
            return;
        }

        $known = self::knownClasses();
        $shortName = self::shortName($className);

        // A real class the walker DID find, but composer's PSR-4 couldn't
        // load — a broken file, a wrong namespace declaration inside the
        // file, etc. Say so plainly rather than pretending the class is
        // missing.
        if (in_array($className, $known, true)) {
            // PHP autoloaders MUST NOT throw — see the docblock above.
            error_log(sprintf(
                "[Tina4] Class '%s' exists on disk at Tina4/ but could not be autoloaded — check the namespace declaration inside the file and the composer PSR-4 map.",
                $className
            ));
            return;
        }

        $suggestions = self::closestByShortName($shortName, $known);

        if ($suggestions !== []) {
            if (count($suggestions) === 1) {
                $message = sprintf(
                    "Class '%s' not found. Did you mean '%s'?",
                    $className,
                    $suggestions[0]
                );
            } else {
                $message = sprintf(
                    "Class '%s' not found. Did you mean one of: %s?",
                    $className,
                    implode(', ', array_map(static fn(string $s): string => "'{$s}'", $suggestions))
                );
            }
        } else {
            // No close match — hand back a small browsable sample so the
            // caller can see the actual naming shape instead of guessing again.
            $sample = self::browsableSample($known, 5);
            $message = sprintf(
                "Class '%s' not found. No close match in the Tina4 namespace; real classes include: %s%s",
                $className,
                implode(', ', $sample),
                count($known) > count($sample) ? ', ...' : ''
            );
        }

        // PHP autoloaders MUST NOT throw — throwing here breaks class_exists()
        // checks and any script that catches "class not found". Emit the hint
        // to error_log (visible in logs and in phpunit output) and return
        // silently. If the caller genuinely needed the class (new X, extends X),
        // PHP's own "Class 'X' not found" fatal fires cleanly afterward.
        error_log('[Tina4] ' . $message);
    }

    /**
     * Every FQCN discovered under the Tina4/ tree, computed once and cached.
     *
     * A file at `Tina4/Foo/Bar.php` maps to `Tina4\Foo\Bar` via PSR-4.
     * The Tina4/Bootstrap/ files are eagerly loaded via composer's `files`
     * map — they DECLARE free functions / constants, not classes, so they
     * are excluded from the walker (a stray `class` in a bootstrap file
     * would still map cleanly, but its presence in the FQCN list would be
     * misleading).
     *
     * @return list<string>
     */
    private static function knownClasses(): array
    {
        if (self::$knownClasses !== null) {
            return self::$knownClasses;
        }

        $root = self::tina4Root();
        if ($root === null || !is_dir($root)) {
            return self::$knownClasses = [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $files = new RegexIterator($iterator, '/\.php$/');
        foreach ($files as $file) {
            $absolute = $file->getPathname();
            $relative = substr($absolute, strlen($root) + 1);
            // Bootstrap files declare free functions/constants; exclude them.
            if (str_starts_with($relative, 'Bootstrap' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            // Any hand-rolled fixture under Tina4/ that starts with _ is a
            // test-only fixture — keep it in the map for the exists-on-disk
            // branch to catch, but avoid suggesting it as a "did you mean".
            $classes[] = 'Tina4\\' . str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                substr($relative, 0, -4)
            );
        }
        sort($classes);
        return self::$knownClasses = $classes;
    }

    /**
     * Rank real Tina4 short names by levenshtein distance to $shortName,
     * return up to three within a tight budget.
     *
     * Budget: distance <= min(4, floor(strlen(shortName)/2)) — matches the
     * Python master's cutoff. `RouteZ` -> `Router` (distance 1) suggests;
     * `Zzzzz` -> `Router` (distance 5+) does not.
     *
     * Skips fixtures whose short name starts with `_` — they exist on disk
     * so the exists-branch of handle() reports them, but they should not be
     * offered as a suggested spelling.
     *
     * @param list<string> $known
     * @return list<string> up to 3 FQCNs
     */
    private static function closestByShortName(string $shortName, array $known): array
    {
        if ($shortName === '' || $known === []) {
            return [];
        }
        $budget = min(4, max(1, (int)floor(strlen($shortName) / 2)));
        $ranked = [];
        foreach ($known as $fqcn) {
            $candidate = self::shortName($fqcn);
            if ($candidate === '' || str_starts_with($candidate, '_')) {
                continue;
            }
            $distance = levenshtein(strtolower($shortName), strtolower($candidate));
            if ($distance <= $budget) {
                $ranked[] = [$distance, $fqcn];
            }
        }
        if ($ranked === []) {
            return [];
        }
        usort($ranked, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]);
        });
        return array_slice(array_map(static fn(array $row): string => $row[1], $ranked), 0, 3);
    }

    /**
     * A short sample of real Tina4 classes for the no-close-match message.
     *
     * Preference is given to top-level `Tina4\Foo` classes over deeply
     * nested ones — those are the names a caller is most likely to want
     * next when they had no idea what to type.
     *
     * @param list<string> $known
     * @return list<string>
     */
    private static function browsableSample(array $known, int $limit): array
    {
        $topLevel = [];
        foreach ($known as $fqcn) {
            $short = self::shortName($fqcn);
            if ($short === '' || str_starts_with($short, '_')) {
                continue;
            }
            if (substr_count($fqcn, '\\') === 1) {
                $topLevel[] = $fqcn;
            }
        }
        sort($topLevel);
        return array_slice($topLevel, 0, $limit);
    }

    /**
     * Absolute path of the Tina4/ source root, or null when this file has
     * been relocated outside the tree.
     */
    private static function tina4Root(): ?string
    {
        $root = __DIR__;
        return is_dir($root) ? $root : null;
    }

    /**
     * The last namespace segment of an FQCN — `Tina4\Foo\Bar` -> `Bar`.
     */
    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
