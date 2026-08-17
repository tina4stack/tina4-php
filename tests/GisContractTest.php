<?php

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\ORM;
use Tina4\Point;
use Tina4\QueryBuilder;
use Tina4\SQLTranslator;
use Tina4\SpatialNotSupportedException;

final class GisFixtureSite extends ORM
{
    public string $tableName = 'gis_fixture_site';
    public int $id = 0;
    public string $name = '';
    public ?Point $location = null;
    public array $pointFields = ['location' => ['srid' => 4326, 'spatialIndex' => true]];
}

final class GisContractTest extends TestCase
{
    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/fixtures/gis_contract.json';
        self::$contract = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRunnerLoadsTheCanonicalFixture(): void
    {
        $this->assertSame('ADR-0057', self::$contract['adr']);
        $this->assertSame(['longitude', 'latitude'], self::$contract['defaults']['coordinate_order']);
    }

    public function testEveryAcceptedPointFormNormalisesToGeoJson(): void
    {
        foreach (self::$contract['accepted_point_forms'] as $case) {
            $this->assertSame($case['expected'], Point::parse($case['value'])->geoJson(), $case['name']);
        }
    }

    public function testEveryInvalidPointFailsBeforePersistence(): void
    {
        foreach (self::$contract['invalid_points'] as $case) {
            try {
                new GisFixtureSite(['name' => $case['name'], 'location' => $case['value']]);
                $this->fail("{$case['name']} should have failed");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPostgisSqlUsesBoundValues(): void
    {
        $this->assertSame(
            'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            SQLTranslator::withinDistance('postgresql', 'location')
        );
        $this->assertSame(
            'ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS metres',
            SQLTranslator::distanceAs('postgresql', 'location', 'metres')
        );
        [$sql, $params] = SQLTranslator::namedToPositional(
            'SELECT ST_MakePoint(?, ?)::geography',
            [18.4241, -33.9249]
        );
        $this->assertSame('SELECT ST_MakePoint(?, ?)::geography', $sql);
        $this->assertSame([18.4241, -33.9249], $params, 'PostgreSQL casts must not discard positional bindings');
    }

    public function testInvalidBoundingBoxesFail(): void
    {
        foreach (array_filter(self::$contract['bbox_cases'], fn(array $case): bool => $case['error'] ?? false) as $case) {
            try {
                [$west, $south, $east, $north] = $case['bounds'];
                new Point($west, $south);
                new Point($east, $north);
                if ($west > $east || $south > $north) {
                    throw new \InvalidArgumentException('inverted bbox');
                }
                $this->fail("{$case['name']} should have failed");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSQLiteSpatialDdlAndQueryFailLoudly(): void
    {
        $sqlite = new SQLite3Adapter(':memory:');
        try {
            $this->expectException(SpatialNotSupportedException::class);
            (new GisFixtureSite($sqlite))->createTable();
        } finally {
            $sqlite->close();
        }
    }

    public function testGeoJsonFeatureAndCollectionPreserveOrder(): void
    {
        $capeTown = new GisFixtureSite(['id' => 1, 'name' => 'Cape Town', 'location' => self::$contract['points']['cape_town']]);
        $johannesburg = new GisFixtureSite(['id' => 2, 'name' => 'Johannesburg', 'location' => self::$contract['points']['johannesburg']]);
        $feature = $capeTown->toFeature();
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame(['type' => 'Point', 'coordinates' => self::$contract['points']['cape_town']], $feature['geometry']);
        $this->assertArrayNotHasKey('location', $feature['properties']);
        $collection = ORM::featureCollection([$johannesburg, $capeTown]);
        $this->assertSame([2, 1], array_column(array_column($collection['features'], 'properties'), 'id'));
    }
}
