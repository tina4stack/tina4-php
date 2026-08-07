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
 *     $oid = $orders->insertOne(["customer_id" => 1, "total" => 9.99])->getInsertedId();
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
 * A configured URI with NO driver installed throws DocStoreDriverMissing
 * (ADR-0033). It does NOT quietly use the local SQLite store: that turns a
 * production write into a write to a container-local file nobody reads, which
 * vanishes on the next deploy, with no error at any point. PHP needs both the
 * 'mongodb' extension and the mongodb/mongodb composer library, and the throw
 * names whichever is missing.
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
     * Decodes a stored JSON document, rehydrating ObjectId and DateTime values.
     *
     * Lives on the codec, not the collection, because it is a PURE function of
     * its inputs. It was a public collection method only so the Cursor could
     * reach it, which ADR-0025 corollary 1 forbids.
     *
     * @param string     $docText    The stored JSON document.
     * @param array|null $projection Optional projection spec.
     * @return array The decoded document.
     */
    public static function loadDoc(string $docText, ?array $projection = null): array
    {
        $doc = self::decode(json_decode($docText, true));
        return $projection ? self::project($doc, $projection) : $doc;
    }

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

    /** A rowset over the field: one row per element of an array, one for a scalar. */
    public static function each(string $field): string
    {
        return "json_each(doc, '" . self::path($field) . "')";
    }

    /**
     * True when the field is an ARRAY and any element satisfies $condition.
     *
     * MongoDB's rule for an array-valued field is that a condition matches when
     * ANY ELEMENT matches it. json_each yields one row per element, so EXISTS is
     * the direct translation.
     *
     * The `= 'array'` guard is load-bearing: json_each over an OBJECT iterates
     * its VALUES, and Mongo never matches an object field against one of its
     * values - ['obj' => 'x'] must NOT match {"obj":{"city":"x"}}.
     *
     * @param string $field     The document field.
     * @param string $condition SQL predicate over the json_each `value` column.
     * @return string The SQL fragment.
     */
    public static function anyElement(string $field, string $condition): string
    {
        return '(' . self::jsonType($field) . " = 'array' AND EXISTS (SELECT 1 FROM "
            . self::each($field) . " WHERE $condition))";
    }

    /**
     * Compile `field == operand` under Mongo's array rule.
     *
     * @param string $field   The document field.
     * @param mixed  $operand The value to compare against.
     * @return array{0: string, 1: array} SQL fragment and its bound parameters.
     */
    public static function equality(string $field, mixed $operand): array
    {
        $ex = self::extract($field);
        if ($operand === null) {
            return ["$ex IS NULL", []];
        }
        if (is_array($operand)) {
            // An array or object operand compares against the WHOLE value,
            // never element-wise: ['tags' => ['x','y']] is exact-array equality.
            return ["$ex = ?", [self::bind($operand)]];
        }

        return ["($ex = ? OR " . self::anyElement($field, 'value = ?') . ')',
            [self::bind($operand), self::bind($operand)]];
    }

    /**
     * Compile `field OP operand` under Mongo's array rule.
     *
     * The `<> 'array'` guard on the scalar branch removes a measured FALSE
     * POSITIVE: json_extract of an array returns its JSON TEXT, and SQLite sorts
     * any text above any number, so ['nums' => ['$gt' => 9]] matched [1,2,3].
     *
     * @param string $field   The document field.
     * @param string $sqlOp   The SQL comparison operator.
     * @param mixed  $operand The value to compare against.
     * @return array{0: string, 1: array} SQL fragment and its bound parameters.
     */
    public static function compare(string $field, string $sqlOp, mixed $operand): array
    {
        $ex = self::extract($field);
        return ['((' . self::jsonType($field) . " <> 'array' AND $ex $sqlOp ?) OR "
            . self::anyElement($field, "value $sqlOp ?") . ')',
            [self::bind($operand), self::bind($operand)]];
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
                // equality - the same helper $eq uses, so the array rule applies
                // whether the filter reads ['tags' => 'x'] or ['tags' => ['$eq' => 'x']]
                [$frag, $p] = self::equality($key, $value);
                $clauses[] = $frag;
                array_push($params, ...$p);
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
            return self::compare($field, self::COMPARATORS[$op], $operand);
        }
        if ($op === '$eq') {
            return self::equality($field, $operand);
        }
        if ($op === '$ne') {
            if ($operand === null) {
                return ["$ex IS NOT NULL", []];
            }
            [$sql, $p] = self::equality($field, $operand);
            // A MISSING field satisfies $ne in Mongo, and SQL's NOT(NULL) is NULL
            // rather than true - so the IS NULL arm is required, not decoration.
            return ["(NOT ($sql) OR $ex IS NULL)", $p];
        }
        if ($op === '$in') {
            $items = array_values((array)$operand);
            if (!$items) {
                return ['0', []];
            }
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            $bound = array_map([self::class, 'bind'], $items);
            return ["($ex IN ($placeholders) OR "
                . self::anyElement($field, "value IN ($placeholders)") . ')',
                array_merge($bound, $bound)];
        }
        if ($op === '$nin') {
            $items = array_values((array)$operand);
            if (!$items) {
                return ['1', []];
            }
            $placeholders = implode(',', array_fill(0, count($items), '?'));
            $bound = array_map([self::class, 'bind'], $items);
            return ["(NOT ($ex IN ($placeholders) OR "
                . self::anyElement($field, "value IN ($placeholders)") . ") OR $ex IS NULL)",
                array_merge($bound, $bound)];
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
            return ['((' . self::jsonType($field) . " <> 'array' AND $ex REGEXP ?) OR "
                . self::anyElement($field, 'value REGEXP ?') . ')',
                [(string)$pattern, (string)$pattern]];
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
 * Read a result's value by its uniform Tina4 property spelling.
 *
 * The result objects keep the driver's GETTERS as the authoritative accessors
 * (ADR-0025 corollary 2, unchanged). This adds the property spelling ALONGSIDE
 * them, because DocStoreDelegator supplies the same spelling on the real
 * driver's result objects - so `$result->insertedId` and
 * `$result->getInsertedId()` both answer, on both providers (ADR-0035).
 *
 * The backing properties stay PRIVATE. A public property would be readable
 * without passing through this trait and would reappear in the reflection the
 * contract harness runs, so one mechanism stays one mechanism.
 */
trait DocStoreResultAccessors
{
    /**
     * Resolves a uniform property name to the driver-spelled getter behind it.
     *
     * @param string $name The property name, e.g. insertedId.
     * @return mixed The accessor's value.
     * @throws \InvalidArgumentException When no accessor of that name exists.
     */
    public function __get(string $name): mixed
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }

        throw new \InvalidArgumentException(
            'Tina4 DocStore: ' . static::class . " has no accessor '{$name}'. "
            . 'Available: ' . implode(', ', self::accessorNames()) . '.'
        );
    }

    /**
     * Reports whether a uniform property name resolves to a non-null value.
     *
     * @param string $name The property name, e.g. insertedId.
     * @return bool True when the accessor exists and its value is not null.
     */
    public function __isset(string $name): bool
    {
        $getter = 'get' . ucfirst($name);

        return method_exists($this, $getter) && $this->$getter() !== null;
    }

    /**
     * Returns the uniform property names this result answers to.
     *
     * @return array<int, string> The accessor names, e.g. ['insertedId'].
     */
    private static function accessorNames(): array
    {
        $names = [];
        foreach (get_class_methods(static::class) as $method) {
            if (str_starts_with($method, 'get') && strlen($method) > 3) {
                $names[] = lcfirst(substr($method, 3));
            }
        }

        return $names;
    }
}

