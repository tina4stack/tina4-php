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

    // ── the driverless environment (ADR-0033) ───────────────────────────────
    //
    // NO MOCKS, and this is the case where that rule bites hardest: faking a
    // missing class is exactly the forbidden thing, because the bug being
    // pinned IS how the absence is handled.
    //
    // So the driver is made GENUINELY absent, twice, because PHP needs TWO
    // pieces and each can go missing on its own:
    //
    //   ext        `php -n` starts a real interpreter with no php.ini, so
    //              ext-mongodb is genuinely not loaded.
    //   library    a bootstrap that requires ONLY Tina4/Bootstrap/DocStore.php
    //              runs with the extension present and no composer autoloader,
    //              so MongoDB\Client genuinely does not exist. This is not
    //              hypothetical - mongodb/mongodb is require-dev plus a
    //              suggest, so `composer install --no-dev` on a box that does
    //              have the extension lands exactly here.
    //
    // Each probe reports what it actually found, so an environment that is NOT
    // driverless FAILS the test instead of quietly proving nothing.

    /** The probe program, run in a separate real PHP process. */
    private const DRIVER_ABSENCE_PROBE = <<<'PHP'
<?php
$bootstrap = $argv[1];
$repo = $argv[2];
if ($bootstrap === 'autoload') {
    require $repo . '/vendor/autoload.php';
} else {
    require $repo . '/Tina4/Bootstrap/DocStore.php';
}
$report = [
    'ext_present' => class_exists('\MongoDB\Driver\Manager'),
    'library_present' => class_exists('\MongoDB\Client'),
];
putenv('TINA4_MONGO_URI=' . $argv[3]);
putenv('TINA4_DOC_STORE_PATH=' . $argv[4]);
$report['is_serverless'] = \Tina4\isServerless();
try {
    $collection = \Tina4\getCollection('driver_absence_probe');
    $report['outcome'] = 'returned';
    $report['returned_type'] = get_class($collection);
} catch (\Throwable $e) {
    $report['outcome'] = 'raised';
    $report['error_type'] = (new \ReflectionClass($e))->getShortName();
    $report['message'] = $e->getMessage();
}
$report['store_file_exists'] = file_exists($argv[4]);
echo '__PROBE__' . json_encode($report);
PHP;

    /**
     * Run the probe in a separate PHP process and decode its report.
     *
     * @param string $bootstrap 'autoload' (composer) or 'standalone' (DocStore.php only)
     * @param array<int, string> $phpFlags extra interpreter flags, e.g. ['-n']
     * @return array<string, mixed>
     */
    private function runDriverAbsenceProbe(string $bootstrap, array $phpFlags, string $uri): array
    {
        $repo = dirname(__DIR__);
        $probePath = tempnam(sys_get_temp_dir(), 'tina4_probe_') . '.php';
        file_put_contents($probePath, self::DRIVER_ABSENCE_PROBE);
        $storePath = tempnam(sys_get_temp_dir(), 'tina4_store_') . '.db';
        @unlink($storePath);

        $command = array_merge([PHP_BINARY], $phpFlags, [$probePath, $bootstrap, $repo, $uri, $storePath]);
        $quoted = implode(' ', array_map('escapeshellarg', $command));
        $output = shell_exec($quoted . ' 2>&1');
        @unlink($probePath);
        @unlink($storePath);

        $this->assertStringContainsString('__PROBE__', (string) $output, "probe did not report: {$output}");

        return json_decode(explode('__PROBE__', (string) $output, 2)[1], true);
    }

    /**
     * docstore_contract.json :: a-missing-driver-has-one-outcome-in-all-four
     *
     * MEASURED 2026-08-01 and re-measured 2026-08-04 at v3 HEAD: with the
     * extension absent, isServerless() answered true and getCollection() handed
     * back the local SQLite store. Production writes went to a container-local
     * file nobody reads, with no error at any point.
     *
     * ADR-0024 rule 3, settled for DocStore by ADR-0033: a provider that cannot
     * honour an operation must RAISE, naming the provider and what is missing.
     */
    public function testAMissingDriverRaisesInsteadOfUsingTheLocalFile(): void
    {
        // A password in the URI, so the credential-leak assertion has something
        // real to catch.
        $uri = 'mongodb://docstore_user:s3cr3t-p4ssw0rd@192.0.2.1:27017';
        $report = $this->runDriverAbsenceProbe('autoload', ['-n'], $uri);

        // The environment must really be driverless, or nothing below means
        // anything. This FAILS rather than skipping, on purpose.
        $this->assertFalse(
            $report['ext_present'],
            'php -n still loaded ext-mongodb, so this test would have proved nothing'
        );

        $this->assertFalse($report['is_serverless'], 'a configured URI must mean not-serverless');
        $this->assertSame(
            'raised',
            $report['outcome'],
            'expected a raise, got ' . ($report['returned_type'] ?? '?')
        );
        $this->assertSame('DocStoreDriverMissing', $report['error_type']);
        $this->assertStringContainsString('mongodb', $report['message']);
        $this->assertStringContainsString('pecl install mongodb', $report['message']);
        $this->assertStringContainsString('TINA4_MONGO_URI', $report['message']);

        // NEGATIVE: naming the variable must not mean printing its value.
        $this->assertStringNotContainsString('s3cr3t-p4ssw0rd', $report['message'], 'the message does not leak the uri credentials, but it did');
        // NEGATIVE, and the one that matters most: nothing was written locally.
        $this->assertFalse($report['store_file_exists'], 'the local SQLite store was created anyway');
    }

    /**
     * docstore_contract.json :: a-missing-driver-has-one-outcome-in-all-four
     *
     * PHP's SECOND door, measured 2026-08-04 at v3 HEAD: with ext-mongodb
     * PRESENT but the mongodb/mongodb library absent, isServerless() reported
     * FALSE and getCollection() STILL returned Tina4\SqliteCollection - the
     * two disagreeing exactly as a-configured-uri-selects-the-real-provider
     * forbids, through a door that invariant's own test never opened.
     */
    public function testAMissingLibraryRaisesInsteadOfUsingTheLocalFile(): void
    {
        $uri = 'mongodb://docstore_user:s3cr3t-p4ssw0rd@192.0.2.1:27017';
        $report = $this->runDriverAbsenceProbe('standalone', [], $uri);

        if ($report['library_present']) {
            $this->fail('the standalone bootstrap still saw MongoDB\Client, so this test would have proved nothing');
        }
        $this->assertTrue($report['ext_present'], 'this case needs the EXTENSION present and the LIBRARY absent');

        $this->assertFalse($report['is_serverless']);
        $this->assertSame(
            'raised',
            $report['outcome'],
            'expected a raise, got ' . ($report['returned_type'] ?? '?')
        );
        $this->assertSame('DocStoreDriverMissing', $report['error_type']);
        $this->assertStringContainsString('mongodb/mongodb', $report['message']);
        $this->assertStringContainsString('composer require mongodb/mongodb', $report['message']);
        $this->assertStringNotContainsString('s3cr3t-p4ssw0rd', $report['message'], 'the message does not leak the uri credentials, but it did');
        $this->assertFalse($report['store_file_exists'], 'the local SQLite store was created anyway');
    }

    /**
     * POSITIVE half: the raise must be about the DRIVER, not the URI.
     *
     * Same configuration, driver installed, and the real provider is selected
     * with no exception. Without this, deleting the whole real-Mongo path
     * would satisfy the negative cases above.
     */
    public function testTheSameUriWithTheDriverPresentStillSelectsMongo(): void
    {
        $uri = $this->resolve('__MONGO__');
        [$collection] = $this->collectionFor($uri);

        $this->assertFalse(isServerless());
        $this->assertNotInstanceOf(\Tina4\SqliteCollection::class, $collection);
        $collection->insertOne(['proof' => 'driver-present']);
        $collection->deleteMany([]);
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
     *
     * EVERY COUNT HERE IS SCOPED TO THE CONNECTIONS THIS TEST OWNS.
     * serverStatus.connections.current, which this test used to read, is a
     * SERVER-GLOBAL counter across every client on that mongod, so any other
     * process moves it and the assertion becomes a coin flip rather than a
     * gate. Measured 2026-08-04 against the shared lab MongoDB 7.0.39 with the
     * docstore code UNCHANGED and correct, the global count read [88, 89, 90]
     * with one other agent connected and [193, 194, 195] with 45 further real
     * clients held open, against an idle baseline near 6.
     *
     * $currentOp with idleConnections is the per-client view: an appName in
     * the connection string tags every socket this test's client opens, and
     * nobody else's carry it.
     */
    public function testRepeatedGetCollectionDoesNotGrowConnections(): void
    {
        $baseUri = $this->resolve('__MONGO__');
        $appName = 'tina4_docstore_lifecycle_' . bin2hex(random_bytes(5));
        $uri = $baseUri . (str_contains($baseUri, '?') ? '&' : '/?') . 'appName=' . $appName;

        $ownConnections = static function () use ($baseUri, $appName): int {
            $probe = new \MongoDB\Client($baseUri);
            $rows = $probe->selectDatabase('admin')->aggregate([
                ['$currentOp' => ['allUsers' => true, 'idleConnections' => true, 'localOps' => true]],
                ['$match' => ['appName' => $appName]],
                ['$count' => 'n'],
            ])->toArray();

            // $count emits NO document when nothing matched, which is 0.
            return $rows === [] ? 0 : (int) $rows[0]['n'];
        };

        // The measurement must be able to SEE this client, or every assertion
        // below is vacuously true and proves nothing.
        [$warmup] = $this->collectionFor($uri);
        $warmup->countDocuments([]);
        $this->assertGreaterThan(
            0,
            $ownConnections(),
            'appName scoping saw none of our own connections - the probe is blind'
        );

        $rounds = [];
        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 20; $i++) {
                [$collection] = $this->collectionFor($uri);
                $collection->countDocuments([]);
            }
            $rounds[] = $ownConnections();
        }

        $settled = end($rounds);
        for ($i = 0; $i < 100; $i++) {
            [$collection] = $this->collectionFor($uri);
            $collection->countDocuments([]);
        }
        $afterHundred = $ownConnections();

        // POSITIVE: 100 further calls add nothing to a settled pool.
        $this->assertLessThanOrEqual(
            $settled,
            $afterHundred,
            sprintf('connections still growing: settled=%d after 100 more=%d', $settled, $afterHundred)
        );
        // And the growth flattened rather than tracking the call count. Both
        // halves are scoped, so the ceiling measures OUR pool.
        $this->assertLessThanOrEqual(2, $rounds[2] - $rounds[1], 'rounds=' . json_encode($rounds));
        $this->assertLessThanOrEqual(10, $rounds[2], 'our own pool is not bounded: rounds=' . json_encode($rounds));
    }
}
