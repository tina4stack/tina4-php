<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Tina4 DocStore - pymongo-style document storage with a zero-config SQLite fallback.
 *
 * A document store with the everyday pymongo collection API, backed by SQLite's
 * JSON1 extension when no MongoDB server is configured.
 *
 *     use function Tina4\getCollection;
 *     use Tina4\ObjectId;
 *
 *     $orders = getCollection("orders");          // SqliteCollection when no Mongo configured
 *     $oid = $orders->insertOne(["customer_id" => 1, "total" => 9.99])->insertedId;
 *     foreach ($orders->find(["customer_id" => ['$in' => [1, 2]]])->sort("created_at", -1)->limit(10) as $o) {
 *         // ...
 *     }
 *     $orders->updateOne(["_id" => $oid], ['$set' => ["status" => "shipped"]]);
 *
 * getCollection(name) returns a real MongoDB driver collection when a Mongo URI is
 * configured (TINA4_MONGO_URI, else TINA4_SESSION_MONGO_URL), and otherwise a
 * SqliteCollection backed by a local SQLite file. This mirrors the file-based
 * fallbacks the queue and session subsystems already provide: an app that talks to
 * Mongo in production runs serverless in local dev with no code change - only the
 * backend differs.
 *
 * Design (the SQLite backend):
 *     - Each collection is a table (_id TEXT PRIMARY KEY, doc TEXT) holding JSON.
 *     - Query filters are pushed down to SQL over json_extract(doc, '$.field')
 *       (lazy, not a full in-memory scan), supporting equality, $in/$nin,
 *       $gt/$gte/$lt/$lte, $ne, $exists, $regex, and implicit-AND / $or / $and.
 *     - Updates: $set, $unset, $inc, and full-document replace.
 *     - Cursors: sort / limit / skip / projection.
 *     - IDs are a built-in 12-byte ObjectId (zero-dependency; interchangeable with
 *       a real driver ObjectId as a 24-hex string).
 *
 * Type round-trip is by value, not by wrapper object, so json_extract stays
 * queryable and sortable: a DateTime is stored as an ISO-8601 UTC string and an
 * ObjectId as its 24-hex string, and reads rehydrate a strict-ISO string back to
 * DateTime and a 24-hex string back to ObjectId. That keeps range queries and
 * sorts working on date and id fields - the trade-off (a plain 24-hex / ISO string
 * becomes an ObjectId / DateTime on read) is acceptable for the local dev store.
 *
 * Deliberate non-goals: aggregation pipeline, $elemMatch, geo. This is the
 * everyday CRUD + filter subset, not full Mongo parity.
 *
 * Mirrors the Python implementation in tina4_python/docstore.
 */

/**
 * Raised when a value cannot be parsed as an ObjectId.
 */
class InvalidId extends \InvalidArgumentException
{
}

/**
 * A 12-byte MongoDB-style ObjectId, with no external dependency.
 *
 * Layout: 4-byte big-endian seconds since epoch, 5-byte per-process random,
 * 3-byte big-endian counter. Renders as a 24-char hex string, so it is
 * interchangeable with a real driver ObjectId wherever the string form is used.
 */
class ObjectId implements \JsonSerializable
{
    private static int $counter = -1;
    private static string $process = '';

    /** @var string Raw 12 bytes. */
    private string $bytes;

    /**
     * @param ObjectId|string|null $oid Existing ObjectId, a 24-hex string, or null to generate.
     */
    public function __construct(ObjectId|string|null $oid = null)
    {
        if ($oid === null) {
            $this->bytes = self::generate();
        } elseif ($oid instanceof ObjectId) {
            $this->bytes = $oid->bytes;
        } else {
            // string form
            if (!preg_match('/^[0-9a-fA-F]{24}$/', $oid)) {
                throw new InvalidId("'$oid' is not a valid 24-character hex ObjectId");
            }
            $bin = hex2bin($oid);
            if ($bin === false || strlen($bin) !== 12) {
                throw new InvalidId("'$oid' is not a valid 24-character hex ObjectId");
            }
            $this->bytes = $bin;
        }
    }

    private static function generate(): string
    {
        if (self::$process === '') {
            self::$process = random_bytes(5);
        }
        if (self::$counter < 0) {
            self::$counter = random_int(0, 0xFFFFFF);
        }
        $ts = pack('N', time());
        self::$counter = (self::$counter + 1) & 0xFFFFFF;
        // 3-byte big-endian counter: take the low 3 bytes of a 4-byte pack.
        $counter = substr(pack('N', self::$counter), 1);
        return $ts . self::$process . $counter;
    }

