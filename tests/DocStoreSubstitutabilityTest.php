<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use function Tina4\getCollection;
use function Tina4\isServerless;

/**
 * DocStore substitutability: the SAME code, against BOTH providers.
 *
 * plan/v3/fixtures/docstore_contract.json is the shared answer key, and this is
 * the PHP half of it. Ported from tina4-python's
 * tests/test_docstore_substitutability.py so the protection exists in both.
 *
 * WHY THIS FILE EXISTS
 *   DocStore is the purest test of ADR-0024 in the framework, because
 *   substitutability IS its advertised feature: develop against a
 *   zero-dependency local SQLite store, switch to MongoDB in production by
 *   setting one env var.
 *
 *   MEASURED 2026-08-01: NO DocStore test in ANY of the four frameworks had
 *   ever touched a real Mongo collection. That is exactly how nine defects
 *   accumulated behind four green suites.
 *
 *   Every case below runs TWICE - once on the SQLite fallback, once on a REAL
 *   MongoDB. A divergence between the two IS the bug, and no assertion here is
 *   meaningful against one provider alone.
 *
 * NO MOCKS. A real SQLite file and a real MongoDB over a real socket. Skips
 * loudly when no Mongo is reachable, because a fabricated one would defeat the
 * entire purpose of the file.
 */
final class DocStoreSubstitutabilityTest extends TestCase
{
    private const SENTINEL_HOST = '192.168.88.99';
    private const SENTINEL_PORT = 27017;

    private array $savedEnv = [];

    /** The Mongo URI under test, overridable for another lab. */
    private static function mongoUri(): string
    {
        return getenv('TINA4_TEST_MONGO_URI')
            ?: 'mongodb://' . self::SENTINEL_HOST . ':' . self::SENTINEL_PORT;
    }

