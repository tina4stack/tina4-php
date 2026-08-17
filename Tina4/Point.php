<?php

namespace Tina4;

/** Immutable SRID-aware longitude/latitude point (ADR-0057). */
final class Point implements \JsonSerializable
{
    public const DEFAULT_SRID = 4326;
    private const EWKB_SRID = 0x20000000;
    private const EWKB_M = 0x40000000;
    private const EWKB_Z = 0x80000000;

    public readonly float $lon;
    public readonly float $lat;
    public readonly int $srid;

    public function __construct(mixed $lon, mixed $lat, mixed $srid = self::DEFAULT_SRID)
    {
        if (is_bool($lon) || is_bool($lat) || !is_numeric($lon) || !is_numeric($lat)) {
            throw new \InvalidArgumentException('Point longitude and latitude must be numbers');
        }
        if (is_bool($srid) || filter_var($srid, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Point SRID must be an integer');
        }
        $longitude = (float) $lon;
        $latitude = (float) $lat;
        $reference = (int) $srid;
        if (!is_finite($longitude) || !is_finite($latitude)) {
            throw new \InvalidArgumentException('Point longitude and latitude must be finite');
        }
        if ($reference === self::DEFAULT_SRID) {
            if ($longitude < -180.0 || $longitude > 180.0) {
                throw new \InvalidArgumentException("Point longitude {$longitude} is outside -180..180; Tina4 uses longitude, latitude order");
            }
            if ($latitude < -90.0 || $latitude > 90.0) {
                throw new \InvalidArgumentException("Point latitude {$latitude} is outside -90..90; Tina4 uses longitude, latitude order");
            }
        }
        $this->lon = $longitude;
        $this->lat = $latitude;
        $this->srid = $reference;
    }

    public function wkt(): string
    {
        return 'POINT(' . self::format($this->lon) . ' ' . self::format($this->lat) . ')';
    }

    public function ewkt(): string
    {
        return "SRID={$this->srid};" . $this->wkt();
    }

    /** @return array{type:string,coordinates:array{0:float,1:float}} */
    public function geoJson(): array
    {
        return ['type' => 'Point', 'coordinates' => [$this->lon, $this->lat]];
    }

    public function jsonSerialize(): array
    {
        return $this->geoJson();
    }

    public function __toString(): string
    {
        return $this->wkt();
    }

    public static function parse(mixed $value, int $srid = self::DEFAULT_SRID): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_array($value)) {
            if (isset($value['type'])) {
                return self::fromGeoJson($value, $srid);
            }
            if (count($value) < 2) {
                throw new \InvalidArgumentException('Point coordinate pair needs longitude and latitude');
            }
            $coordinates = array_values($value);
            return new self($coordinates[0], $coordinates[1], $srid);
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (is_string($value)) {
            $text = trim($value);
            if (preg_match('/^(?:SRID\s*=\s*(\d+)\s*;\s*)?POINT\s*(?:Z|M|ZM)?\s*\(\s*([-+0-9.eE]+)\s+([-+0-9.eE]+)(?:\s+[-+0-9.eE]+)*\s*\)$/i', $text, $match)) {
                return new self($match[2], $match[3], isset($match[1]) && $match[1] !== '' ? (int) $match[1] : $srid);
            }
            if (strlen($text) >= 42 && strlen($text) % 2 === 0 && ctype_xdigit($text)) {
                $raw = hex2bin($text);
                if ($raw !== false) {
                    return self::fromWkb($raw, $srid);
                }
            }
            if ($text !== '' && (ord($text[0]) === 0 || ord($text[0]) === 1)) {
                return self::fromWkb($text, $srid);
            }
        }
        throw new \InvalidArgumentException('Point value must be Point, [longitude, latitude], WKT/EWKT, GeoJSON or WKB/EWKB');
    }

    /** @return array{0:string,1:string} bound value and ewkt|geojson form */
    public static function geometryBinding(mixed $value, int $srid = self::DEFAULT_SRID): array
    {
        if ($value instanceof self || (is_array($value) && !isset($value['type']))) {
            return [self::parse($value, $srid)->ewkt(), 'ewkt'];
        }
        if (is_array($value)) {
            $geometry = strtolower((string) ($value['type'] ?? '')) === 'feature'
                ? ($value['geometry'] ?? []) : $value;
            $type = strtolower((string) ($geometry['type'] ?? ''));
            $allowed = ['point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];
            if (!in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('GeoJSON geometry has an unsupported type');
            }
            if ($type === 'geometrycollection') {
                if (!is_array($geometry['geometries'] ?? null)) {
                    throw new \InvalidArgumentException('GeoJSON GeometryCollection needs geometries');
                }
            } elseif (!is_array($geometry['coordinates'] ?? null)) {
                throw new \InvalidArgumentException('GeoJSON geometry needs coordinates');
            }
            return [json_encode($geometry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'geojson'];
        }
        if (is_string($value) && preg_match('/^\s*(?:SRID\s*=\s*(\d+)\s*;\s*)?(?:POINT|LINESTRING|POLYGON|MULTIPOINT|MULTILINESTRING|MULTIPOLYGON|GEOMETRYCOLLECTION)\s*(?:Z|M|ZM)?\s*(?:\(|EMPTY\b)/i', $value, $match)) {
            return [isset($match[1]) && $match[1] !== '' ? trim($value) : "SRID={$srid};" . trim($value), 'ewkt'];
        }
        throw new \InvalidArgumentException('Geometry must be Point, coordinate pair, WKT/EWKT or GeoJSON');
    }

    private static function fromGeoJson(array $data, int $srid): self
    {
        $geometry = strtolower((string) ($data['type'] ?? '')) === 'feature'
            ? ($data['geometry'] ?? []) : $data;
        if (strtolower((string) ($geometry['type'] ?? '')) !== 'point') {
            throw new \InvalidArgumentException('Point GeoJSON type must be Point');
        }
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) < 2) {
            throw new \InvalidArgumentException('Point GeoJSON coordinates must be [longitude, latitude]');
        }
        return new self($coordinates[0], $coordinates[1], $srid);
    }

    private static function fromWkb(string $raw, int $srid): self
    {
        if (strlen($raw) < 21) {
            throw new \InvalidArgumentException('Point WKB is too short');
        }
        $little = ord($raw[0]) === 1;
        $typeWord = unpack($little ? 'Vvalue' : 'Nvalue', substr($raw, 1, 4))['value'];
        $offset = 5;
        if (($typeWord & self::EWKB_SRID) !== 0) {
            $srid = unpack($little ? 'Vvalue' : 'Nvalue', substr($raw, 5, 4))['value'];
            $offset = 9;
        }
        $geometryCode = ($typeWord & ~(self::EWKB_SRID | self::EWKB_Z | self::EWKB_M)) % 1000;
        if ($geometryCode !== 1 || strlen($raw) < $offset + 16) {
            throw new \InvalidArgumentException('WKB geometry is not a Point');
        }
        $format = $little ? 'elon/elat' : 'Elon/Elat';
        $point = unpack($format, substr($raw, $offset, 16));
        return new self($point['lon'], $point['lat'], $srid);
    }

    private static function format(float $value): string
    {
        $text = sprintf('%.15g', $value);
        return $text === '-0' ? '0' : $text;
    }
}