/**
 * Result of an insertOne call.
 *
 * The GETTERS are authoritative, because that is what MongoDB\InsertOneResult
 * exposes and ADR-0025 makes the driver the shape the fallback imitates. The
 * backing properties are private; the uniform `->insertedId` spelling is
 * supplied by DocStoreResultAccessors on this side and by DocStoreDelegator on
 * the driver side, so one spelling works on both (ADR-0035).
 */
class InsertOneResult
{
    use DocStoreResultAccessors;

    public function __construct(private readonly mixed $insertedId)
    {
    }

    /**
     * Returns the _id of the inserted document.
     *
     * @return mixed The generated or supplied document id.
     */
    public function getInsertedId(): mixed
    {
        return $this->insertedId;
    }
}

/**
 * Result of an insertMany call.
 */
class InsertManyResult
{
    use DocStoreResultAccessors;

    /** @param array $insertedIds */
    public function __construct(private readonly array $insertedIds)
    {
    }

    /**
     * Returns the _ids of the inserted documents, in insertion order.
     *
     * @return array The generated or supplied document ids.
     */
    public function getInsertedIds(): array
    {
        return $this->insertedIds;
    }
}

/**
 * Result of an update / replace call.
 */
class UpdateResult
{
    use DocStoreResultAccessors;

