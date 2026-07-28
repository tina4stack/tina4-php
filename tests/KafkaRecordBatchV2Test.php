<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\Queue\KafkaBackend;

/**
 * Lock-in tests for the Kafka record format v2 encoder.
 *
 * The bug these pin down: the client spoke Produce v0 / Fetch v0, which Kafka 4.x
 * REMOVED along with message formats v0/v1. A removed version is not answered with
 * an error code -- the broker logs
 *   UnsupportedVersionException: Received request for api with key 0 (Produce)
 *   and unsupported version 0
 * and CLOSES THE SOCKET. The client saw a bare EOF, so the symptom
 * ("Failed to read Kafka response length") named neither the cause nor the fix.
 * That is why a plain "does produce work" test is not enough: these assert the
 * WIRE-LEVEL invariants that make it work, so a regression is caught at the byte
 * level instead of as a mystery disconnect.
 *
 * The encoder is a pure function over its inputs -- no socket, no broker, no
 * doubles -- so these are unit tests in the sense the no-mock rule allows. The
 * live round trip against a real broker lives in KafkaIntegrationTest.
 */
final class KafkaRecordBatchV2Test extends TestCase
{
    private static function method(string $name): ReflectionMethod
    {
        // PHP 8.1+ makes private methods reflectively callable without
        // setAccessible(), which is deprecated in 8.5.
        return (new ReflectionClass(KafkaBackend::class))->getMethod($name);
    }

    // ── API versions: the actual bug ─────────────────────────────────

    /**
     * NEGATIVE: the versions must never fall back below what Kafka 4.x accepts.
     *
     * A regression here does not fail loudly -- it makes the broker hang up mid
     * conversation -- so it has to be asserted, not assumed.
     */
    public function testProduceAndFetchVersionsAreNotTheRemovedOnes(): void
    {
        $c = new ReflectionClass(KafkaBackend::class);
        $produce = $c->getConstant('PRODUCE_VERSION');
        $fetch = $c->getConstant('FETCH_VERSION');

        self::assertGreaterThanOrEqual(
            3,
            $produce,
            'Produce v0-v2 carry message format v0/v1, which Kafka 4.x REMOVED. '
            . 'A broker answers those by closing the socket, not by erroring.'
        );
        self::assertGreaterThanOrEqual(
            4,
            $fetch,
            'Fetch v0-v3 were removed with the old message formats; Kafka 4.3 '
            . 'advertises "Fetch(1): 4 to 18".'
        );
    }

    // ── CRC32C, not CRC32 ────────────────────────────────────────────

    /**
     * NEGATIVE: record format v2 needs CRC32C (Castagnoli), NOT the CRC32 that
     * format v0 used and that PHP's crc32() computes. Swapping them builds a batch
     * the encoder accepts and the broker silently rejects, so pin the algorithm to
     * its published test vector.
     */
    public function testCrc32cIsCastagnoliNotIsoHdlc(): void
    {
        self::assertSame(
            'e3069283',
            hash('crc32c', '123456789'),
            'CRC32C of "123456789" is e3069283 (published vector)'
        );
        self::assertNotSame(
            hash('crc32c', '123456789'),
            dechex(crc32('123456789')),
            'crc32() is ISO-HDLC and must NOT be used for a v2 batch'
        );
    }

    // ── Varints ──────────────────────────────────────────────────────

    /**
     * POSITIVE: zigzag LEB128 against the published vectors. -1 (the null
     * key/value sentinel) must cost ONE byte; get zigzag wrong and it costs ten,
     * which shifts every following field.
     */
    public function testVarintMatchesTheZigzagVectors(): void
    {
        $varint = self::method('varint');
        $vectors = [
            0 => '00', -1 => '01', 1 => '02', -2 => '03',
            2 => '04', 63 => '7e', 64 => '8001', -64 => '7f',
        ];
        foreach ($vectors as $n => $hex) {
            self::assertSame($hex, bin2hex($varint->invoke(null, (int)$n)), "varint({$n})");
        }
    }

    /** POSITIVE: encode/decode round-trips across the int32 range and the sentinel. */
    public function testVarintRoundTrips(): void
    {
        $varint = self::method('varint');
        $read = self::method('readVarint');

        foreach ([0, -1, 1, -2, 300, -300, 65535, -65535, 2147483647, -2147483648] as $n) {
            $buf = $varint->invoke(null, $n);
            $pos = 0;
            self::assertSame($n, $read->invokeArgs(null, [$buf, &$pos]), "round-trip {$n}");
            self::assertSame(strlen($buf), $pos, "consumed every byte for {$n}");
        }
    }

    // ── Batch layout ─────────────────────────────────────────────────

    /**
     * POSITIVE: the batch header lands where the spec says.
     *
     * These fixed offsets are exactly what decodeFirstRecord() reads back, so an
     * encoder change that shifts them shows up here rather than as a broker
     * disconnect.
     */
    public function testRecordBatchHeaderLayout(): void
    {
        $encode = self::method('encodeRecordBatch');
        $batch = $encode->invoke(new KafkaBackend([]), 'k1', '{"v":1}');

        self::assertGreaterThan(61, strlen($batch), 'a v2 batch header alone is 61 bytes');
        self::assertSame(0, unpack('J', substr($batch, 0, 8))[1], 'baseOffset');
        self::assertSame(
            strlen($batch) - 12,
            unpack('N', substr($batch, 8, 4))[1],
            'batchLength counts everything after itself'
        );
        self::assertSame(2, ord($batch[16]), 'magic byte MUST be 2 (record format v2)');
        self::assertSame(0, unpack('n', substr($batch, 21, 2))[1], 'attributes: no compression');
        self::assertSame(1, unpack('N', substr($batch, 57, 4))[1], 'record count');
    }

    /**
     * NEGATIVE: the CRC in the batch must actually verify. A wrong range (or a
     * wrong algorithm) still produces four plausible bytes, so recompute over the
     * region the spec defines -- everything AFTER the crc field -- and compare.
     */
    public function testRecordBatchCrcCoversEverythingAfterIt(): void
    {
        $encode = self::method('encodeRecordBatch');
        $batch = $encode->invoke(new KafkaBackend([]), 'k1', '{"v":1}');

        $crcInBatch = bin2hex(substr($batch, 17, 4));
        $covered = substr($batch, 21);                 // attributes onwards
        self::assertSame(
            hash('crc32c', $covered),
            $crcInBatch,
            'the stored CRC32C must match a recomputation over attributes..end'
        );
    }

    /** POSITIVE: the payload survives the round trip through the encoder/decoder. */
    public function testEncodeThenDecodeReturnsTheSameValue(): void
    {
        $backend = new KafkaBackend([]);
        $batch = self::method('encodeRecordBatch')->invoke($backend, 'thekey', '{"hello":"world"}');
        $decoded = self::method('decodeFirstRecord')->invoke($backend, $batch);

        self::assertIsArray($decoded, 'a freshly encoded batch must decode');
        self::assertSame('{"hello":"world"}', $decoded['value']);
        self::assertSame(0, $decoded['offset'], 'baseOffset 0 + offsetDelta 0');
    }
}
