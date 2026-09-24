<?php
/* Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
namespace Tina4;

/** @internal Credential updates without following links or publishing public bytes. */
final class SecretFile
{
    private static function target(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) return null;
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new \RuntimeException('Credential target must be a single-link regular file');
        }
        return $stat;
    }

    private static function same(?array $a, ?array $b): bool
    {
        return $a === null ? $b === null : $b !== null && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }

    public static function update(string $path, callable $transform): void
    {
        $before = self::target($path);
        $input = null; $output = null; $temp = null;
        try {
            $content = '';
            if ($before !== null) {
                $input = @fopen($path, 'rb');
                if ($input === false || !flock($input, LOCK_EX)) throw new \RuntimeException('Could not open credential file');
                $opened = fstat($input);
                if (!self::same($before, $opened) || !self::same($before, self::target($path)) || $opened['nlink'] !== 1) {
                    throw new \RuntimeException('Credential file changed while opening');
                }
                $content = stream_get_contents($input);
                if ($content === false) throw new \RuntimeException('Could not read credential file');
            }
            $updated = $transform($content);
            // PHP core has no portable fchmod/O_NOFOLLOW. Publish a freshly created
            // owner-only inode instead of chmod/truncate through a pathname.
            $temp = dirname($path) . '/.tina4-secret-' . bin2hex(random_bytes(16));
            $mask = umask(0077);
            try { $output = @fopen($temp, 'x+b'); } finally { umask($mask); }
            if ($output === false) throw new \RuntimeException('Could not create private credential file');
            $stat = fstat($output);
            if (($stat['mode'] & 0777) !== 0600 && PHP_OS_FAMILY !== 'Windows') {
                throw new \RuntimeException('Credential file is not owner-only');
            }
            for ($offset = 0; $offset < strlen($updated); $offset += $written) {
                $written = fwrite($output, substr($updated, $offset));
                if ($written === false || $written === 0) throw new \RuntimeException('Could not write credential file');
            }
            if (!fflush($output)) throw new \RuntimeException('Could not flush credential file');
            if (!self::same($before, self::target($path))) throw new \RuntimeException('Credential file changed while updating');
            if (!self::same($stat, self::target($temp))) throw new \RuntimeException('Private credential file changed');
            // link is an atomic no-replace publication for a previously missing file.
            $published = $before === null ? @link($temp, $path) : @rename($temp, $path);
            if (!$published) throw new \RuntimeException('Could not publish private credential file');
        } finally {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            if ($temp !== null && is_file($temp)) @unlink($temp);
        }
    }
}