    /**
     * A real connect, not a port probe.
     *
     * A port that merely accepts is not a usable Mongo. That distinction is the
     * one that turned an intended skip into a hard failure in the MySQL batch
     * tests, where the gate checked reachability and the service then refused
     * the credentials.
     */
    private static function mongoReachable(): bool
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            return false;
        }
        try {
            $client = new \MongoDB\Client(self::mongoUri(), ['serverSelectionTimeoutMS' => 3000]);
            $client->selectDatabase('admin')->command(['ping' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function setUp(): void
    {
        foreach (['TINA4_MONGO_URI', 'TINA4_SESSION_MONGO_URI', 'TINA4_SESSION_MONGO_URL', 'TINA4_DOC_STORE_PATH'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Bind the DocStore to one provider and hand back a fresh collection.
     *
     * @param string|null $uri A Mongo URI, or null for the SQLite fallback.
     * @return array{0: object, 1: string} The collection and its name.
     */
    private function collectionFor(?string $uri): array
    {
        foreach (['TINA4_MONGO_URI', 'TINA4_SESSION_MONGO_URI', 'TINA4_SESSION_MONGO_URL'] as $key) {
            putenv($key);
        }
        if ($uri !== null) {
            putenv("TINA4_MONGO_URI={$uri}");
        }
        putenv('TINA4_DOC_STORE_PATH=' . sys_get_temp_dir() . '/ds_' . bin2hex(random_bytes(6)) . '.db');

        $name = 'ds_contract_' . bin2hex(random_bytes(5));

        return [getCollection($name), $name];
    }

    /** @return array<string, array{0: string|null}> fallback + real mongo */
    public static function providerCases(): array
    {
        return [
            'fallback' => [null],
            'mongo' => ['__MONGO__'],
        ];
    }

    /** Resolve the marker to a live URI, or skip when Mongo is unreachable. */
    private function resolve(?string $marker): ?string
    {
        if ($marker !== '__MONGO__') {
            return null;
        }
        if (!self::mongoReachable()) {
            $this->markTestSkipped('no reachable MongoDB at ' . self::mongoUri());
        }

        return self::mongoUri();
    }

    // ── the ROOT invariant ──────────────────────────────────────────────────

    /**
     * docstore_contract.json :: a-real-mongo-is-actually-exercised
     *
     * Listed last in the fixture because it EXPLAINS the other seven: with no
     * real-provider coverage, every other rule could drift unnoticed.
     */
    public function testARealMongoCollectionIsReachableAndUsed(): void
    {
        $uri = $this->resolve('__MONGO__');
        [$collection] = $this->collectionFor($uri);

        // NEGATIVE: it must not be the fallback masquerading as Mongo.
        $this->assertNotInstanceOf(
            \Tina4\SqliteCollection::class,
            $collection,
            'getCollection returned the SQLite fallback while a Mongo URI was configured'
        );
        // POSITIVE: and it must really round-trip through the server.
        $collection->insertOne(['proof' => 'real-mongo']);
        $this->assertSame('real-mongo', $collection->findOne(['proof' => 'real-mongo'])['proof']);
        $collection->deleteMany([]);
    }

    /**
     * docstore_contract.json :: a-configured-uri-selects-the-real-provider
     *
     * isServerless() and getCollection() must never disagree. The backlog
     * records a PHP install where isServerless() reported "not serverless"
     * while getCollection() still handed back local SQLite, so the app believed
     * it was on Mongo while writing to a container-local file.
     */
    public function testIsServerlessAgreesWithWhatGetCollectionReturned(): void
    {
        $uri = $this->resolve('__MONGO__');
        [$collection] = $this->collectionFor($uri);

        $this->assertFalse(isServerless(), 'a configured URI must mean not-serverless');
        $this->assertNotInstanceOf(\Tina4\SqliteCollection::class, $collection);
    }

    // ── the shared round trip, on BOTH providers ────────────────────────────

    /**
     * @dataProvider providerCases
     */
    public function testInsertThenFindOneReturnsWhatWasStored(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        $collection->insertOne(['name' => 'alpha', 'n' => 5, 'ok' => true]);
        $found = $collection->findOne(['name' => 'alpha']);

        $this->assertNotNull($found, 'the document must be findable on both providers');
        $this->assertEquals(5, $found['n'], 'integer did not round-trip');
        $collection->deleteMany([]);
    }

    /**
     * @dataProvider providerCases
     */
    public function testUpdateOneSetIsVisibleToTheNextRead(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        $collection->insertOne(['name' => 'beta', 'status' => 'new']);
        $collection->updateOne(['name' => 'beta'], ['$set' => ['status' => 'shipped']]);

        $this->assertSame('shipped', $collection->findOne(['name' => 'beta'])['status']);
        $collection->deleteMany([]);
    }

    /**
     * @dataProvider providerCases
     */
    public function testCountDocumentsAgreesWithWhatWasInserted(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        foreach (range(0, 2) as $i) {
            $collection->insertOne(['batch' => 'c', 'i' => $i]);
        }

        $this->assertSame(3, $collection->countDocuments(['batch' => 'c']));
        $collection->deleteMany([]);
    }

    /**
     * @dataProvider providerCases
     */
    public function testAComparisonOperatorFiltersTheSameWay(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        foreach ([1, 5, 9] as $n) {
            $collection->insertOne(['grp' => 'd', 'n' => $n]);
        }

        $got = [];
        foreach ($collection->find(['grp' => 'd', 'n' => ['$gt' => 4]]) as $doc) {
            $got[] = (int) $doc['n'];
        }
        sort($got);

        $this->assertSame([5, 9], $got, '$gt must filter identically on both providers');
        $collection->deleteMany([]);
    }

    // ── ADR-0025: the fallback imitates the driver (ASSERTED) ───────────────

    /**
     * docstore_contract.json :: the-call-site-surface-is-identical
     *
     * ADR-0025, closed 2026-08-03. This was an OPEN DEFECT reported rather than
     * asserted; it is now a gate.
     *
     * The defect: the insert-result accessors were MUTUALLY EXCLUSIVE. The
     * fallback exposed a public `->insertedId` property and no getter; a real
     * MongoDB\InsertOneResult exposes getInsertedId() and NO public properties
     * at all. So the framework's own documented example
     *
     *     $res = $orders->insertOne([...]);
     *     $orders->findOne(['_id' => $res->insertedId]);
     *
     * silently became findOne(['_id' => null]) the moment TINA4_MONGO_URI was
     * set, and the developer just saw "document not found". There was NO
     * spelling of the insert that worked on both providers.
     *
     * ADR-0025 settles it: the fallback imitates the DRIVER, because the driver
     * is the half that cannot be changed. This test pins the outcome - ONE
     * spelling, working identically on both.
     *
     * @dataProvider providerCases
     */
    public function testTheDriverSpellingWorksOnBothProviders(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        $result = $collection->insertOne(['probe' => 'accessor']);

        // POSITIVE: the driver's spelling round-trips on whichever provider is
        // in play - the whole point of the swap.
        $id = $result->getInsertedId();
        $this->assertNotNull($id, 'getInsertedId() must return the new document id');
        $found = $collection->findOne(['_id' => $id]);
        $this->assertNotNull($found, 'the id from getInsertedId() must find the document back');
        $this->assertSame('accessor', $found['probe']);

        $collection->deleteMany([]);
    }

    /**
     * docstore_contract.json :: the-call-site-surface-is-identical
     *
     * The NEGATIVE half of the rule above, and the one that keeps it honest.
     *
     * ADR-0025 corollary 1 is "no fallback-only public method": a second
     * spelling that works ONLY on the fallback is exactly how the original
     * defect shipped, because it let the documentation settle on an accessor
     * the real driver had never heard of. A real MongoDB result object exposes
     * no public properties, so neither may ours.
     *
     * @dataProvider providerCases
     */
    public function testTheFallbackOnlySpellingIsGone(?string $marker): void
    {
        $uri = $this->resolve($marker);
        [$collection] = $this->collectionFor($uri);

        $result = $collection->insertOne(['probe' => 'accessor']);

        $public = (new \ReflectionObject($result))->getProperties(\ReflectionProperty::IS_PUBLIC);
        $this->assertSame(
            [],
            array_map(static fn ($p) => $p->getName(), $public),
            'an insert result must expose NO public properties, on either provider'
        );

        $collection->deleteMany([]);
    }

    // ── OPEN DEFECTS: measured, reported, deliberately not asserted ──────────

    /**
     * docstore_contract.json :: query-semantics-match-on-both-providers
     *
     * ADR-0025 clause 4, closed 2026-08-03.
     *
     * MEASURED against a real MongoDB: EIGHT array-query behaviours diverged
     * IDENTICALLY in all four frameworks - the signature of a contract nobody
     * had written down. Three were FALSE POSITIVES, where the fallback returned
     * a document Mongo excludes: ['nums' => ['$gt' => 9]] matched [1,2,3],
     * because json_extract of an array returns its JSON TEXT and SQLite sorts
     * any text above any number.
     *
     * MongoDB's rule is one sentence: a condition on an array-valued field
     * matches when ANY ELEMENT matches it (or the whole array equals the
     * operand), and a negation matches when NO element does.
     *
     * What is asserted is not "the fallback returns N" - it is that BOTH
     * PROVIDERS RETURN THE SAME THING. That is ADR-0024 stated directly, and it
     * cannot drift towards a hard-coded expectation.
     */
    public function testArrayQueriesMatchIdenticallyOnBothProviders(): void
    {
        $uri = $this->resolve('__MONGO__');

        $doc = ['name' => 'w', 'tags' => ['x', 'y'], 'nums' => [1, 2, 3],
            'empty' => [], 'scalar' => 'x', 'obj' => ['city' => 'x']];
        $cases = [
            'equality containment' => ['tags' => 'x'],
            'equality no match' => ['tags' => 'z'],
            'exact array, right order' => ['tags' => ['x', 'y']],
            'exact array, wrong order' => ['tags' => ['y', 'x']],
            '$in hits one element' => ['tags' => ['$in' => ['x', 'q']]],
            '$in hits nothing' => ['tags' => ['$in' => ['q']]],
            '$nin excludes a present element' => ['tags' => ['$nin' => ['x']]],
            '$nin with an absent element' => ['tags' => ['$nin' => ['q']]],
            '$ne a present element' => ['tags' => ['$ne' => 'x']],
            '$ne an absent element' => ['tags' => ['$ne' => 'q']],
            'numeric containment' => ['nums' => 1],
            '$gt any element' => ['nums' => ['$gt' => 2]],
            '$gt no element' => ['nums' => ['$gt' => 9]],
            '$lt any element' => ['nums' => ['$lt' => 2]],
            '$exists on an array' => ['tags' => ['$exists' => true]],
            'empty array exact' => ['empty' => []],
            '$regex on an array element' => ['tags' => ['$regex' => '^x$']],
            'scalar still works' => ['scalar' => 'x'],
            'object field is not matched by its value' => ['obj' => 'x'],
            'object field matches the whole object' => ['obj' => ['city' => 'x']],
        ];

        $results = [];
        foreach (['fallback' => null, 'mongo' => $uri] as $provider => $providerUri) {
            [$collection] = $this->collectionFor($providerUri);
            $collection->deleteMany([]);
            $collection->insertOne($doc);
            foreach ($cases as $name => $query) {
                $results[$provider][$name] = count(iterator_to_array($collection->find($query)));
            }
            $collection->deleteMany([]);
        }

        $mismatched = [];
        foreach (array_keys($cases) as $name) {
            if ($results['fallback'][$name] !== $results['mongo'][$name]) {
                $mismatched[$name] = [$results['fallback'][$name], $results['mongo'][$name]];
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            'array-query semantics diverge between the providers (fallback, mongo): '
                . json_encode($mismatched)
        );
    }

    // ── ADR-0025 / client-lifecycle-is-bounded (ASSERTED) ───────────────────

    /**
     * docstore_contract.json :: client-lifecycle-is-bounded
     *
     * MEASURED 2026-08-03 against a real MongoDB, across all four frameworks:
     * get_collection() built a NEW client on every call and never closed it, so
     * connections grew linearly and without bound - Node +40 and Ruby +60 per 20
     * calls. Invisible in development, because the SQLite fallback opens no
     * connections at all; the leak existed ONLY after the swap.
     *
     * PHP was the one framework already BOUNDED, and not by our code: ext-mongodb
     * pools at the libmongoc level, so many MongoDB\Client objects sharing a URI
     * share one connection pool. Measured 0 growth over 60 calls.
     *
     * This test exists anyway, and asserts the same named case as the other
     * three, because "correct for a reason we did not choose" is exactly the
     * behaviour that regresses silently. If a future change starts passing
     * per-call options that defeat libmongoc's pool sharing, this catches it.
     */
    public function testRepeatedGetCollectionDoesNotGrowConnections(): void
    {
        $uri = $this->resolve('__MONGO__');

        $connections = static function () use ($uri): int {
            $probe = new \MongoDB\Client($uri);
            $status = $probe->selectDatabase('admin')->command(['serverStatus' => 1])->toArray()[0];

            return (int) $status['connections']['current'];
        };

        $rounds = [];
        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 20; $i++) {
                [$collection] = $this->collectionFor($uri);
                $collection->countDocuments([]);
            }
            $rounds[] = $connections();
        }

        $settled = end($rounds);
        for ($i = 0; $i < 100; $i++) {
            [$collection] = $this->collectionFor($uri);
            $collection->countDocuments([]);
        }
        $afterHundred = $connections();

        // POSITIVE: 100 further calls add nothing to a settled pool.
        $this->assertLessThanOrEqual(
            $settled + 2,
            $afterHundred,
            sprintf('connections still growing: settled=%d after 100 more=%d', $settled, $afterHundred)
        );
        // And the growth flattened rather than tracking the call count.
        $this->assertLessThanOrEqual(2, $rounds[2] - $rounds[1], 'rounds=' . json_encode($rounds));
        $this->assertLessThan(60, $rounds[2], 'rounds=' . json_encode($rounds));
    }
}