    /**
     * True if $value parses as a valid ObjectId.
     */
    public static function isValid(mixed $value): bool
    {
        if ($value instanceof ObjectId) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        try {
            new ObjectId($value);
            return true;
        } catch (InvalidId) {
            return false;
        }
    }

    /**
     * The raw 12 bytes.
     */
    public function binary(): string
    {
        return $this->bytes;
    }

    /**
     * The UTC generation time (the embedded 4-byte timestamp).
     */
    public function generationTime(): \DateTimeImmutable
    {
        $ts = unpack('N', substr($this->bytes, 0, 4))[1];
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return bin2hex($this->bytes);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof ObjectId && $other->bytes === $this->bytes;
    }

    public function jsonSerialize(): string
    {
        return (string)$this;
    }
}

/**
 * Value encoding helpers + Mongo-filter-to-SQL compiler.
 *
 * Static-only utility class: keeps the value round-trip (ObjectId/DateTime) and
 * the filter compiler in one cohesive place, mirroring the Python module-level
 * functions.
 */
class DocStoreCodec
{
    private const OID_RE = '/^[0-9a-fA-F]{24}$/';
    // strict ISO-8601: 2024-01-01T00:00:00 with optional fraction + optional zone
    private const ISO_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/';

    private const COMPARATORS = ['$gt' => '>', '$gte' => '>=', '$lt' => '<', '$lte' => '<='];

    /**
     * A DateTime -> ISO-8601 UTC string (Z suffix).
     */
    public static function iso(\DateTimeInterface $dt): string
    {
        $utc = (clone (new \DateTimeImmutable('@' . $dt->getTimestamp())))
            ->setTimezone(new \DateTimeZone('UTC'));
        // Preserve sub-second precision if present.
        $frac = $dt->format('u');
        $base = $utc->format('Y-m-d\TH:i:s');
        if ($frac !== '000000') {
            return $base . '.' . rtrim($frac, '0') . 'Z';
        }
        return $base . 'Z';
    }

    /**
     * PHP value -> JSON-serialisable, sortable scalar form (for storage/queries).
     */
    public static function encode(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof \DateTimeInterface) {
            return self::iso($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::encode($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Stored JSON value -> PHP, rehydrating ObjectId (24-hex) and DateTime (ISO).
     */
    public static function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match(self::OID_RE, $value)) {
                return new ObjectId($value);
            }
            if (preg_match(self::ISO_RE, $value)) {
                try {
                    return new \DateTimeImmutable(str_replace('Z', '+00:00', $value));
                } catch (\Exception) {
                    return $value;
                }
            }
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::decode($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Canonical string key for the _id column.
     */
    public static function idKey(mixed $value): string
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof \DateTimeInterface) {
            return self::iso($value);
        }
        return (string)$value;
    }

    /**
     * Field name -> a JSON path. Dotted names address nested keys.
     */
    public static function path(string $field): string
    {
        $segments = explode('.', $field);
        $parts = [];
        foreach ($segments as $s) {
            // quote segments that are not bare identifiers
            $parts[] = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s) ? $s : '"' . $s . '"';
        }
        return '$.' . implode('.', $parts);
    }

    public static function extract(string $field): string
    {
        return "json_extract(doc, '" . self::path($field) . "')";
    }

    public static function jsonType(string $field): string
    {
        return "json_type(doc, '" . self::path($field) . "')";
    }

    /**
     * Compile a Mongo-style filter array into [sqlFragment, params].
     *
     * Returns ['1=1', []] for an empty filter. Supports implicit AND across keys,
     * $or / $and, and the per-field operator set.
     *
     * @return array{0:string,1:array}
     */
    public static function compileFilter(?array $query): array
    {
        if (empty($query)) {
            return ['1=1', []];
        }

        $clauses = [];
        $params = [];
        foreach ($query as $key => $value) {
            if ($key === '$or' || $key === '$and') {
                $joiner = $key === '$or' ? ' OR ' : ' AND ';
                $subs = [];
                foreach ($value as $sub) {
                    [$frag, $p] = self::compileFilter($sub);
                    $subs[] = "($frag)";
                    array_push($params, ...$p);
                }
                if ($subs) {
                    $clauses[] = '(' . implode($joiner, $subs) . ')';
                }
                continue;
            }

            if (self::isOperatorMap($value)) {
                foreach ($value as $op => $operand) {
                    [$frag, $p] = self::compileOp($key, $op, $operand);
                    $clauses[] = $frag;
                    array_push($params, ...$p);
                }
            } else {
                // equality
                if ($value === null) {
                    $clauses[] = self::extract($key) . ' IS NULL';
                } else {
                    $clauses[] = self::extract($key) . ' = ?';
                    $params[] = self::bind($value);
                }
            }
        }

        return [$clauses ? implode(' AND ', $clauses) : '1=1', $params];
    }

