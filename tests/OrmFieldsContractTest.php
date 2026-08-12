<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\ORM;
use Tina4\Database\Database;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Feature 18 - ORM fields and column mapping: the shared conformance contract.
 *
 * Proves the reconciled field-model BEHAVIOUR (FIELD-DEC-01 gap + FIELD-DEC-02),
 * NO MOCKS. The cross-engine DDL cases actually CREATE the table on real
 * PostgreSQL / MySQL / MSSQL / Firebird and read the column type back from each
 * engine's OWN catalog.
 *
 * Case names are shared verbatim (each language's own idiom) across the four
 * frameworks and gated by scripts/audit-contract-fixtures.py. The PHP-only
 * datetime-heuristic cases carry the FIELD-PHP-DATETIME-HEURISTIC invariant.
 *
 * Coordinates use the canonical TINA4_TEST_* convention. Under
 * TINA4_REQUIRE_SERVICES a PG/MySQL/MSSQL skip is upgraded to a failure by
 * RequireServicesExtension; Firebird is gated (runs when TINA4_TEST_FIREBIRD_URL
 * is set, which the lab sets, else stays green).
 */

/** Every field type, for the round-trip case (real SQLite). */
class OrmfWidget extends ORM
{
    public string $tableName = 'ormf_widget';
    public int $id = 0;
    public string $name = '';
    public string $note = '';
    public int $qty = 0;
    public bool $active = false;
    public float $ratio = 0.0;
    public ?string $made_at = null;   // "_at" suffix -> datetime column
    public array $payload = [];        // array-typed -> JSON column
    public array $decimals = ['ratio' => [8, 3]];
}

/** JSON round-trip. */
class OrmfDoc extends ORM
{
    public string $tableName = 'ormf_doc';
    public int $id = 0;
    public array $body = [];
}

/** Callable default (per-instance, dropped from DDL). */
class OrmfSession extends ORM
{
    public string $tableName = 'ormf_session';
    public int $id = 0;
    public string $token = '';

    protected function fieldDefaults(): array
    {
        return ['token' => fn() => bin2hex(random_bytes(8))];
    }
}

/** Counter-backed callable default (proves per-instance resolution). */
class OrmfTicket extends ORM
{
    public string $tableName = 'ormf_ticket';
    public static int $counter = 0;
    public int $id = 0;
    public int $seq = 0;

    protected function fieldDefaults(): array
    {
        return ['seq' => fn() => ++self::$counter];
    }
}

/** Non-serializable JSON. */
class OrmfBlob extends ORM
{
    public string $tableName = 'ormf_blob';
    public int $id = 0;
    public array $data = [];
}

/** Decimal DDL. */
class OrmfMoney extends ORM
{
    public string $tableName = 'ormf_money';
    public int $id = 0;
    public float $amount = 0.0;
    public array $decimals = ['amount' => [12, 4]];
}

/** PHP datetime NAME-heuristic model (FIELD-PHP-DATETIME-HEURISTIC). */
class OrmfHeuristic extends ORM
{
    public string $tableName = 'ormf_heuristic';
    public int $id = 0;
    public ?string $updated_by = null;   // has "date" in "up-date-d" -> must NOT be datetime
    public ?string $runtime = null;      // ends "time" -> must NOT be datetime
    public ?string $downtime = null;     // ends "time" -> must NOT be datetime
    public ?string $created_at = null;   // "_at" suffix -> datetime
    public ?string $event_date = null;   // "_date" suffix -> datetime
    public ?string $start_time = null;   // "_time" suffix -> datetime
}

/**
 * Cross-engine DDL model: bool / json / decimal / datetime columns. A natural
 * string PK (the round-trip supplies it) so the row insert exercises the field
 * COLUMNS on the real engine without depending on per-engine auto-increment
 * (Firebird auto-increment needs a generator ORM.createTable sets up only in
 * Node today -- a separate feature 16/17 gap).
 */
class OrmfEngine extends ORM
{
    public string $tableName = 'ormf_engine';
    public string $id = '';
    public bool $flag = false;
    public array $payload = [];
    public float $amount = 0.0;
    public ?string $made_at = null;
    public array $decimals = ['amount' => [12, 4]];
}

final class OrmFieldsContractTest extends TestCase
{
    private ?Database $sqlite = null;

    protected function setUp(): void
    {
        $this->sqlite = Database::create('sqlite::memory:');
        ORM::bindDatabase($this->sqlite);
    }

    private function ddl(Database $db, string $table): string
    {
        $row = $db->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
        return $row['sql'] ?? '';
    }

    // ── four-way behaviour (SQLite here; the engine variants prove PG/MySQL/MSSQL/FB) ──

    public function testEachDeclaredFieldTypeRoundTripsThroughARealDatabase(): void
    {
        $this->assertTrue((new OrmfWidget())->createTable());
        $w = new OrmfWidget([
            'name' => 'gizmo', 'note' => 'a longer note', 'qty' => 7, 'active' => true,
            'ratio' => 12.345, 'made_at' => '2026-01-02 03:04:05',
            'payload' => ['k' => 'v', 'n' => [1, 2, 3]],
        ]);
        $this->assertNotFalse($w->save());
        $got = (new OrmfWidget())->findById($w->id);
        $this->assertSame('gizmo', $got->name);
        $this->assertSame('a longer note', $got->note);
        $this->assertSame(7, $got->qty);
        $this->assertTrue((bool) $got->active);
        $this->assertEqualsWithDelta(12.345, $got->ratio, 1e-6);
        $this->assertStringContainsString('2026-01-02', (string) $got->made_at);
        $this->assertSame(['k' => 'v', 'n' => [1, 2, 3]], $got->payload);
    }

    public function testAJsonFieldRoundTripsToANativeObject(): void
    {
        (new OrmfDoc())->createTable();
        $d = new OrmfDoc(['body' => ['tags' => ['a', 'b'], 'meta' => ['n' => 1]]]);
        $this->assertNotFalse($d->save());
        $got = (new OrmfDoc())->findById($d->id);
        $this->assertIsArray($got->body);
        $this->assertSame(['a', 'b'], $got->body['tags']);
        $this->assertSame(1, $got->body['meta']['n']);
    }

    public function testACallableDefaultIsResolvedPerInstance(): void
    {
        OrmfTicket::$counter = 0;
        $a = new OrmfTicket();
        $b = new OrmfTicket();
        $c = new OrmfTicket();
        $this->assertSame([1, 2, 3], [$a->seq, $b->seq, $c->seq]);
    }

    public function testACallableDefaultIsNotPresentInTheCreateTableDdl(): void
    {
        $this->assertTrue((new OrmfSession())->createTable());
        $ddl = $this->ddl($this->sqlite, 'ormf_session');
        $this->assertStringNotContainsStringIgnoringCase('DEFAULT', $ddl);
    }

    public function testANonSerializableJsonValueMakesSaveReturnFalseAndIsLogged(): void
    {
        (new OrmfBlob())->createTable();
        $bad = new OrmfBlob(['data' => ['x' => NAN]]);   // NAN cannot be JSON-encoded
        $this->assertFalse($bad->save());
        $this->assertNotNull($bad->getError());
        $this->assertMatchesRegularExpression('/json|nan|serial/i', $bad->getError());
        $this->assertSame(0, (new OrmfBlob())->count());
    }

    public function testADecimalFieldProducesADecimalPrecisionScaleColumn(): void
    {
        $this->assertTrue((new OrmfMoney())->createTable());
        $ddl = str_replace(' ', '', strtoupper($this->ddl($this->sqlite, 'ormf_money')));
        $this->assertStringContainsString('DECIMAL(12,4)', $ddl);
    }

    // ── FIELD-PHP-DATETIME-HEURISTIC (PHP-only) ────────────────────────────

    public function testUpdatedByIsNotInferredAsDatetime(): void
    {
        $defs = (new OrmfHeuristic())->getFieldDefinitions();
        $this->assertSame('string', $defs['updated_by']['type']);
    }

    public function testRuntimeAndDowntimeAreNotInferredAsDatetime(): void
    {
        $defs = (new OrmfHeuristic())->getFieldDefinitions();
        $this->assertSame('string', $defs['runtime']['type']);
        $this->assertSame('string', $defs['downtime']['type']);
    }

    public function testCreatedAtAndADateColumnAndATimeColumnAreStillInferredAsDatetime(): void
    {
        $defs = (new OrmfHeuristic())->getFieldDefinitions();
        $this->assertSame('datetime', $defs['created_at']['type']);
        $this->assertSame('datetime', $defs['event_date']['type']);
        $this->assertSame('datetime', $defs['start_time']['type']);
    }

    // ── real-engine DDL (replaces the deleted Python mock) ─────────────────

    public static function engineProvider(): array
    {
        return [
            'postgres' => ['postgres'],
            'mysql'    => ['mysql'],
            'mssql'    => ['mssql'],
            'firebird' => ['firebird'],
        ];
    }

    private function engineDb(string $engine): Database
    {
        $reach = static function (string $host, int $port): bool {
            $c = @fsockopen($host, $port, $e, $s, 2.0);
            if ($c) { fclose($c); return true; }
            return false;
        };
        if ($engine === 'postgres') {
            $h = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
            $p = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
            if (!$reach($h, $p)) { $this->markTestSkipped("postgres unreachable at {$h}:{$p} (set TINA4_TEST_PG_*)"); }
            $db = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
            $u = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
            $pw = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
            return Database::create("postgres://{$u}:{$pw}@{$h}:{$p}/{$db}");
        }
        if ($engine === 'mysql') {
            $h = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
            $p = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
            if (!$reach($h, $p)) { $this->markTestSkipped("mysql unreachable at {$h}:{$p} (set TINA4_TEST_MYSQL_*)"); }
            $db = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
            $u = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
            $pw = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
            return Database::create("mysql://{$u}:{$pw}@{$h}:{$p}/{$db}");
        }
        if ($engine === 'mssql') {
            $h = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
            $p = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
            if (!$reach($h, $p)) { $this->markTestSkipped("mssql unreachable at {$h}:{$p} (set TINA4_TEST_MSSQL_*)"); }
            $db = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
            $u = getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
            $pw = getenv('TINA4_TEST_MSSQL_PASSWORD') ?: 'TinaSQL123!Secure';
            return Database::create("mssql://{$h}:{$p}/{$db}", username: $u, password: $pw);
        }
        // firebird — gated; runs when TINA4_TEST_FIREBIRD_URL is set (the lab sets it)
        $url = getenv('TINA4_TEST_FIREBIRD_URL') ?: '';
        if ($url === '') { $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird)'); }
        return Database::create($url);
    }

    private function dropEngineTable(Database $db, string $engine, string $table): void
    {
        try {
            if ($engine === 'mssql') {
                $db->execute("IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table}");
            } elseif ($engine === 'firebird') {
                if ($db->tableExists($table)) { $db->execute("DROP TABLE {$table}"); }
            } else {
                $db->execute("DROP TABLE IF EXISTS {$table}");
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    /** @return array<string, array<string, mixed>> lower-cased column name => info */
    private function describe(Database $db, string $engine, string $table): array
    {
        $out = [];
        // A driver returns catalog column labels in its OWN case (MySQL
        // upper-cases them, PG/MSSQL keep them lower). array_change_key_case
        // normalises every row to lower so the reads below are driver-independent.
        if ($engine === 'postgres') {
            $rows = $db->fetch(
                'SELECT column_name, data_type, udt_name, numeric_precision, numeric_scale '
                . 'FROM information_schema.columns WHERE table_name = ?',
                [strtolower($table)]
            )->records;
            foreach ($rows as $raw) {
                $r = array_change_key_case($raw, CASE_LOWER);
                $out[strtolower($r['column_name'])] = [
                    'type' => strtolower($r['udt_name'] ?? $r['data_type']),
                    'precision' => $r['numeric_precision'] !== null ? (int) $r['numeric_precision'] : null,
                    'scale' => $r['numeric_scale'] !== null ? (int) $r['numeric_scale'] : null,
                ];
            }
        } elseif ($engine === 'mysql') {
            $rows = $db->fetch(
                'SELECT column_name, data_type, column_type, numeric_precision, numeric_scale '
                . 'FROM information_schema.columns WHERE table_name = ? AND table_schema = DATABASE()',
                [$table]
            )->records;
            foreach ($rows as $raw) {
                $r = array_change_key_case($raw, CASE_LOWER);
                $out[strtolower($r['column_name'])] = [
                    'type' => strtolower($r['column_type']),
                    'data_type' => strtolower($r['data_type']),
                    'precision' => $r['numeric_precision'] !== null ? (int) $r['numeric_precision'] : null,
                    'scale' => $r['numeric_scale'] !== null ? (int) $r['numeric_scale'] : null,
                ];
            }
        } elseif ($engine === 'mssql') {
            $rows = $db->fetch(
                'SELECT column_name, data_type, numeric_precision, numeric_scale, character_maximum_length '
                . 'FROM information_schema.columns WHERE table_name = ?',
                [$table]
            )->records;
            foreach ($rows as $raw) {
                $r = array_change_key_case($raw, CASE_LOWER);
                $out[strtolower($r['column_name'])] = [
                    'type' => strtolower($r['data_type']),
                    'precision' => $r['numeric_precision'] !== null ? (int) $r['numeric_precision'] : null,
                    'scale' => $r['numeric_scale'] !== null ? (int) $r['numeric_scale'] : null,
                    'maxlen' => $r['character_maximum_length'] !== null ? (int) $r['character_maximum_length'] : null,
                ];
            }
        } else { // firebird
            $rows = $db->fetch(
                'SELECT TRIM(rf.rdb$field_name) AS fname, f.rdb$field_type AS ftype, '
                . 'f.rdb$field_sub_type AS fsub, f.rdb$field_precision AS fprec, f.rdb$field_scale AS fscale '
                . 'FROM rdb$relation_fields rf JOIN rdb$fields f '
                . 'ON rf.rdb$field_source = f.rdb$field_name WHERE rf.rdb$relation_name = ?',
                [strtoupper($table)]
            )->records;
            foreach ($rows as $raw) {
                $r = array_change_key_case($raw, CASE_LOWER);
                $out[strtolower(trim((string) $r['fname']))] = [
                    'ftype' => (int) $r['ftype'],
                    'fsub' => $r['fsub'] !== null ? (int) $r['fsub'] : null,
                    'precision' => $r['fprec'] !== null ? (int) $r['fprec'] : null,
                    'scale' => $r['fscale'] !== null ? (int) $r['fscale'] : null,
                ];
            }
        }
        return $out;
    }

    private function makeEngineTable(Database $db, string $engine): array
    {
        ORM::bindDatabase($db);
        $m = new OrmfEngine();
        $this->dropEngineTable($db, $engine, 'ormf_engine');
        $this->assertTrue($m->createTable(), "create_table failed on {$engine}: " . ($db->getError() ?? ''));
        return $this->describe($db, $engine, 'ormf_engine');
    }

    #[DataProvider('engineProvider')]
    public function testABooleanColumnIsCreatedWithTheEngineNativeBooleanType(string $engine): void
    {
        $db = $this->engineDb($engine);
        try {
            $cols = $this->makeEngineTable($db, $engine);
            $flag = $cols['flag'];
            if ($engine === 'postgres') {
                $this->assertStringContainsString('bool', $flag['type']);
            } elseif ($engine === 'mysql') {
                $this->assertSame('tinyint(1)', $flag['type']);
            } elseif ($engine === 'mssql') {
                $this->assertSame('bit', $flag['type']);
            } else {
                $this->assertSame(8, $flag['ftype']);   // Firebird LONG / INTEGER
            }
            $this->dropEngineTable($db, $engine, 'ormf_engine');
        } finally {
            $db->close();
        }
    }

    #[DataProvider('engineProvider')]
    public function testAJsonColumnIsCreatedWithTheEngineNativeJsonType(string $engine): void
    {
        $db = $this->engineDb($engine);
        try {
            $cols = $this->makeEngineTable($db, $engine);
            $payload = $cols['payload'];
            if ($engine === 'postgres') {
                $this->assertSame('jsonb', $payload['type']);
            } elseif ($engine === 'mysql') {
                $this->assertSame('json', $payload['type']);
            } elseif ($engine === 'mssql') {
                $this->assertSame('nvarchar', $payload['type']);
                $this->assertSame(-1, $payload['maxlen']);   // NVARCHAR(MAX)
            } else {
                $this->assertSame(261, $payload['ftype']);   // BLOB
                $this->assertSame(1, $payload['fsub']);      // SUB_TYPE TEXT
            }
            $this->dropEngineTable($db, $engine, 'ormf_engine');
        } finally {
            $db->close();
        }
    }

    #[DataProvider('engineProvider')]
    public function testADecimalColumnIsCreatedWithDecimalPrecisionAndScaleOnTheEngine(string $engine): void
    {
        $db = $this->engineDb($engine);
        try {
            $cols = $this->makeEngineTable($db, $engine);
            $amount = $cols['amount'];
            if ($engine === 'firebird') {
                $this->assertSame(12, $amount['precision']);
                $this->assertSame(-4, $amount['scale']);   // stored as a scaled INT64
            } else {
                $this->assertSame(12, $amount['precision']);
                $this->assertSame(4, $amount['scale']);
                if ($engine === 'postgres') {
                    $this->assertContains($amount['type'], ['numeric', 'decimal']);
                } else {
                    $this->assertStringStartsWith('decimal', $amount['type']);
                }
            }
            $this->dropEngineTable($db, $engine, 'ormf_engine');
        } finally {
            $db->close();
        }
    }

    #[DataProvider('engineProvider')]
    public function testTheEngineDdlTypesRoundTripARealRow(string $engine): void
    {
        $db = $this->engineDb($engine);
        try {
            $this->makeEngineTable($db, $engine);
            // Drive the write/read through the DB facade with an explicit key:
            // proves the ENGINE COLUMNS accept + return a real row of each type,
            // independent of ORM auto-increment (Firebird) and the ORM natural-key
            // save path (a second transaction on MySQL) -- separate feature 16/17
            // concerns. The ORM's JSON serialize/hydrate is proven by the SQLite
            // cases above.
            $db->insert('ormf_engine', [
                'id' => 'row-1',
                'flag' => true,
                'payload' => json_encode(['k' => 'v', 'n' => [1, 2]]),
                'amount' => 1234.5678,
                'made_at' => '2026-03-04 05:06:07',
            ]);
            $row = $db->fetchOne('SELECT * FROM ormf_engine WHERE id = ?', ['row-1']);
            $this->assertNotNull($row, "row not found on {$engine}");
            $r = array_change_key_case($row, CASE_LOWER);
            $this->assertContains(strtolower((string) $r['flag']), ['1', 't', 'true']);
            $payload = is_array($r['payload']) ? $r['payload'] : json_decode((string) $r['payload'], true);
            $this->assertSame(['k' => 'v', 'n' => [1, 2]], $payload);
            $this->assertEqualsWithDelta(1234.5678, (float) $r['amount'], 1e-3);
            $this->assertStringContainsString('2026-03-04', (string) $r['made_at']);
            $this->dropEngineTable($db, $engine, 'ormf_engine');
        } finally {
            $db->close();
        }
    }
}