    public function __construct(
        private readonly int $matchedCount,
        private readonly int $modifiedCount,
        private readonly mixed $upsertedId = null
    ) {
    }

    /**
     * Returns how many documents the filter matched.
     *
     * @return int The matched count.
     */
    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    /**
     * Returns how many documents were actually changed.
     *
     * @return int The modified count, which is <= the matched count.
     */
    public function getModifiedCount(): int
    {
        return $this->modifiedCount;
    }

    /**
     * Returns the _id of the document created by an upsert.
     *
     * @return mixed The upserted id, or null when no document was upserted.
     */
    public function getUpsertedId(): mixed
    {
        return $this->upsertedId;
    }
}

/**
 * Result of a delete call.
 */
class DeleteResult
{
    use DocStoreResultAccessors;

    public function __construct(private readonly int $deletedCount)
    {
    }

    /**
     * Returns how many documents were removed.
     *
     * @return int The deleted count.
     */
    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }
}

/**
 * Normalise the driver's three sort spellings to a list of [key, direction].
 *
 * ADR-0036. The framework documents `sort('total', -1)`; a list of pairs is
 * what pymongo and the Node driver also take; and a MAP (`['total' => -1]`) is
 * what a MongoDB sort document actually is. Measured 2026-08-04 against a real
 * MongoDB, the map spelling raised
 *
 *     TypeError: DocStoreCodec::extract(): Argument #1 ($field) must be of type string
 *
 * on the fallback while working on the driver - the same defect class this ADR
 * closes, one layer down. All three now mean the same thing on both providers.
 *
 * @param string|array $keyOrList A field name, a list of [field, direction] pairs, or a field => direction map.
 * @param int          $direction The direction, used only with a string field name.
 * @return array<int, array{0:string,1:int}> The normalised [field, direction] pairs.
 */