    /**
     * True when an associative array whose keys ALL start with '$' (an operator map).
     */
    private static function isOperatorMap(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach (array_keys($value) as $k) {
            if (!is_string($k) || $k === '' || $k[0] !== '$') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{0:string,1:array}
     */
    private static function compileOp(string $field, string $op, mixed $operand): array
    {
        $ex = self::extract($field);
        if (isset(self::COMPARATORS[$op])) {
            return ["$ex " . self::COMPARATORS[$op] . ' ?', [self::bind($operand)]];
        }
        if ($op === '$eq') {
            if ($operand === null) {
                return ["$ex IS NULL", []];
            }
            return ["$ex = ?", [self::bind($operand)]];
        }
        if ($op === '$ne') {
            if ($operand === null) {
                return ["$ex IS NOT NULL", []];
            }
            return ["($ex <> ? OR $ex IS NULL)", [self::bind($operand)]];
        }
        if ($op === '$in') {
            $items = array_values((array)$operand);
            if (!$items) {
                return ['0', []];
            }
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            return ["$ex IN ($placeholders)", array_map([self::class, 'bind'], $items)];
        }
        if ($op === '$nin') {
            $items = array_values((array)$operand);
            if (!$items) {
                return ['1', []];
            }
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            return ["($ex NOT IN ($placeholders) OR $ex IS NULL)", array_map([self::class, 'bind'], $items)];
        }
        if ($op === '$exists') {
            $type = self::jsonType($field);
            return [$operand ? "$type IS NOT NULL" : "$type IS NULL", []];
        }
        if ($op === '$regex') {
            $pattern = $operand;
            if (is_array($operand)) {
                $pattern = $operand['$regex'] ?? '';
            }
            return ["$ex REGEXP ?", [(string)$pattern]];
        }
        throw new \InvalidArgumentException("DocStore: unsupported query operator '$op'");
    }

    /**
     * Bind a PHP value for comparison against json_extract output.
     */
    public static function bind(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof ObjectId || $value instanceof \DateTimeInterface) {
            return self::encode($value);
        }
        if (is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }
        return json_encode(self::encode($value));
    }

    /**
     * REGEXP user function: 1 when $value matches the regex $pattern, else 0.
     */
    public static function regexp(?string $pattern, mixed $value): int
    {
        if ($value === null || $pattern === null) {
            return 0;
        }
        // Anchor the pattern as a PCRE; PHP needs delimiters.
        $delimited = '~' . str_replace('~', '\~', $pattern) . '~';
        $matched = @preg_match($delimited, (string)$value);
        return $matched === 1 ? 1 : 0;
    }

    /**
     * Apply a projection (include or exclude) to a document.
     */
    public static function project(array $doc, ?array $projection): array
    {
        if (empty($projection)) {
            return $doc;
        }
        $include = [];
        $exclude = [];
        foreach ($projection as $k => $v) {
            if ($v && $k !== '_id') {
                $include[] = $k;
            } elseif (!$v) {
                $exclude[] = $k;
            }
        }
        if ($include) {
            $out = [];
            foreach ($include as $k) {
                if (array_key_exists($k, $doc)) {
                    $out[$k] = $doc[$k];
                }
            }
            $keepId = $projection['_id'] ?? 1;
            if ($keepId && array_key_exists('_id', $doc)) {
                $out['_id'] = $doc['_id'];
            }
            return $out;
        }
        // exclusion projection
        $out = [];
        foreach ($doc as $k => $v) {
            if (!in_array($k, $exclude, true)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Apply an update document to $doc, returning the new document.
     */
    public static function applyUpdate(array $doc, array $update): array
    {
        $hasOp = false;
        foreach (array_keys($update) as $k) {
            if (is_string($k) && $k !== '' && $k[0] === '$') {
                $hasOp = true;
                break;
            }
        }
        if (!$hasOp) {
            // full-document replace (keep the existing _id)
            $new = $update;
            if (!array_key_exists('_id', $new) && array_key_exists('_id', $doc)) {
                $new['_id'] = $doc['_id'];
            }
            return $new;
        }
        $new = $doc;
        foreach ($update as $op => $fields) {
            if ($op === '$set') {
                foreach ($fields as $k => $v) {
                    self::setPath($new, $k, $v);
                }
            } elseif ($op === '$unset') {
                foreach (array_keys($fields) as $k) {
                    self::unsetPath($new, (string)$k);
                }
            } elseif ($op === '$inc') {
                foreach ($fields as $k => $v) {
                    $current = self::getPath($new, $k) ?? 0;
                    self::setPath($new, $k, $current + $v);
                }
            } else {
                throw new \InvalidArgumentException("DocStore: unsupported update operator '$op'");
            }
        }
        return $new;
    }

    private static function setPath(array &$doc, string $dotted, mixed $value): void
    {
        $parts = explode('.', $dotted);
        $node = &$doc;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $p = $parts[$i];
            if (!isset($node[$p]) || !is_array($node[$p])) {
                $node[$p] = [];
            }
            $node = &$node[$p];
        }
        $node[$parts[count($parts) - 1]] = $value;
    }

    private static function unsetPath(array &$doc, string $dotted): void
    {
        $parts = explode('.', $dotted);
        $node = &$doc;
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $p = $parts[$i];
            if (!isset($node[$p]) || !is_array($node[$p])) {
                return;
            }
            $node = &$node[$p];
        }
        unset($node[$parts[count($parts) - 1]]);
    }

    private static function getPath(array $doc, string $dotted): mixed
    {
        $parts = explode('.', $dotted);
        $node = $doc;
        foreach ($parts as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return null;
            }
            $node = $node[$p];
        }
        return $node;
    }
}

/**
 * Result of an insertOne call.
 */
class InsertOneResult
{
    public function __construct(public readonly mixed $insertedId)
    {
    }
}

/**
 * Result of an insertMany call.
 */
class InsertManyResult
{
    /** @param array $insertedIds */
    public function __construct(public readonly array $insertedIds)
    {
    }
}

/**
 * Result of an update / replace call.
 */
class UpdateResult
{
    public function __construct(
        public readonly int $matchedCount,
        public readonly int $modifiedCount,
        public readonly mixed $upsertedId = null
    ) {
    }
}

/**
 * Result of a delete call.
 */
class DeleteResult
{
    public function __construct(public readonly int $deletedCount)
    {
    }
}

/**
 * Lazy result cursor. Builds and runs SQL only when iterated.
 *
 * @implements \IteratorAggregate<int, array>
 */
class Cursor implements \IteratorAggregate
{
    /** @var array<array{0:string,1:int}> */
    private array $sort = [];
    private ?int $limit = null;
    private int $skip = 0;

