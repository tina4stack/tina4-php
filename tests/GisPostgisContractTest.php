<?php

require_once __DIR__ . '/GisContractTest.php';

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\ORM;
use Tina4\Point;

final class GisPostgisContractTest extends TestCase
{
    private ?DatabaseAdapter $db = null;
    private static array $contract;

    protected function setUp(): void
    {
        $url = getenv('TINA4_TEST_POSTGIS_URL') ?: 'postgres://tina4:tina4@localhost:55433/tina4_gis';
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'localhost';
        $port = (int) ($parts['port'] ?? 5432);
        $socket = @fsockopen($host, $port, $errno, $error, 1.0);
        if ($socket === false) $this->markTestSkipped("PostGIS not reachable at {$host}:{$port}");
        fclose($socket);
        $database = ltrim($parts['path'] ?? '/tina4_gis', '/');
        $this->db = Database::create(
            "postgres://{$host}:{$port}/{$database}",
            username: rawurldecode($parts['user'] ?? 'tina4'),
            password: rawurldecode($parts['pass'] ?? 'tina4')
        );
        $this->db->execute('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->db->execute('DROP TABLE IF EXISTS gis_fixture_site');
        $this->db->commit();
        ORM::bindDatabase($this->db);
        self::$contract = json_decode((string) file_get_contents(__DIR__ . '/fixtures/gis_contract.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->execute('DROP TABLE IF EXISTS gis_fixture_site');
            $this->db->commit();
            $this->db->close();
        }
    }

    public function testFixtureRunsAgainstRealPostgis(): void
    {
        $site = new GisFixtureSite($this->db);
        $this->assertTrue($site->createTable());
        $this->assertTrue($site->createTable(), 'DDL and GiST creation must be idempotent');
        $column = $this->db->fetchOne(
            "SELECT type, srid FROM geography_columns WHERE f_table_name = ? AND f_geography_column = ?",
            ['gis_fixture_site', 'location']
        );
        $this->assertSame('Point', $column['type']);
        $this->assertSame(4326, (int) $column['srid']);
        $index = $this->db->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            ['gis_fixture_site', 'gis_fixture_site_location_gist']
        );
        $this->assertStringContainsString('using gist', strtolower($index['indexdef']));

        foreach (['cape_town' => 'Cape Town', 'johannesburg' => 'Johannesburg', 'anti_east' => 'Anti East', 'anti_west' => 'Anti West', 'null_island' => 'Null Island'] as $key => $name) {
            $this->assertNotFalse((new GisFixtureSite(['name' => $name, 'location' => self::$contract['points'][$key]]))->save());
        }
        $this->assertNotFalse((new GisFixtureSite(['name' => 'No Fix', 'location' => null]))->save());
        $near = GisFixtureSite::query()
            ->withinDistance('location', self::$contract['points']['cape_town'], 1000)
            ->orderByDistance('location', self::$contract['points']['cape_town'])->get();
        $this->assertSame(['Cape Town'], array_column($near->records, 'name'));
        $distances = GisFixtureSite::query()->select('name')
            ->selectDistance('location', self::$contract['points']['cape_town'], 'metres')
            ->where('name = ?', ['Johannesburg'])->get();
        $metres = (float) $distances->records[0]['metres'];
        $case = self::$contract['distance_cases'][0];
        $this->assertGreaterThanOrEqual($case['minimum_metres'], $metres);
        $this->assertLessThanOrEqual($case['maximum_metres'], $metres);
        $anti = GisFixtureSite::query()->select('name')
            ->selectDistance('location', self::$contract['points']['anti_east'], 'metres')
            ->where('name = ?', ['Anti West'])->first();
        $this->assertGreaterThanOrEqual(21000, (float) $anti['metres']);
        $this->assertLessThanOrEqual(23000, (float) $anti['metres']);
        $box = self::$contract['bbox_cases'][0];
        $boxed = GisFixtureSite::query()->bbox('location', ...$box['bounds'])->get();
        $this->assertSame(['Cape Town'], array_column($boxed->records, 'name'));
        $polygon = ['type' => 'Polygon', 'coordinates' => [[[18.0, -34.2], [18.9, -34.2], [18.9, -33.5], [18.0, -33.5], [18.0, -34.2]]]];
        $intersections = GisFixtureSite::query()->intersects('location', $polygon)->get();
        $this->assertSame(['Cape Town'], array_column($intersections->records, 'name'));
        $loaded = GisFixtureSite::find(1);
        $this->assertInstanceOf(Point::class, $loaded->location);
        $this->assertSame(self::$contract['points']['cape_town'], $loaded->location->geoJson()['coordinates']);
        $this->assertNull(GisFixtureSite::find(6)->location);
    }
}
