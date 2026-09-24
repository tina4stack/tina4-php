<?php

use PHPUnit\Framework\TestCase;
use Tina4\MongoBson;

/**
 * The zero-dependency MongoDB wire clients (Session\MongoSessionHandler,
 * Cache\MongoBackend) must decode every reply a real server sends, including
 * replica-set replies.
 *
 * THE BUG (Mac testbed, 2026-09-24). The codec decoded double, string,
 * document, array, boolean, datetime, null, int32 and int64 only. Any other
 * BSON type returned null WITHOUT consuming its bytes, so the cursor landed
 * inside the value and the rest of the reply was garbage (strpos() offset
 * errors, lost `ok`). It looked like "MongoDB 8 breaks Tina4" because the
 * testbed's Mongo 8 was a replica-set member. Captured on real servers:
 * standalone mongo:7 and mongo:8 answer update/find/delete with plain types
 * only; a replica-set member (7 AND 8) adds electionId (ObjectId), opTime /
 * operationTime / $clusterTime (Timestamp) and a BinData signature to every
 * write reply. Every session and cache write against a replica set failed.
 *
 * Ruby's and Node's wire clients already decoded these types; this is the
 * same behaviour (ADR-0004).
 *
 * No mocks: the live test talks to the real server at TINA4_TEST_MONGO_URI;
 * the others feed the codec real bytes captured from a mongo:8.3.11
 * replica-set member - a pure function over its input.
 */
class MongoWireBsonTypesTest extends TestCase
{
    // Body document of a real OP_MSG reply to an upsert, from a mongo:8.3.11
    // replica-set member (rs0, one node), captured byte for byte.
    private const REPLICA_SET_UPSERT_REPLY =
        '12010000106e000100000007656c656374696f6e4964007fffffff0000000000000001'
        . '036f7054696d65001c00000011747300020000009be4b46a12740001000000000000'
        . '000004757073657274656400280000000330002000000010696e646578000000000002'
        . '5f69640007000000736573732d31000000106e4d6f6469666965640000000000016f6b'
        . '00000000000000f03f0324636c757374657254696d65005800000011636c7573746572'
        . '54696d6500020000009be4b46a037369676e61747572650033000000056861736800'
        . '14000000000000000000000000000000000000000000000000126b65794964000000'
        . '0000000000000000116f7065726174696f6e54696d6500020000009be4b46a00';

    // Timestamp(1790239899, 2): seconds in the high 32 bits, increment low.
    private const CAPTURED_TIMESTAMP = (1790239899 << 32) | 2;

    public function testAReplicaSetWriteReplyDecodesEveryField(): void
    {
        $reply = MongoBson::decode(hex2bin(self::REPLICA_SET_UPSERT_REPLY));

        $this->assertSame(1, $reply['n']);
        $this->assertSame(1.0, $reply['ok']);
        $this->assertSame(0, $reply['nModified']);
        $this->assertSame([['index' => 0, '_id' => 'sess-1']], $reply['upserted']);
        $this->assertSame('7fffffff0000000000000001', $reply['electionId']);
        $this->assertSame(['ts' => self::CAPTURED_TIMESTAMP, 't' => 1], $reply['opTime']);
        $this->assertSame(self::CAPTURED_TIMESTAMP, $reply['operationTime']);
        $this->assertSame(self::CAPTURED_TIMESTAMP, $reply['$clusterTime']['clusterTime']);
        $this->assertSame(['hash' => str_repeat("\x00", 20), 'keyId' => 0], $reply['$clusterTime']['signature']);
    }

    public function testAnUnknownTypeIsSkippedWithoutCorruptingTheFieldsAfterIt(): void
    {
        // {"inner": {"weird": <type 0x13 decimal128, 16 bytes>}, "ok": 1.0}
        // The codec does not decode 0x13; it must stop at the INNER document's
        // boundary and carry on, never read the value's bytes as keys.
        $weird = "\x13weird\x00" . implode('', array_map('chr', range(0, 15)));
        $inner = pack('V', strlen($weird) + 5) . $weird . "\x00";
        $body = "\x03inner\x00" . $inner . "\x01ok\x00" . pack('e', 1.0);
        $document = pack('V', strlen($body) + 5) . $body . "\x00";

        $decoded = MongoBson::decode($document);

        $this->assertSame(1.0, $decoded['ok']);
        $this->assertSame(['inner', 'ok'], array_keys($decoded));
    }

    /**
     * Every mongod - standalone or replica set, 7 or 8 - answers `hello` with
     * an ObjectId (topologyVersion.processId) and a UTC datetime (localTime)
     * BEFORE maxBsonObjectSize. A codec that mis-sizes either never reaches
     * maxBsonObjectSize intact. Sent through the session handler's own
     * command path.
     */
    public function testTheWireClientDecodesARealHelloReply(): void
    {
        $uri = getenv('TINA4_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        $parts = parse_url($uri);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 27017;
        $probe = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$probe) {
            $this->markTestSkipped("mongo not reachable at {$host}:{$port}");
        }
        fclose($probe);

        $handler = new \Tina4\Session\MongoSessionHandler(['url' => $uri, 'database' => 'tina4_wire_types']);
        $command = new \ReflectionMethod($handler, 'command');
        $connect = new \ReflectionMethod($handler, 'ensureConnected');
        try {
            $connect->invoke($handler);
            $reply = $command->invoke($handler, ['hello' => 1, '$db' => 'admin']);
        } finally {
            $handler->close();
        }

        $this->assertSame(1.0, $reply['ok']);
        $this->assertSame(16 * 1024 * 1024, $reply['maxBsonObjectSize']);
        $this->assertIsInt($reply['localTime']);
        $this->assertLessThan(600_000, abs($reply['localTime'] - (int)(microtime(true) * 1000)),
            'localTime is not the server clock in epoch milliseconds');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $reply['topologyVersion']['processId']);
    }
}