    public function __construct(
        private readonly SqliteCollection $collection,
        private readonly string $where,
        private readonly array $params,
        private readonly ?array $projection = null
    ) {
    }

    /**
     * @param string|array<array{0:string,1:int}> $keyOrList
     */
    public function sort(string|array $keyOrList, int $direction = 1): self
    {
        if (is_string($keyOrList)) {
            $this->sort[] = [$keyOrList, $direction];
        } else {
            foreach ($keyOrList as $pair) {
                $this->sort[] = [$pair[0], $pair[1]];
            }
        }
        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = $n;
        return $this;
    }

    public function skip(int $n): self
    {
        $this->skip = $n;
        return $this;
    }

    public function buildSql(): string
    {
        $sql = "SELECT doc FROM {$this->collection->quoted()} WHERE {$this->where}";
        if ($this->sort) {
            $order = [];
            foreach ($this->sort as [$k, $d]) {
                $order[] = DocStoreCodec::extract($k) . ($d < 0 ? ' DESC' : ' ASC');
            }
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
            if ($this->skip) {
                $sql .= ' OFFSET ' . (int)$this->skip;
            }
        } elseif ($this->skip) {
            $sql .= ' LIMIT -1 OFFSET ' . (int)$this->skip;
        }
        return $sql;
    }

    public function getIterator(): \Generator
    {
        foreach ($this->collection->runDocQuery($this->buildSql(), $this->params) as $docText) {
            yield $this->collection->load($docText, $this->projection);
        }
    }

