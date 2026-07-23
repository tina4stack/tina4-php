<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * File-based ORM model discovery — scans src/orm/ and loads what it finds.
 *
 * Convention:
 *   src/orm/Product.php            → class Product extends \Tina4\ORM
 *   src/orm/billing/Invoice.php    → class Invoice extends \Tina4\ORM
 *
 * Location IS configuration: dropping a model file into src/orm/ registers it,
 * with no require_once in the route and no composer.json edit. Routes, seeds
 * and services were already discovered by convention; this closes the gap for
 * models, matching the Python master, which imports every module under src/ at
 * boot (tina4_python/core/server.py::_discover).
 *
 * Loading is EAGER (require_once), not lazy autoloading, for the same reason
 * Python imports eagerly: a model file's side effects — scopes, AutoCrud
 * registration, event listeners — have to have happened by the time the first
 * request is served, and `class_exists('Product', false)` must be true.
 *
 * Skipped: files and directories whose name starts with "_" or "." (private or
 * partial scaffolding, Python parity) and anything that is not a .php file.
 */
class ModelDiscovery
{
    /**
     * Files already loaded by a previous scan, so scan() is idempotent.
     *
     * @var array<string, true>
     */
    private static array $seenFiles = [];

    /**
     * Last-seen modification time per file (filepath => mtime).
     *
     * @var array<string, int>
     */
    private static array $lastMtimes = [];

    /** Last directory passed to scan(), so rescan() needs no argument. */
    private static string $lastModelsDir = '';

    /**
     * Re-run the most recent scan — used by the dev reload endpoint so a model
     * file added while the server runs registers without a restart. No-op if
     * scan() has never been called.
     *
     * @return array<int, array{class: string, file: string}> Newly loaded models
     */
    public static function rescan(): array
    {
        if (self::$lastModelsDir === '') {
            return [];
        }
        return self::scan(self::$lastModelsDir);
    }

    /**
     * Reset the seen-files state. Test-only — production code should treat
     * ModelDiscovery as a process-level singleton.
     */
    public static function reset(): void
    {
        self::$seenFiles = [];
        self::$lastMtimes = [];
        self::$lastModelsDir = '';
    }

    /**
     * Scan a directory recursively and load every model file in it.
     *
     * Each file is loaded once with require_once, so a model that is ALSO
     * pulled in elsewhere (a generated test's require, a composer
     * `autoload.files` entry) can never be declared twice. A file that raises
     * while loading is logged LOUD and recorded to data/.broken — the
     * remaining models still load and the server still boots, because one bad
     * model must not take the whole application down. A COMPILE-level fault
     * (a parse error, or a property that conflicts with the ORM base class) is
     * a PHP fatal that no catch block can intercept, so it still stops the
     * boot with the file and line named — exactly as it does for a route file.
     *
     * Re-running is cheap and safe: unchanged files are skipped. An EDITED
     * model cannot be re-declared in a live PHP process, so a changed file is
     * logged with a restart hint rather than re-included (mirrors the
     * RouteDiscovery policy for inline route files with top-level
     * declarations).
     *
     * @param string $modelsDir The directory to scan (e.g. src/orm)
     * @return array<int, array{class: string, file: string}> Newly loaded ORM models
     */
    public static function scan(string $modelsDir): array
    {
        $discovered = [];

        if (!is_dir($modelsDir)) {
            return $discovered;
        }

        // Read fresh mtimes — PHP caches stat() results, and a same-second
        // rewrite would otherwise look unchanged.
        clearstatcache();

        $modelsDir = rtrim(str_replace('\\', '/', $modelsDir), '/');
        self::$lastModelsDir = $modelsDir;

        foreach (self::findModelFiles($modelsDir) as $file) {
            $mtime = self::mtimeOf($file);

            if (isset(self::$seenFiles[$file])) {
                if ($mtime > (self::$lastMtimes[$file] ?? 0)) {
                    // Honest caveat: re-including would fatal with "cannot
                    // redeclare class". Record the mtime so we stop reporting
                    // it, and tell the developer what to do.
                    self::$lastMtimes[$file] = $mtime;
                    Log::warning(
                        "Model file changed but a PHP class cannot be redeclared in a running process: {$file}. " .
                        "Restart the server to pick up its changes."
                    );
                }
                continue;
            }

            $before = get_declared_classes();

            try {
                require_once $file;
            } catch (\Throwable $e) {
                Log::error("Failed to load model file {$file}: " . $e->getMessage());
                RouteDiscovery::recordBrokenImport($file, $e);
                // Mark it seen so a boot loop doesn't retry it every rescan.
                self::$seenFiles[$file] = true;
                self::$lastMtimes[$file] = $mtime;
                continue;
            }

            self::$seenFiles[$file] = true;
            self::$lastMtimes[$file] = $mtime;

            foreach (array_diff(get_declared_classes(), $before) as $class) {
                if (is_subclass_of($class, ORM::class)) {
                    $discovered[] = ['class' => $class, 'file' => $file];
                    Log::debug("Discovered model: {$class}");
                }
            }
        }

        return $discovered;
    }

    /**
     * Recursively collect the model files in a directory, in a stable order.
     *
     * Names starting with "_" or "." are skipped — private or partial
     * scaffolding, the same rule the Python master applies to src/.
     *
     * @return array<int, string> Absolute paths, sorted
     */
    private static function findModelFiles(string $dir): array
    {
        $files = [];

        $items = scandir($dir);
        if ($items === false) {
            return $files;
        }
        sort($items);

        foreach ($items as $item) {
            if ($item === '' || $item[0] === '.' || $item[0] === '_') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $files = array_merge($files, self::findModelFiles($path));
            } elseif (str_ends_with($item, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /** Fresh mtime for a file, or 0 if it can't be read. */
    private static function mtimeOf(string $file): int
    {
        $mtime = @filemtime($file);
        return $mtime === false ? 0 : $mtime;
    }
}
