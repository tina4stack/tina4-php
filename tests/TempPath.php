<?php

// Global namespace, like PgTestEnv, AppTestSupport, FreePort and TestServer:
// this repo maps PSR-4 Tina4\ -> Tina4/, so a Tina4\Tests\... class here would
// never autoload. Required from tests/bootstrap.php.

/**
 * One place to get a temp path that does not leak.
 *
 * MEASURED on the lab 2026-08-06, in /tmp on the host that runs the suites:
 *
 *     888  tina4_rowcap_      592  tina4_sq_main_     592  tina4_sq_att_
 *     370  tina4_popjob_      200  tina4-rs256-       195  tina4-service-test-
 *     117  tina4-test-sessions-  111  tina4_fetch_all_   ... and more
 *
 * Roughly 2,700 orphaned files and 840 orphaned directories, growing on every
 * run. /tmp looks the same as it fills, so nothing ever surfaced it.
 *
 * TWO DISTINCT DEFECTS PRODUCED THAT, and only one of them is "forgot to clean
 * up".
 *
 * 1. THE tempnam() SUFFIX ORPHAN, which is the bigger half and is invisible at
 *    the call site. Seventeen places wrote (spelled out here rather than shown
 *    verbatim, because a sweep over tests/ will happily rewrite an example of
 *    the bug inside the very docblock explaining it -- mine did):
 *
 *        $file = tempnam( sys_get_temp_dir(), 'tina4_rowcap_' ) . '.db';
 *
 *    tempnam() does not return a name, it CREATES a file and returns its path.
 *    Concatenating '.db' then produces a DIFFERENT path, so the file tempnam
 *    actually created is never referred to again. The tearDowns were not even
 *    wrong -- they unlinked $file faithfully, and the 0-byte original stayed
 *    behind every single time. That is why these entries are all `-rw-------`
 *    and 0 bytes: they are tempnam's own files, not the databases.
 *
 * 2. Directories built by hand from sys_get_temp_dir() with no removal at all
 *    (tina4_popjob_, tina4-service-test-, tina4-test-sessions-, tina4_devsecret_,
 *    tina4_qclose_).
 *
 * Both are closed here rather than at seventeen-plus call sites, so a new test
 * cannot reintroduce either by copying the old idiom.
 *
 * reap() is registered as a shutdown function by tests/bootstrap.php, so a test
 * that forgets its own tearDown still cannot leak. Per-test cleanup is still
 * welcome and still works -- reap() is idempotent and silent about anything
 * already gone.
 */
final class TempPath
{
    /** @var array<int,string> Every path handed out and not yet removed. */
    private static array $created = [];

    /**
     * A unique temp FILE path, optionally with an extension, leaving nothing
     * behind.
     *
     * The suffix exists because callers need `.db` / `.php` for SQLite and for
     * `include`, and tempnam() cannot give you one. Uniqueness still comes from
     * tempnam: the returned base is unique, so base+suffix is unique too. The
     * base file is removed here, which is the whole point -- it is the thing
     * that was being orphaned.
     *
     * @param string $prefix Name prefix, e.g. 'tina4_rowcap_'
     * @param string $suffix Extension including the dot, e.g. '.db'; '' for none
     * @return string Path to use. NOT created when a suffix is given (the
     *                caller creates it), created and empty when it is not.
     */
    public static function file(string $prefix, string $suffix = ''): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new \RuntimeException("could not create a temp file with prefix {$prefix}");
        }

        if ($suffix === '') {
            self::$created[] = $base;
            return $base;
        }

        // tempnam CREATED $base. We are about to use a different path, so this
        // one has no owner -- remove it now rather than leave the 0-byte file
        // that put 888 tina4_rowcap_ entries in /tmp.
        @unlink($base);

        $path = $base . $suffix;
        self::$created[] = $path;
        return $path;
    }

    /**
     * A unique temp DIRECTORY, created and tracked for removal.
     *
     * $mode exists so the two call sites that asked for a specific one (0777 in
     * DevSecretTest, 0755 in ServiceRunnerV3Test) keep exactly the permissions
     * they had. Tightening them to 0700 would almost certainly have been fine
     * and is not worth finding out in a suite that spawns child processes.
     *
     * @param string $prefix Name prefix, e.g. 'tina4_popjob_'
     * @param int    $mode   Directory permissions (default 0700)
     */
    public static function dir(string $prefix, int $mode = 0700): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("could not create a temp directory at {$path}");
        }
        self::$created[] = $path;
        return $path;
    }

    /**
     * Remove everything handed out that is still present.
     *
     * NEVER THROWS. Cleanup that can fail a run is worse than the leak it
     * fixes: some of these paths are chmod'd or held open by the test that
     * asked for them, and a reaper that raised would turn a green suite red for
     * a reason with nothing to do with the behaviour under test.
     */
    public static function reap(): void
    {
        foreach (self::$created as $path) {
            self::remove($path);
        }
        self::$created = [];
    }

    /**
     * Files SQLite creates BESIDE the database it was given.
     *
     * Measured while verifying this class: with the .db files reaped, six
     * entries still survived a run -- tina4_fetch_all_*.db-shm and .db-wal.
     * A WAL-mode connection writes those two next to the database under names
     * the caller never sees, so tracking the path handed out is not the same as
     * tracking what ends up on disk. -journal is the rollback-mode equivalent.
     */
    private const SQLITE_SIDECARS = ['-shm', '-wal', '-journal'];

    /** Recursive, best-effort removal of one path. */
    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $entries = @scandir($path);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    self::remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($path);
            return;
        }

        // Unconditionally, and that is the point: the sidecars are swept even
        // when the database itself is ALREADY GONE. The first version returned
        // early on "not a file", so a test whose own tearDown unlinked the .db
        // before shutdown left .db-shm and .db-wal behind forever -- which is
        // exactly what the measurement showed, six survivors per run after the
        // .db files were being reaped correctly.
        @unlink($path);
        foreach (self::SQLITE_SIDECARS as $sidecar) {
            @unlink($path . $sidecar);
        }
    }
}