    /**
     * @return array<int, array>
     */
    public function toList(?int $length = null): array
    {
        $out = iterator_to_array($this->getIterator(), false);
        return $length === null ? $out : array_slice($out, 0, $length);
    }
}

/**
 * A SQLite-backed collection exposing the everyday pymongo API.
 */
class SqliteCollection
{
    private string $quoted;

    public function __construct(private \SQLite3 $conn, private string $name)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("DocStore: invalid collection name '$name'");
        }
        $this->quoted = '"' . $name . '"';
        $this->conn->exec(
            "CREATE TABLE IF NOT EXISTS {$this->quoted} (_id TEXT PRIMARY KEY, doc TEXT NOT NULL)"
        );
    }

    public function quoted(): string
    {
        return $this->quoted;
    }

    // -- helpers --

    private function dump(array $document): string
    {
        return json_encode(DocStoreCodec::encode($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function load(string $docText, ?array $projection = null): array
    {
        $doc = DocStoreCodec::decode(json_decode($docText, true));
        return $projection ? DocStoreCodec::project($doc, $projection) : $doc;
    }

    /**
     * Run an exec, throwing on error so a bad statement never silently no-ops.
     */
    private function exec(string $sql, array $params = []): void
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->execute();
        if ($result === false) {
            throw new \RuntimeException('DocStore: ' . $this->conn->lastErrorMsg());
        }
    }

    private function prepare(string $sql, array $params = []): \SQLite3Stmt
    {
        $stmt = $this->conn->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('DocStore: ' . $this->conn->lastErrorMsg());
        }
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, $this->sqliteType($p));
        }
        return $stmt;
    }

    private function sqliteType(mixed $value): int
    {
        if (is_int($value)) {
            return SQLITE3_INTEGER;
        }
        if (is_float($value)) {
            return SQLITE3_FLOAT;
        }
        if ($value === null) {
            return SQLITE3_NULL;
        }
        return SQLITE3_TEXT;
    }

    /**
     * Yield each `doc` column value for a SELECT doc FROM ... query.
     *
     * @return \Generator<int, string>
     */
    public function runDocQuery(string $sql, array $params): \Generator
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->execute();
        if ($result === false) {
            throw new \RuntimeException('DocStore: ' . $this->conn->lastErrorMsg());
        }
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            yield $row[0];
        }
    }

    /**
     * @return array<int, array{0:string,1:string}>  rows of [_id, doc]
     */
    private function selectIdDoc(string $sql, array $params): array
    {
        $stmt = $this->prepare($sql, $params);
        $result = $stmt->execute();
        if ($result === false) {
            throw new \RuntimeException('DocStore: ' . $this->conn->lastErrorMsg());
        }
        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            $rows[] = [$row[0], $row[1]];
        }
        return $rows;
    }

    // -- writes --

    public function insertOne(array $document): InsertOneResult
    {
        $doc = $document;
        if (!array_key_exists('_id', $doc)) {
            $doc['_id'] = new ObjectId();
        }
        $this->exec(
            "INSERT INTO {$this->quoted} (_id, doc) VALUES (?, ?)",
            [DocStoreCodec::idKey($doc['_id']), $this->dump($doc)]
        );
        return new InsertOneResult($doc['_id']);
    }

    /**
     * @param iterable<array> $documents
     */
    public function insertMany(iterable $documents): InsertManyResult
    {
        $ids = [];
        foreach ($documents as $document) {
            $doc = $document;
            if (!array_key_exists('_id', $doc)) {
                $doc['_id'] = new ObjectId();
            }
            $ids[] = $doc['_id'];
            $this->exec(
                "INSERT INTO {$this->quoted} (_id, doc) VALUES (?, ?)",
                [DocStoreCodec::idKey($doc['_id']), $this->dump($doc)]
            );
        }
        return new InsertManyResult($ids);
    }

    // -- reads --

    public function find(?array $filter = null, ?array $projection = null): Cursor
    {
        [$where, $params] = DocStoreCodec::compileFilter($filter ?? []);
        return new Cursor($this, $where, $params, $projection);
    }

    public function findOne(?array $filter = null, ?array $projection = null): ?array
    {
        $results = $this->find($filter, $projection)->limit(1)->toList();
        return $results[0] ?? null;
    }

    public function countDocuments(?array $filter = null): int
    {
        [$where, $params] = DocStoreCodec::compileFilter($filter ?? []);
        $stmt = $this->prepare("SELECT count(*) FROM {$this->quoted} WHERE $where", $params);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        return (int)$row[0];
    }

    public function estimatedDocumentCount(): int
    {
        $row = $this->conn->querySingle("SELECT count(*) FROM {$this->quoted}");
        return (int)$row;
    }

    /**
     * @return array<int, mixed>
     */
    public function distinct(string $key, ?array $filter = null): array
    {
        $seen = [];
        foreach ($this->find($filter) as $doc) {
            $v = $doc[$key] ?? null;
            $found = false;
            foreach ($seen as $s) {
                if ($s === $v || ($s instanceof ObjectId && $v instanceof ObjectId && $s->equals($v))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $seen[] = $v;
            }
        }
        return $seen;
    }

    // -- updates (filter pushed to SQL; mutation applied per matched doc) --

    private function firstMatch(?array $filter): array
    {
        [$where, $params] = DocStoreCodec::compileFilter($filter ?? []);
        return $this->selectIdDoc("SELECT _id, doc FROM {$this->quoted} WHERE $where LIMIT 1", $params);
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function matchingRows(?array $filter): array
    {
        [$where, $params] = DocStoreCodec::compileFilter($filter ?? []);
        return $this->selectIdDoc("SELECT _id, doc FROM {$this->quoted} WHERE $where", $params);
    }

    public function updateOne(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        $rows = $this->firstMatch($filter);
        if (!$rows) {
            if ($upsert) {
                return $this->doUpsert($filter, $update);
            }
            return new UpdateResult(0, 0);
        }
        [$oldId, $docText] = $rows[0];
        $doc = DocStoreCodec::decode(json_decode($docText, true));
        $newDoc = DocStoreCodec::applyUpdate($doc, $update);
        $modified = $this->writeBack($oldId, $newDoc);
        return new UpdateResult(1, $modified ? 1 : 0);
    }

    public function updateMany(array $filter, array $update, bool $upsert = false): UpdateResult
    {
        $rows = $this->matchingRows($filter);
        if (!$rows && $upsert) {
            return $this->doUpsert($filter, $update);
        }
        $matched = 0;
        $modified = 0;
        foreach ($rows as [$oldId, $docText]) {
            $matched++;
            $doc = DocStoreCodec::decode(json_decode($docText, true));
            $newDoc = DocStoreCodec::applyUpdate($doc, $update);
            if ($this->writeBack($oldId, $newDoc)) {
                $modified++;
            }
        }
        return new UpdateResult($matched, $modified);
    }

    public function replaceOne(array $filter, array $replacement, bool $upsert = false): UpdateResult
    {
        $rows = $this->firstMatch($filter);
        if (!$rows) {
            if ($upsert) {
                $doc = $replacement;
                if (!array_key_exists('_id', $doc)) {
                    $doc['_id'] = new ObjectId();
                }
                $this->insertOne($doc);
                return new UpdateResult(0, 0, $doc['_id']);
            }
            return new UpdateResult(0, 0);
        }
        [$oldId, $docText] = $rows[0];
        $doc = $replacement;
        if (!array_key_exists('_id', $doc)) {
            $existing = DocStoreCodec::decode(json_decode($docText, true));
            $doc['_id'] = $existing['_id'] ?? null;
        }
        $this->writeBack($oldId, $doc);
        return new UpdateResult(1, 1);
    }

    private function writeBack(string $oldId, array $newDoc): bool
    {
        $newKey = DocStoreCodec::idKey($newDoc['_id']);
        $this->exec(
            "UPDATE {$this->quoted} SET _id = ?, doc = ? WHERE _id = ?",
            [$newKey, $this->dump($newDoc), $oldId]
        );
        return true;
    }

    private function doUpsert(array $filter, array $update): UpdateResult
    {
        // Seed a document from the filter's equality terms, then apply the update.
        $seed = [];
        foreach (($filter ?? []) as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '$') {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $seed[$k] = $v;
        }
        $doc = DocStoreCodec::applyUpdate($seed, $update);
        if (!array_key_exists('_id', $doc)) {
            $doc['_id'] = new ObjectId();
        }
        $this->insertOne($doc);
        return new UpdateResult(0, 0, $doc['_id']);
    }

    // -- deletes --

    public function deleteOne(array $filter): DeleteResult
    {
        $rows = $this->firstMatch($filter);
        if (!$rows) {
            return new DeleteResult(0);
        }
        $this->exec("DELETE FROM {$this->quoted} WHERE _id = ?", [$rows[0][0]]);
        return new DeleteResult(1);
    }

    public function deleteMany(array $filter): DeleteResult
    {
        [$where, $params] = DocStoreCodec::compileFilter($filter ?? []);
        $this->exec("DELETE FROM {$this->quoted} WHERE $where", $params);
        return new DeleteResult($this->conn->changes());
    }

    public function drop(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->quoted}");
    }
}

