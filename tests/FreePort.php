<?php

// Global namespace, like PgTestEnv, AppTestSupport and TestServer: this repo
// maps PSR-4 Tina4\ -> Tina4/, so a Tina4\Tests\... class here would never
// autoload. Required from tests/bootstrap.php.

/**
 * One place to get a port for a test server.
 *
 * Replaces two things that were live sources of flakiness:
 *
 *  1. `BASE + (getmypid() % N)` - a DETERMINISTIC port. The same pid always
 *     picks the same one, the range is small, and the ranges used overlapped
 *     Tina4's own default serve ports (7145/7146 and +1000). It collided for
 *     real on 2026-07-31: an unrelated process held 7147, phpunit's pid mapped
 *     to 7147, `php -S` still printed "Development Server started", and
 *     LegacyEnvGuardTest then asserted against the server's banner instead of
 *     the migration message. The same test passed 5/5 in isolation. Cost: a
 *     3.5-minute suite re-run, and it can mask a real failure.
 *
 *  2. Scanning a fixed range with `fsockopen` until one refuses to connect.
 *     A refused connection only proves nothing was LISTENING at that instant;
 *     the port can be taken between the probe and the real bind. Asking the OS
 *     for one is both simpler and stronger.
 *
 * Five near-identical copies of this existed across the suite (TestServer,
 * BackgroundOverlapTest, ModelDiscoveryTest, SSEWireTest, WebSapiEntryTest).
 * They are now one.
 *
 * HONEST LIMIT: this reserves and releases, so a race window remains between
 * the close here and the server's bind. Nothing that returns a port NUMBER can
 * close that window - the airtight form is to bind port 0 and read back what
 * the OS gave you, which needs the server to cooperate. This is dramatically
 * better than a pid modulo because the OS avoids immediately reusing a port it
 * just released, and because the pool is the whole ephemeral range rather than
 * a few hundred values that collide with the framework's own defaults.
 */
final class FreePort
{
    /**
     * A TCP port on 127.0.0.1 that was free a moment ago.
     *
     * @throws RuntimeException when the OS will not hand one out - loud, because
     *                          a silent fallback to a guessed port is how the
     *                          flakiness above got in.
     */
    public static function get(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("could not reserve a free port: {$errstr} ({$errno})");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $colon = strrpos($name, ':');
        if ($colon === false) {
            throw new RuntimeException("could not read the port back from '{$name}'");
        }

        return (int) substr($name, $colon + 1);
    }
}
