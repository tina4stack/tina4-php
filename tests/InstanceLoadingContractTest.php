<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 26 - ORM instance loading / hydration: the shared conformance
 * contract, parity with tina4-python/tests/test_instance_loading_contract.py.
 *
 * LOAD-DEC-01: PHP's fill() never re-ran business constraints on read (the
 * $fields overlay is validate()-only, never consulted by fill()) — but it had
 * its OWN read-time footgun with the SAME observable symptom: hydrating a
 * stored SQL NULL into a NON-NULLABLE typed property (the documented
 * "required" idiom: `public string $name = '';` + $fields['name']['required'])
 * THREW a TypeError, unhandled, aborting the whole find()/all()/select(). Fixed:
 * fill() now catches a null-into-non-nullable TypeError and leaves the property
 * at its already-initialized class default instead of letting it propagate —
 * any OTHER TypeError (a genuinely un-coercible non-null value) still raises.
 *
 * LOAD-JSON-ONLY (LOAD-DEC-02): the scalar read-coercion contract is PINNED as
 * JSON-only (OWNER-DECISIONS.md Batch 5) — PHP already coerces ONLY JSON columns
 * on read (fill(), unchanged); non-JSON scalars stay driver-typed.
 *
 * Case names are shared verbatim across all four frameworks and gated by
 * scripts/audit-contract-fixtures.py.
 *
 * NO MOCKS: real SQLite (always) + real PostgreSQL :55432 tina4/tina4 (gated —
 * skips cleanly when unreachable locally, a hard failure under
 * TINA4_REQUIRE_SERVICES, e.g. on the lab).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

/**
 * V1 ("loose"): defines the table's DDL. `name` is a NULLABLE typed property
 * with no required constraint, so the column stays nullable — a legitimate
 * pre-existing row CAN hold NULL.
 */
class LoadContractItemV1 extends ORM
{
    public string $tableName = 'load_contract_item';
    public string $primaryKey = 'id';
    public int $id = 0;
    public ?string $name = null;
    public ?array $payload = null;
    public bool $active = true;
}

/**
 * V2 ("tight"): the SAME table, but `name` is a NON-NULLABLE typed property
 * (the documented "required" idiom) with the matching $fields overlay —
 * simulating a constraint TIGHTENED after the row already existed
 * (LOAD-PY-REVALIDATE's PHP-shaped twin). Only V2 proves the
 * read-hydrate-still-works / write-still-rejects split.
 */
class LoadContractItemV2 extends ORM
{
    public string $tableName = 'load_contract_item';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
    public ?array $payload = null;
    public bool $active = true;

    public array $fields = [
        'name' => ['required' => true],
    ];
}

class InstanceLoadingContractTest extends TestCase
{
    private function runCases(): void
    {
        (new LoadContractItemV1())->createTable();

        // ── json_column_round_trips_via_finder ──────────────────────────
        $saved = new LoadContractItemV1();
        $saved->name = 'alice';
        $saved->payload = ['tags' => ['a', 'b'], 'n' => 1];
        $this->assertNotFalse($saved->save());

        $got = (new LoadContractItemV1())->find($saved->id);
        $this->assertIsArray($got->payload, 'expected a native array, not a JSON string');
        // assertEqualsCanonicalizing, NOT assertSame: PostgreSQL's JSONB storage
        // does not preserve OBJECT key insertion order (SQLite's TEXT column
        // does) -- same data, key order differs by engine. That is a real,
        // engine-level JSONB property, not a hydration bug: the parsed value
        // carries the identical key/value pairs, so a key-order-insensitive
        // comparison is the correct assertion here, not a weakened one.
        $this->assertEqualsCanonicalizing(['tags' => ['a', 'b'], 'n' => 1], $got->payload);

        // ── json_column_round_trips_via_load ─────────────────────────────
        $reloaded = new LoadContractItemV1();
        $reloaded->id = $saved->id;
        $this->assertTrue($reloaded->load());
        $this->assertIsArray($reloaded->payload, 'expected a native array, not a JSON string');
        $this->assertEqualsCanonicalizing(['tags' => ['a', 'b'], 'n' => 1], $reloaded->payload);

        // ── constraint_violating_stored_row_still_hydrates ───────────────
        // V1 (nullable `name`, no required) legitimately stores a NULL name —
        // an ordinary nullable-column row, saved through the NORMAL write path.
        $stored = new LoadContractItemV1();
        $stored->name = null;
        $stored->payload = ['k' => 'v'];
        $this->assertNotFalse($stored->save());

        // V2 (SAME table, `name` now non-nullable + required=true) reads it
        // back. Pre-fix this THREW "Cannot assign null to property ...$name of
        // type string" out of fill(), aborting the whole find()/all(). Now it
        // must hydrate — the property falls back to V2's OWN class default
        // ('') since a non-nullable PHP property cannot literally represent
        // the stored NULL; the row is still readable, which is the contract.
        $stillReadable = (new LoadContractItemV2())->find($stored->id);
        $this->assertNotNull($stillReadable, 'a required-but-NULL stored row must still hydrate via find()');
        $this->assertSame('', $stillReadable->name);

        // The SAME row must also survive a full all() (not just a single
        // find), proving one non-conforming row does not abort a page of results.
        $allRows = (new LoadContractItemV2())->all();
        $ids = array_map(fn($r) => $r->id, $allRows);
        $this->assertContains($stored->id, $ids, 'all() aborted instead of returning every row');

        // Prove the write path is UNCHANGED: V2's OWN save() still rejects a
        // NEW row missing the now-required `name` — this is a read-only fix,
        // not a deleted constraint.
        $rejected = new LoadContractItemV2();
        $rejected->payload = [];
        $result = $rejected->save();
        $this->assertFalse($result, 'save() must still reject a missing required field');
        $this->assertNotNull($rejected->getError());
        $this->assertStringContainsString('required', strtolower($rejected->getError()));

        // ── partial_select_yields_partial_instance ───────────────────────
        $full = new LoadContractItemV1();
        $full->name = 'partial-target';
        $full->payload = ['z' => 9];
        $this->assertNotFalse($full->save());

        $partial = (new LoadContractItemV1())->select(
            'SELECT id, name FROM load_contract_item WHERE id = ?', [$full->id]
        );
        $this->assertCount(1, $partial);
        $inst = $partial[0];
        $this->assertSame('partial-target', $inst->name);
        // `payload` and `active` were NOT selected — they must sit at their
        // declared class defaults, not crash and not carry a stale/wrong value.
        $this->assertTrue($inst->active);
        $this->assertNull($inst->payload);
    }

    public function testJsonColumnRoundTripsViaFinderAndLoadSqlite(): void
    {
        $db = new SQLite3Adapter(':memory:');
        ORM::bindDatabase($db);
        try {
            $this->runCases();
        } finally {
            $db->close();
        }
    }

    public function testJsonColumnRoundTripsViaFinderAndLoadPostgres(): void
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $c = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if (!$c) {
            $this->markTestSkipped("postgres unreachable at {$host}:{$port} (set TINA4_TEST_PG_*)");
        }
        fclose($c);

        $dbName = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        $db = Database::create("postgres://{$user}:{$pass}@{$host}:{$port}/{$dbName}");
        ORM::bindDatabase($db);
        try {
            $db->execute('DROP TABLE IF EXISTS load_contract_item');
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $this->runCases();
        } finally {
            try {
                $db->execute('DROP TABLE IF EXISTS load_contract_item');
            } catch (\Throwable $e) {
                // ignore
            }
            $db->close();
        }
    }
}