/**
 * A SQLite-backed document database (a file of collection tables).
 */
class SqliteDatabase
{
    public string $path;
    private \SQLite3 $conn;
    /** @var array<string, SqliteCollection> */
    private array $collections = [];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (getenv('TINA4_DOC_STORE_PATH') ?: 'data/tina4_docstore.db');
        if ($this->path !== ':memory:') {
            $directory = dirname($this->path);
            if ($directory && $directory !== '.' && !is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
        }
        $this->conn = new \SQLite3($this->path);
        // Register the REGEXP user function for $regex pushdown.
        $this->conn->createFunction('regexp', [DocStoreCodec::class, 'regexp'], 2);
    }

    public function getCollection(string $name): SqliteCollection
    {
        if (!isset($this->collections[$name])) {
            $this->collections[$name] = new SqliteCollection($this->conn, $name);
        }
        return $this->collections[$name];
    }

    /**
     * @return array<int, string>
     */
    public function listCollectionNames(): array
    {
        $names = [];
        $result = $this->conn->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        );
        while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
            $names[] = $row[0];
        }
        return $names;
    }

    public function close(): void
    {
        $this->conn->close();
    }
}

/**
 * The configured Mongo URI, reusing the app-wide queue/session env vars.
 * Canonical TINA4_SESSION_MONGO_URI; TINA4_SESSION_MONGO_URL is a legacy alias.
 */