function docStoreSortSpec(string|array $keyOrList, int $direction = 1): array
{
    if (is_string($keyOrList)) {
        return [[$keyOrList, $direction]];
    }

    $spec = [];
    foreach ($keyOrList as $key => $value) {
        // A LIST of pairs has integer keys and array values; a MAP has the
        // field name as the key. Both are legal driver input, so both resolve
        // here rather than at four call sites.
        if (is_int($key) && is_array($value)) {
            $spec[] = [(string) $value[0], (int) $value[1]];
        } else {
            $spec[] = [(string) $key, (int) $value];
        }
    }

    return $spec;
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

    /**
     * The cursor receives WHAT IT NEEDS, not the collection it came from.
     *
     * It used to hold the collection and reach back into it for quoted(),
     * runDocQuery() and load() - which was the ONLY reason those three were
     * public. ADR-0025 corollary 1: anything the fallback needs internally is
     * private, and a real MongoDB\Driver\Cursor exposes none of them.
     *
     * @param string     $quoted     The quoted table name.
     * @param string     $where      The compiled WHERE fragment.
     * @param array      $params     Bound parameters for the WHERE.
     * @param array|null $projection Optional projection spec.
     * @param \Closure   $runQuery   fn(string $sql, array $params): \Generator
     */
    public function __construct(
        private readonly string $quoted,
        private readonly string $where,
        private readonly array $params,
        private readonly ?array $projection,
        private readonly \Closure $runQuery
    ) {
    }

    /**
     * Orders the result. Accepts all three driver spellings (ADR-0036).
     *
     * @param string|array $keyOrList A field name, a list of [field, direction] pairs, or a field => direction map.
     * @param int          $direction The direction, used only with a string field name.
     * @return self This cursor, for chaining.
     */
    public function sort(string|array $keyOrList, int $direction = 1): self
    {
        foreach (docStoreSortSpec($keyOrList, $direction) as $pair) {
            $this->sort[] = $pair;
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

    private function buildSql(): string
    {
        $sql = "SELECT doc FROM {$this->quoted} WHERE {$this->where}";
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
        foreach (($this->runQuery)($this->buildSql(), $this->params) as $docText) {
            yield DocStoreCodec::loadDoc($docText, $this->projection);
        }
    }

    /**
     * Materialise the cursor into a list of decoded documents.
     *
     * Named toArray because that is what MongoDB\Driver\Cursor spells it. The
     * old toList had no counterpart on the driver, so code written against it
     * broke the moment TINA4_MONGO_URI was set (ADR-0025 corollary 1).
     *
     * @param int|null $length Optional cap on the number of documents returned.
     * @return array<int, array> The decoded documents.
     */
    public function toArray(?int $length = null): array
    {
        $out = iterator_to_array($this->getIterator(), false);
        return $length === null ? $out : array_slice($out, 0, $length);
    }

    /**
     * The uniform Tina4 spelling, ADDITIVE to toArray (ADR-0035).
     *
     * ADR-0025 corollary 1 removed this because MongoDB\Driver\Cursor has no
     * toList. ADR-0035 amends that corollary: a method may exist here when it
     * also exists on WHAT getCollection RETURNS for the real provider, and
     * DocStoreDelegator supplies it there. toArray stays - it is the driver's
     * spelling - so both work on both providers.
     *
     * @param int|null $length Optional cap on the number of documents returned.
     * @return array<int, array> The decoded documents.
     */
    public function toList(?int $length = null): array
    {
        return $this->toArray($length);
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

    // -- helpers --

    private function dump(array $document): string
    {
        return json_encode(DocStoreCodec::encode($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    private function runDocQuery(string $sql, array $params): \Generator
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
        return new Cursor(
            $this->quoted,
            $where,
            $params,
            $projection,
            fn (string $sql, array $p): \Generator => $this->runDocQuery($sql, $p)
        );
    }

    public function findOne(?array $filter = null, ?array $projection = null): ?array
    {
        $results = $this->find($filter, $projection)->limit(1)->toArray();
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
                if ($this->valueEquals($s, $v)) {
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

    /**
     * Value-equality for distinct(): ObjectId by 24-hex and DateTime by UTC
     * instant, everything else by ===. Decoded documents rehydrate ObjectId and
     * DateTime as fresh objects, so identity (===) would treat two equal values
     * as distinct; this normalises them the same way the Node/Ruby/Python
     * masters do so the dedup is by value on every provider.
     *
     * @param mixed $a First value.
     * @param mixed $b Second value.
     * @return bool True when the two values are equal by value.
     */
    private function valueEquals(mixed $a, mixed $b): bool
    {
        if ($a instanceof ObjectId && $b instanceof ObjectId) {
            return $a->equals($b);
        }
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return DocStoreCodec::iso($a) === DocStoreCodec::iso($b);
        }
        return $a === $b;
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
 * A Mongo URI is configured but the MongoDB driver is not installed.
 *
 * ADR-0024 rule 3, settled for DocStore by ADR-0033: a provider that cannot
 * honour an operation must RAISE, naming the provider and what is missing.
 * Falling back to the local SQLite store here would send production writes to
 * a container-local file nobody reads.
 */
class DocStoreDriverMissing extends \RuntimeException
{
}

/**
 * A DEFERRED find() on the real MongoDB driver (ADR-0036).
 *
 * WHY THIS EXISTS
 *
 * The fallback's Cursor is lazy and chainable: find() builds no SQL, sort() /
 * limit() / skip() accumulate, and the query runs on iteration. PHP's driver is
 * the opposite - MongoDB\Collection::find($filter, $options) EXECUTES, and the
 * MongoDB\Driver\Cursor it returns has no sort(), limit() or skip() at all,
 * because by then the query is already on the wire.
 *
 * So the framework's own documented example
 *
 *     $orders->find(['total' => ['$gt' => 5]])->sort('total', -1)->limit(10)
 *
 * worked on the SQLite fallback and FATALLED on a real MongoDB with
 * "Call to undefined method MongoDB\Driver\Cursor::sort()". Measured
 * 2026-08-04. That is ADR-0025's defect class - a spelling that works on the
 * fallback and breaks on the swap - and it also broke the First Principle,
 * because it is a PUBLISHED example that does not run.
 *
 * The fix is to defer: this object accumulates the chain and only calls
 * find($filter, $options) when it is actually materialised, which is the shape
 * PHP's driver wants anyway. Ruby's View and pymongo's Cursor are both already
 * lazy, so this brings PHP to the shape the other three already have.
 *
 * ITERATION SEMANTICS are the fallback's, deliberately: the chain issues NO
 * query, materialising issues exactly ONE, and iterating a second time issues
 * another (a MongoDB\Driver\Cursor can only be walked once, and the fallback
 * re-runs its SQL per iteration). Nothing is buffered, so a large unlimited
 * result still streams.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class DocStoreQuery implements \IteratorAggregate
{
    /** @var array<string, int> field => direction, in insertion order */
    private array $sort = [];
    private ?int $limit = null;
    private int $skip = 0;
    /** Memoised only for __call, never for iteration - see the class docblock. */
    private ?object $executed = null;

    /**
     * @param object $collection The real MongoDB\Collection.
     * @param array  $filter     The query filter.
     * @param array  $options    Driver options carried from find(), e.g. projection.
     */
    public function __construct(
        private readonly object $collection,
        private readonly array $filter,
        private readonly array $options = []
    ) {
    }

    /**
     * Orders the result. Accepts all three driver spellings (ADR-0036).
     *
     * @param string|array $keyOrList A field name, a list of [field, direction] pairs, or a field => direction map.
     * @param int          $direction The direction, used only with a string field name.
     * @return self This query, for chaining.
     */
    public function sort(string|array $keyOrList, int $direction = 1): self
    {
        foreach (docStoreSortSpec($keyOrList, $direction) as [$field, $fieldDirection]) {
            $this->sort[$field] = $fieldDirection;
        }
        $this->executed = null;

        return $this;
    }

    /**
     * Caps the number of documents returned.
     *
     * @param int $n The maximum number of documents.
     * @return self This query, for chaining.
     */
    public function limit(int $n): self
    {
        $this->limit = $n;
        $this->executed = null;

        return $this;
    }

    /**
     * Skips the first N documents.
     *
     * @param int $n How many documents to skip.
     * @return self This query, for chaining.
     */
    public function skip(int $n): self
    {
        $this->skip = $n;
        $this->executed = null;

        return $this;
    }

    /**
     * Runs the query and returns a live driver cursor.
     *
     * @return object A MongoDB\Driver\Cursor.
     */
    private function run(): object
    {
        $options = $this->options;
        if ($this->sort !== []) {
            $options['sort'] = $this->sort;
        }
        if ($this->limit !== null) {
            $options['limit'] = $this->limit;
        }
        if ($this->skip !== 0) {
            $options['skip'] = $this->skip;
        }

        return $this->collection->find($this->filter, $options);
    }

    /**
     * Iterates the documents, running the query on first use.
     *
     * @return \Traversable The driver's cursor.
     */
    public function getIterator(): \Traversable
    {
        return $this->run();
    }

    /**
     * Materialises the query into a list of documents.
     *
     * @param int|null $length Optional cap on the number of documents returned.
     * @return array<int, mixed> The documents.
     */
    public function toArray(?int $length = null): array
    {
        $out = $this->run()->toArray();

        return $length === null ? $out : array_slice($out, 0, $length);
    }

    /**
     * The uniform Tina4 spelling, ADDITIVE to toArray (ADR-0035).
     *
     * @param int|null $length Optional cap on the number of documents returned.
     * @return array<int, mixed> The documents.
     */
    public function toList(?int $length = null): array
    {
        return $this->toArray($length);
    }

    /**
     * Forwards any other driver-cursor call, so nothing becomes unreachable.
     *
     * The cursor is memoised HERE only, so repeated calls such as getId() plus
     * setTypeMap() act on one cursor rather than issuing a query each time.
     * Chaining sort/limit/skip discards it, because the chain no longer
     * describes that cursor.
     *
     * @param string $name      The method being called.
     * @param array  $arguments The arguments to forward.
     * @return mixed The driver's return value.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->executed ??= $this->run();

        return $this->executed->$name(...$arguments);
    }
}

/**
 * The driver delegator (ADR-0035).
 *
 * ADR-0025 costed "wrap the driver" as a hand-written FACADE over 12-14 methods
 * and rejected it because that makes aggregate, bulkWrite, createIndex, watch,
 * sessions and transactions unreachable. That objection is fatal to a facade
 * and irrelevant to a DELEGATOR: __call forwards EVERYTHING untouched, so the
 * whole driver surface stays reachable and we add exactly two members.
 *
 * What it adds:
 *   - toList()      the uniform cursor spelling, alongside the driver's toArray()
 *   - ->insertedId  the uniform result spelling, alongside getInsertedId()
 *
 * Both exist natively on the SQLite fallback, so one spelling works on both
 * providers - which is what ADR-0025 corollary 1 wanted and ADR-0035 supplies
 * instead of deleting.
 *
 * Wrapping is applied to what the driver RETURNS as well, because a cursor and
 * a result object are where those two spellings live. BSON values are never
 * wrapped: a BSONDocument is DATA a call site subscripts, not an API surface,
 * and wrapping it would break `$doc['field']`.
 *
 * @implements \IteratorAggregate<mixed, mixed>
 */
final class DocStoreDelegator implements \IteratorAggregate
{
    public function __construct(private readonly object $target)
    {
    }

    /**
     * Returns the wrapped driver object, for code that needs it unadorned.
     *
     * @return object The real MongoDB driver object.
     */
    public function unwrap(): object
    {
        return $this->target;
    }

    /**
     * Forwards any driver call untouched, wrapping an API object it returns.
     *
     * @param string $name      The method being called.
     * @param array  $arguments The arguments to forward.
     * @return mixed The driver's return value, wrapped when it is an API object.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return self::wrap($this->target->$name(...$arguments));
    }

    /**
     * Returns a DEFERRED, chainable query instead of an executed cursor.
     *
     * This is the ONE method the delegator does not simply forward, and
     * ADR-0036 says why: MongoDB\Collection::find() executes immediately and
     * hands back a cursor with no sort/limit/skip, so the chain the fallback
     * supports - and the framework documents - fatalled on the real provider.
     * Deferring makes one spelling work on both.
     *
     * Only reached on a COLLECTION; the delegator also wraps cursors and result
     * objects, and calling find() on one of those is already an error.
     *
     * @param array|null $filter  The query filter.
     * @param array|null $options Driver options, e.g. ['projection' => [...]].
     * @return DocStoreQuery The deferred query.
     */
    public function find(?array $filter = null, ?array $options = null): DocStoreQuery
    {
        return new DocStoreQuery($this->target, $filter ?? [], $options ?? []);
    }

    /**
     * Reads the uniform name of a driver getter, or a public driver property.
     *
     * The GETTER is tried first, and that ordering is load-bearing rather than
     * stylistic: MEASURED against ext-mongodb + mongodb/mongodb 1.x, a real
     * MongoDB\InsertOneResult has a PRIVATE property literally named
     * $insertedId behind getInsertedId(). property_exists() answers true for a
     * private property, so a property-first order reached it and PHP raised
     * "Cannot access private property" - on the real provider only.
     *
     * Public properties are detected with get_object_vars(), which from outside
     * the class returns exactly the ones a call site could have read itself.
     *
     * @param string $name The property name, e.g. insertedId.
     * @return mixed The accessor or property value.
     * @throws \InvalidArgumentException When neither resolves.
     */
    public function __get(string $name): mixed
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this->target, $getter)) {
            return self::wrap($this->target->$getter());
        }
        if (array_key_exists($name, get_object_vars($this->target))) {
            return self::wrap($this->target->$name);
        }

        throw new \InvalidArgumentException(
            'Tina4 DocStore: ' . get_class($this->target) . " has no accessor or public property '{$name}'."
        );
    }

    /**
     * Reports whether a uniform getter name or public property resolves.
     *
     * @param string $name The property name, e.g. insertedId.
     * @return bool True when the value resolves and is not null.
     */
    public function __isset(string $name): bool
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this->target, $getter)) {
            return $this->target->$getter() !== null;
        }

        return isset(get_object_vars($this->target)[$name]);
    }

    /**
     * Materialises a cursor into a list of documents.
     *
     * The uniform Tina4 spelling. MongoDB\Driver\Cursor::toArray() takes no
     * length argument, so the cap is applied here rather than pushed down.
     *
     * @param int|null $length Optional cap on the number of documents returned.
     * @return array<int, mixed> The documents.
     * @throws \LogicException When the wrapped object is not cursor-like.
     */
    public function toList(?int $length = null): array
    {
        if (method_exists($this->target, 'toList')) {
            return $this->target->toList($length);
        }
        if (!method_exists($this->target, 'toArray')) {
            throw new \LogicException(
                'Tina4 DocStore: ' . get_class($this->target) . ' is not a cursor, so toList() has no meaning.'
            );
        }

        $out = $this->target->toArray();

        return $length === null ? $out : array_slice($out, 0, $length);
    }

    /**
     * Iterates the wrapped cursor, so foreach works through the delegator.
     *
     * @return \Traversable The driver's own iterator.
     * @throws \LogicException When the wrapped object is not traversable.
     */
    public function getIterator(): \Traversable
    {
        if ($this->target instanceof \IteratorAggregate) {
            return $this->target->getIterator();
        }
        if ($this->target instanceof \Traversable) {
            return $this->target;
        }

        throw new \LogicException(
            'Tina4 DocStore: ' . get_class($this->target) . ' is not traversable.'
        );
    }

    /**
     * Wraps an API object, and leaves everything else exactly as it was.
     *
     * A BSON value is DATA - a document a call site subscripts, an ObjectId it
     * compares - so wrapping one would break `$doc['field']` and buy nothing.
     * Everything else the driver hands back is an API surface (a cursor, a
     * result, another collection), and those are what carry the uniform
     * spellings.
     *
     * @param mixed $value The driver's return value.
     * @return mixed The value, wrapped when it is an API object.
     */
    private static function wrap(mixed $value): mixed
    {
        if (!is_object($value)) {
            return $value;
        }
        if ($value instanceof self || $value instanceof \ArrayAccess) {
            return $value;
        }
        if (interface_exists('\MongoDB\BSON\Type') && $value instanceof \MongoDB\BSON\Type) {
            return $value;
        }

        return new self($value);
    }
}

/**
 * The env var that supplied the URI, or '' when none did.
 *
 * Named separately so an error can tell the operator WHICH variable to unset
 * without ever printing its value - a Mongo URI routinely carries
 * `user:password@`.
 *
 * TINA4_MONGO_URI is the canonical app-wide name; TINA4_SESSION_MONGO_URI is
 * the session-layer name; TINA4_SESSION_MONGO_URL is a legacy alias.
 */
function docStoreMongoUriSource(): string
{
    foreach (['TINA4_MONGO_URI', 'TINA4_SESSION_MONGO_URI', 'TINA4_SESSION_MONGO_URL'] as $name) {
        if (trim((string) (getenv($name) ?: '')) !== '') {
            return $name;
        }
    }

    return '';
}

/**
 * The configured Mongo URI, reusing the app-wide queue/session env vars.
 */
function docStoreMongoUri(): string
{
    $source = docStoreMongoUriSource();

    return $source === '' ? '' : trim((string) getenv($source));
}

/**
 * True when no Mongo is configured, so the SQLite fallback is in effect.
 *
 * CONFIGURATION ONLY. Before 3.13.95 this also returned true when a URI was set
 * but ext-mongodb was absent, and that is precisely what made getCollection()
 * hand back the local SQLite store while the operator believed they were on
 * Mongo. A missing driver is now an error (ADR-0033), not a second way to be
 * serverless - otherwise an app branching on this would take the local path and
 * never reach the throw.
 */
function isServerless(): bool
{
    return docStoreMongoUri() === '';
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
 * The Mongo collection is handed back through DocStoreDelegator, which adds the
 * uniform Tina4 spellings and forwards everything else untouched (ADR-0035).
 * What this function RETURNS is the surface a call site sees, so it is the
 * surface the contract compares.
 *
 * @return SqliteCollection|DocStoreDelegator|object
 */
function getCollection(string $name): object
{
    if (isServerless()) {
        return docStoreDefaultDb()->getCollection($name);
    }

    // A missing driver is resolved HERE, before any socket is opened: it is a
    // static fact and needs no network to establish. PHP needs TWO pieces and
    // each can go missing on its own, so each is named separately - telling an
    // operator with the extension installed to "install the driver" sends them
    // looking in the wrong place.
    $source = docStoreMongoUriSource() ?: 'TINA4_MONGO_URI';
    if (!class_exists('\MongoDB\Driver\Manager')) {
        throw new DocStoreDriverMissing(
            "Tina4 DocStore: {$source} is set, so the MongoDB provider is selected, but its "
            . "driver is not installed (PHP extension 'mongodb'). Install it with "
            . "`pecl install mongodb`, or unset {$source} to use the local SQLite store."
        );
    }
    if (!class_exists('\MongoDB\Client')) {
        throw new DocStoreDriverMissing(
            "Tina4 DocStore: {$source} is set, so the MongoDB provider is selected, and the "
            . "'mongodb' PHP extension is loaded, but the high-level library is not installed "
            . "(composer package 'mongodb/mongodb'). Install it with "
            . "`composer require mongodb/mongodb`, or unset {$source} to use the local SQLite store."
        );
    }

    // Real-Mongo path: the high-level library brings its own ObjectId. The
    // SQLite path never needs it.
    $uri = docStoreMongoUri();
    $dbName = (getenv('TINA4_MONGO_DB') ?: '') ?: ((getenv('TINA4_SESSION_MONGO_DB') ?: '') ?: 'tina4');
    $client = new \MongoDB\Client($uri);

    return new DocStoreDelegator($client->selectCollection($dbName, $name));
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
