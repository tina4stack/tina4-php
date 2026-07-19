<?php

/**
 * Tina4 PHP — v3.13.12 large-response write loop (no truncation).
 *
 * Regression test for the non-blocking socket truncation bug: a response
 * larger than the OS send buffer (~200KB-1MB) was silently cut off because
 * the write loop treated `fwrite() === 0` (EAGAIN, "buffer full, try again")
 * as a closed socket and `break`ed mid-body. A ~4MB attachment download
 * returned 200 + correct Content-Length but only ~1-2MB of body; nginx
 * logged "upstream prematurely closed connection while reading upstream".
 *
 * The fix (Server::writeFully) waits for socket writability and retries on
 * a 0 return, only giving up on a real error (`false`) or a stalled client.
 *
 * Reproduction needs a SLOW reader to force the send buffer to fill, which
 * needs a second process — hence pcntl_fork. Where pcntl is unavailable the
 * forked test self-skips; the small-payload delivery test always runs.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class ServerLargeResponseTest extends TestCase
{
    /** Invoke the private Server::writeFully via reflection. */
    private function writeFully(Server $server, $socket, string $data): int
    {
        $ref = new ReflectionMethod(Server::class, 'writeFully');
        return $ref->invoke($server, $socket, $data);
    }

    /**
     * Small payload (fits in the buffer in one shot) is delivered intact.
     * Always runs — proves the loop delivers in full and returns the byte
     * count, with no regression on the simple path.
     */
    public function testSmallPayloadDeliveredInFull(): void
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }
        [$writeEnd, $readEnd] = $pair;
        stream_set_blocking($writeEnd, false);

        $server = new Server();
        $payload = str_repeat('A', 4096);
        $written = $this->writeFully($server, $writeEnd, $payload);

        $this->assertSame(strlen($payload), $written, 'writeFully must report full byte count');

        fclose($writeEnd);
        $received = stream_get_contents($readEnd);
        fclose($readEnd);

        $this->assertSame($payload, $received, 'small payload must arrive intact');
    }

    /**
     * The actual bug: a 4MB payload through a deliberately slow reader.
     * Pre-v3.13.12 this truncated at the send-buffer boundary. The fork
     * gives us a concurrent slow reader so the parent's send buffer fills
     * and writeFully must wait-and-retry rather than bail.
     */
    public function testLargePayloadSurvivesFullSendBuffer(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available — cannot drive a concurrent slow reader');
        }
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }
        [$writeEnd, $readEnd] = $pair;

        $size = 4 * 1024 * 1024; // 4MB — matches the field report
        $payload = str_repeat('x', $size);

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($writeEnd);
            fclose($readEnd);
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            // ── Child: deliberately stalled reader.
            // @codeCoverageIgnoreStart
            fclose($writeEnd);
            stream_set_blocking($readEnd, true);

            // Stall up front: do NOT read for 250ms. This lets the parent's
            // send buffer fill COMPLETELY and stay full. Under the old loop,
            // the parent's fwrite() then returns exactly 0 and it break()s —
            // truncating the body. Under the fix, writeFully() waits on
            // writability (up to 5s) and resumes once we start draining.
            usleep(250000);

            $received = 0;
            while (!feof($readEnd)) {
                $chunk = fread($readEnd, 65536);
                if ($chunk === false || $chunk === '') {
                    usleep(1000);
                    continue;
                }
                $received += strlen($chunk);
            }
            fclose($readEnd);
            // Exit 0 only if every byte arrived.
            exit($received === $size ? 0 : 1);
            // @codeCoverageIgnoreEnd
        }

        // ── Parent: writer.
        fclose($readEnd);
        stream_set_blocking($writeEnd, false);

        $server = new Server();
        $written = $this->writeFully($server, $writeEnd, $payload);

        // Closing signals EOF so the child stops reading and exits.
        fclose($writeEnd);

        $status = 0;
        pcntl_waitpid($pid, $status);

        $this->assertSame($size, $written, 'writeFully must write all 4MB, not stop at the buffer boundary');
        $this->assertSame(0, pcntl_wexitstatus($status), 'reader must receive all 4MB intact (no truncation)');
    }
}