function docStoreMongoUri(): string
{
    return trim(
        (getenv('TINA4_MONGO_URI') ?: '')
        ?: (getenv('TINA4_SESSION_MONGO_URI') ?: '')
        ?: (getenv('TINA4_SESSION_MONGO_URL') ?: '')
    );
}

/**
 * True when no Mongo is configured (or the driver is absent), so the SQLite
 * fallback is in effect.
 */
function isServerless(): bool
{
    if (docStoreMongoUri() === '') {
        return true;
    }
    // A URI is set but the ext-mongodb driver is absent: degrade to the local
    // store rather than crash.
    return !class_exists('\MongoDB\Driver\Manager');
}

/** @var SqliteDatabase|null */
$GLOBALS['__tina4_docstore_default'] = $GLOBALS['__tina4_docstore_default'] ?? null;

function docStoreDefaultDb(): SqliteDatabase
{
    if ($GLOBALS['__tina4_docstore_default'] === null) {
        $GLOBALS['__tina4_docstore_default'] = new SqliteDatabase();
    }
    return $GLOBALS['__tina4_docstore_default'];
}

/**
 * Return a collection for $name.
 *
 * A SqliteCollection backed by the local SQLite file when serverless; otherwise
 * a real MongoDB driver collection when a Mongo URI is configured and the
 * ext-mongodb driver is installed. Same call sites either way - only the backend
 * differs.
 *
 * @return SqliteCollection|\MongoDB\Driver\Manager|object
 */
function getCollection(string $name): object
{
    if (isServerless()) {
        return docStoreDefaultDb()->getCollection($name);
    }
    // Real-Mongo path: hand back a MongoDB\Collection if the high-level library
    // is present (it brings its own ObjectId). The SQLite path never needs it.
    $uri = docStoreMongoUri();
    $dbName = (getenv('TINA4_MONGO_DB') ?: '') ?: ((getenv('TINA4_SESSION_MONGO_DB') ?: '') ?: 'tina4');
    if (class_exists('\MongoDB\Client')) {
        $client = new \MongoDB\Client($uri);
        return $client->selectCollection($dbName, $name);
    }
    // Driver present but no high-level library: fall back to the local store.
    return docStoreDefaultDb()->getCollection($name);
}

/**
 * Drop the cached default SQLite store (test helper).
 */
function resetDefaultStore(): void
{
    if ($GLOBALS['__tina4_docstore_default'] !== null) {
        $GLOBALS['__tina4_docstore_default']->close();
    }
    $GLOBALS['__tina4_docstore_default'] = null;
}
