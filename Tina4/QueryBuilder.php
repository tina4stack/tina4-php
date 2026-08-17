<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;

/**
 * QueryBuilder — Fluent SQL query builder.
 *
 * Usage:
 *   // Standalone
 *   $result = QueryBuilder::fromTable('users', $db)
 *       ->select('id', 'name')
 *       ->where('active = ?', [1])
 *       ->orderBy('name ASC')
 *       ->limit(10)
 *       ->get();
 *
 *   // From ORM model
 *   $result = User::query()
 *       ->where('age > ?', [18])
 *       ->orderBy('name')
 *       ->get();
 */
class QueryBuilder
{
    private string $table;
    private ?DatabaseAdapter $db;
    private array $columns = ['*'];
    private array $selectParams = [];
    private array $wheres = [];
    private array $params = [];
    private array $joins = [];
    private array $groupByCols = [];
    private array $havings = [];
    private array $havingParams = [];
    private array $orderByCols = [];
    private array $orderByParams = [];
    private ?string $primaryKey = null;
    private ?int $limitVal = null;
    private ?int $offsetVal = null;

    /**
     * Private constructor — use static factory methods.
     */
    private function __construct(string $table, ?DatabaseAdapter $db = null, ?string $primaryKey = null)
    {
        $this->table = $table;
        $this->db = $db;
        $this->primaryKey = $primaryKey;
    }

    /**
     * Create a QueryBuilder for a table.
     *
     * @param string $table Table name.
     * @param DatabaseAdapter|null $db Database adapter (optional).
     * @return self
     */
    public static function fromTable(string $table, ?DatabaseAdapter $db = null, ?string $primaryKey = null): self
    {
        return new self($table, $db, $primaryKey);
    }

    /**
     * Set the columns to select.
     *
     * @param string ...$columns Column names.
     * @return $this
     */
    public function select(string ...$columns): self
    {
        if (!empty($columns)) {
            $this->columns = $columns;
            $this->selectParams = [];
        }
        return $this;
    }

    /**
     * Add a WHERE condition (AND).
     *
     * @param string $condition SQL condition with ? placeholders.
     * @param array $params Parameter values.
     * @return $this
     */
    public function where(string $condition, array $params = []): self
    {
        $this->wheres[] = ['AND', $condition];
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * Add a WHERE condition (OR).
     *
     * @param string $condition SQL condition with ? placeholders.
     * @param array $params Parameter values.
     * @return $this
     */
    public function orWhere(string $condition, array $params = []): self
    {
        $this->wheres[] = ['OR', $condition];
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * Add an INNER JOIN.
     *
     * @param string $table Table to join.
     * @param string $on Join condition.
     * @return $this
     */
    public function join(string $table, string $on): self
    {
        $this->joins[] = "INNER JOIN {$table} ON {$on}";
        return $this;
    }

    /**
     * Add a LEFT JOIN.
     *
     * @param string $table Table to join.
     * @param string $on Join condition.
     * @return $this
     */
    public function leftJoin(string $table, string $on): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$on}";
        return $this;
    }

    /**
     * Add a GROUP BY clause.
     *
     * @param string $column Column to group by.
     * @return $this
     */
    public function groupBy(string $column): self
    {
        $this->groupByCols[] = $column;
        return $this;
    }

    /**
     * Add a HAVING clause.
     *
     * @param string $expression HAVING expression with ? placeholders.
     * @param array $params Parameter values.
     * @return $this
     */
    public function having(string $expression, array $params = []): self
    {
        $this->havings[] = $expression;
        $this->havingParams = array_merge($this->havingParams, $params);
        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @param string $expression Column and direction (e.g. "name ASC").
     * @return $this
     */
    public function orderBy(string $expression): self
    {
        $this->orderByCols[] = $expression;
        return $this;
    }

    /** Restrict rows to points within a radius measured in metres. */
    public function withinDistance(string $column, mixed $point, float $radiusMetres, int $srid = Point::DEFAULT_SRID): self
    {
        if (!is_finite($radiusMetres) || $radiusMetres < 0) {
            throw new \InvalidArgumentException('Spatial radius must be a finite number greater than or equal to zero');
        }
        $point = Point::parse($point, $srid);
        return $this->where(
            SQLTranslator::withinDistance($this->engine(), $column, $point->srid),
            [$point->lon, $point->lat, $radiusMetres]
        );
    }

    /** Restrict rows to points intersecting a bound WKT/EWKT or GeoJSON geometry. */
    public function intersects(string $column, mixed $geometry, int $srid = Point::DEFAULT_SRID): self
    {
        [$bound, $form] = Point::geometryBinding($geometry, $srid);
        return $this->where(
            SQLTranslator::intersects($this->engine(), $column, $form, $srid),
            [$bound]
        );
    }

    /** Restrict rows to a longitude/latitude bounding box. */
    public function bbox(string $column, mixed $minLon, mixed $minLat, mixed $maxLon, mixed $maxLat, int $srid = Point::DEFAULT_SRID): self
    {
        $values = [$minLon, $minLat, $maxLon, $maxLat];
        foreach ($values as $value) {
            if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Bounding-box coordinates must be finite numbers');
            }
        }
        [$west, $south, $east, $north] = array_map('floatval', $values);
        new Point($west, $south, $srid);
        new Point($east, $north, $srid);
        if ($west > $east || $south > $north) {
            throw new \InvalidArgumentException('Bounding box must be ordered west, south, east, north');
        }
        return $this->where(SQLTranslator::bbox($this->engine(), $column, $srid), [$west, $south, $east, $north]);
    }

    /** Add an exact PostGIS distance (metres) to the selected columns. */
    public function selectDistance(string $column, mixed $point, string $alias = 'distance', int $srid = Point::DEFAULT_SRID): self
    {
        $point = Point::parse($point, $srid);
        if ($this->columns === ['*']) {
            $this->columns = ['*'];
        }
        $this->columns[] = SQLTranslator::distanceAs($this->engine(), $column, $alias, $point->srid);
        $this->selectParams = array_merge($this->selectParams, [$point->lon, $point->lat]);
        return $this;
    }

    /** Order by exact distance and then the model primary key for stable ties. */
    public function orderByDistance(string $column, mixed $point, string $direction = 'ASC', int $srid = Point::DEFAULT_SRID): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Distance order direction must be ASC or DESC');
        }
        if ($this->primaryKey === null || $this->primaryKey === '') {
            throw new \LogicException('Stable spatial ordering needs a primary key; create the builder through ORM::query() or pass one to fromTable()');
        }
        $point = Point::parse($point, $srid);
        $this->orderByCols[] = SQLTranslator::distance($this->engine(), $column, $point->srid) . " {$direction}";
        $this->orderByParams = array_merge($this->orderByParams, [$point->lon, $point->lat]);
        $this->orderByCols[] = SQLTranslator::spatialIdentifier($this->primaryKey, 'primary key') . ' ASC';
        return $this;
    }

    /**
     * Set LIMIT and optional OFFSET.
     *
     * @param int $count Maximum rows to return.
     * @param int|null $offset Number of rows to skip.
     * @return $this
     */
    public function limit(int $count, ?int $offset = null): self
    {
        $this->limitVal = $count;
        if ($offset !== null) {
            $this->offsetVal = $offset;
        }
        return $this;
    }

    /**
     * Build the SQL string.
     *
     * @return string The constructed SQL query.
     */
    public function toSql(): string
    {
        $sql = "SELECT " . implode(', ', $this->columns) . " FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhere();
        }

        if (!empty($this->groupByCols)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupByCols);
        }

        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if (!empty($this->orderByCols)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderByCols);
        }

        return $sql;
    }

    /**
     * Execute the query and return the database result.
     *
     * v3.13.39: with no ->limit() set, get() returns ALL matching rows. It
     * previously applied a silent default LIMIT 100 — a data-loss-on-read
     * footgun where the 101st row vanished without a trace. An explicit
     * ->limit(n) is still honoured; toSql() never injects a default LIMIT
     * either. Pass 0 to fetch (its "no truncation" sentinel) when no limit was
     * requested.
     *
     * @return mixed The result from DatabaseAdapter::fetch().
     */
    public function get(): mixed
    {
        $this->ensureDb();
        $sql = $this->toSql();
        $allParams = array_merge($this->selectParams, $this->params, $this->havingParams, $this->orderByParams);

        return $this->db->fetch(
            $sql,
            $allParams,
            $this->limitVal ?? 0,
            $this->offsetVal ?? 0
        );
    }

    /**
     * Execute the query and return a single row.
     *
     * @return array|null A single row as an associative array, or null.
     */
    public function first(): ?array
    {
        $this->ensureDb();
        $sql = $this->toSql();
        $allParams = array_merge($this->selectParams, $this->params, $this->havingParams, $this->orderByParams);

        $result = $this->db->fetch(
            $sql,
            $allParams,
            1,
            $this->offsetVal ?? 0
        );

        $records = self::extractRecords($result);

        return $records[0] ?? null;
    }

    /**
     * Execute the query and return the row count.
     *
     * @return int Number of matching rows.
     */
    public function count(): int
    {
        $this->ensureDb();

        // Build a count query by replacing columns with COUNT(*)
        $original = $this->columns;
        $originalSelectParams = $this->selectParams;
        $originalOrder = $this->orderByCols;
        $originalOrderParams = $this->orderByParams;
        $this->columns = ['COUNT(*) as cnt'];
        $this->selectParams = [];
        $this->orderByCols = [];
        $this->orderByParams = [];
        $sql = $this->toSql();
        $this->columns = $original;
        $this->selectParams = $originalSelectParams;
        $this->orderByCols = $originalOrder;
        $this->orderByParams = $originalOrderParams;

        $allParams = array_merge($this->params, $this->havingParams);

        $result = $this->db->fetch(
            $sql,
            $allParams,
            1,
            0
        );

        $records = self::extractRecords($result);
        $row = $records[0] ?? null;

        if (is_array($row)) {
            if (isset($row['cnt'])) {
                return (int)$row['cnt'];
            }
            if (isset($row['CNT'])) {
                return (int)$row['CNT'];
            }
        }

        return 0;
    }

    /**
     * Normalise the value returned by DatabaseAdapter::fetch() into a plain
     * list of row arrays.
     *
     * The Database facade returns a DatabaseResult (which exposes its rows via
     * ->records and integer-indexed ArrayAccess), while a raw adapter returns
     * the ['data' => [...]] array shape. get(), first() and count() must all
     * consume whichever shape they are handed — reading $result['data'] off a
     * DatabaseResult yields null (ArrayAccess only indexes by integer), which
     * is what previously made first() return null and count() return 0 even
     * when rows matched.
     *
     * @param mixed $result Result from DatabaseAdapter::fetch()
     * @return array<int, array<string, mixed>>
     */
    private static function extractRecords(mixed $result): array
    {
        if ($result instanceof \Tina4\Database\DatabaseResult) {
            return $result->records;
        }
        if (is_array($result)) {
            return $result['data'] ?? $result;
        }
        return [];
    }

    /**
     * Check whether any matching rows exist.
     *
     * @return bool True if at least one row matches.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Convert the fluent builder state into a MongoDB-compatible query document.
     *
     * @return array{filter?: array, projection?: array, sort?: array, limit?: int, skip?: int}
     */
    public function toMongo(): array
    {
        $result = [];

        // -- projection --
        if ($this->columns !== ['*']) {
            $projection = [];
            foreach ($this->columns as $col) {
                $projection[trim($col)] = 1;
            }
            $result['projection'] = $projection;
        }

        // -- filter --
        if (!empty($this->wheres)) {
            $paramIndex = 0;
            $andConditions = [];
            $orConditions = [];

            foreach ($this->wheres as $i => [$connector, $condition]) {
                [$mongoCond, $paramIndex] = $this->parseConditionToMongo($condition, $paramIndex);
                if ($i === 0 || $connector === 'AND') {
                    $andConditions[] = $mongoCond;
                } else {
                    $orConditions[] = $mongoCond;
                }
            }

            if (!empty($orConditions)) {
                $andMerged = $this->mergeMongoConditions($andConditions);
                $allBranches = array_merge([$andMerged], $orConditions);
                $result['filter'] = ['$or' => $allBranches];
            } else {
                $result['filter'] = $this->mergeMongoConditions($andConditions);
            }
        }

        // -- sort --
        if (!empty($this->orderByCols)) {
            $sort = [];
            foreach ($this->orderByCols as $expr) {
                $parts = preg_split('/\s+/', trim($expr));
                $field = $parts[0];
                $direction = (isset($parts[1]) && strtoupper($parts[1]) === 'DESC') ? -1 : 1;
                $sort[$field] = $direction;
            }
            $result['sort'] = $sort;
        }

        // -- limit / skip --
        if ($this->limitVal !== null) {
            $result['limit'] = $this->limitVal;
        }
        if ($this->offsetVal !== null) {
            $result['skip'] = $this->offsetVal;
        }

        return $result;
    }

    /**
     * Parse a single SQL condition into a MongoDB filter array.
     *
     * @return array{0: array, 1: int} [mongoCondition, updatedParamIndex]
     */
    private function parseConditionToMongo(string $condition, int $paramIndex): array
    {
        $cond = trim($condition);

        // IS NOT NULL
        if (preg_match('/^(\w+)\s+IS\s+NOT\s+NULL$/i', $cond, $m)) {
            return [[$m[1] => ['$exists' => true, '$ne' => null]], $paramIndex];
        }

        // IS NULL
        if (preg_match('/^(\w+)\s+IS\s+NULL$/i', $cond, $m)) {
            return [[$m[1] => ['$exists' => false]], $paramIndex];
        }

        // NOT IN
        if (preg_match('/^(\w+)\s+NOT\s+IN\s*\(\s*\?\s*\)$/i', $cond, $m)) {
            $val = $this->params[$paramIndex] ?? [];
            $values = is_array($val) ? $val : [$val];
            return [[$m[1] => ['$nin' => $values]], $paramIndex + 1];
        }

        // IN
        if (preg_match('/^(\w+)\s+IN\s*\(\s*\?\s*\)$/i', $cond, $m)) {
            $val = $this->params[$paramIndex] ?? [];
            $values = is_array($val) ? $val : [$val];
            return [[$m[1] => ['$in' => $values]], $paramIndex + 1];
        }

        // LIKE
        if (preg_match('/^(\w+)\s+LIKE\s+\?$/i', $cond, $m)) {
            $val = (string)($this->params[$paramIndex] ?? '');
            $pattern = str_replace(['%', '_'], ['.*', '.'], $val);
            return [[$m[1] => ['$regex' => $pattern, '$options' => 'i']], $paramIndex + 1];
        }

        // Comparison operators: >=, <=, <>, !=, >, <, =
        if (preg_match('/^(\w+)\s*(>=|<=|<>|!=|>|<|=)\s*\?$/', $cond, $m)) {
            $field = $m[1];
            $op = $m[2];
            $val = $this->params[$paramIndex] ?? null;

            $opMap = [
                '='  => null,
                '!=' => '$ne',
                '<>' => '$ne',
                '>'  => '$gt',
                '>=' => '$gte',
                '<'  => '$lt',
                '<=' => '$lte',
            ];

            $mongoOp = $opMap[$op] ?? null;
            if ($mongoOp === null) {
                return [[$field => $val], $paramIndex + 1];
            }
            return [[$field => [$mongoOp => $val]], $paramIndex + 1];
        }

        // v3.13.39: no silent $where fallback. Previously an unparseable
        // condition was wrapped as ['$where' => <raw condition string>] — a
        // raw-JS sink that is both injection-shaped (the WHERE string runs as
        // JavaScript on the MongoDB server) and silently different semantics
        // from the SQL the caller wrote. Fail loud instead: name the clause so
        // the caller fixes it rather than shipping a surprise $where.
        throw new \InvalidArgumentException(
            "QueryBuilder::toMongo(): cannot translate WHERE clause to a MongoDB "
            . "filter: '{$cond}'. Supported forms: '<field> <op> ?' "
            . "(=, !=, <>, >, >=, <, <=), '<field> LIKE ?', "
            . "'<field> [NOT] IN (?)', '<field> IS [NOT] NULL'. "
            . "Rewrite the condition in one of those forms (toMongo() will not "
            . "silently emit a raw \$where JavaScript expression)."
        );
    }

    /**
     * Merge multiple single-field mongo conditions into one array.
     * Uses $and if field keys conflict.
     */
    private function mergeMongoConditions(array $conditions): array
    {
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        $merged = [];
        $hasConflict = false;

        foreach ($conditions as $cond) {
            foreach ($cond as $key => $val) {
                if (array_key_exists($key, $merged)) {
                    $hasConflict = true;
                    break 2;
                }
                $merged[$key] = $val;
            }
        }

        if ($hasConflict) {
            return ['$and' => $conditions];
        }

        return $merged;
    }

    /**
     * Build the WHERE clause from accumulated conditions.
     */
    private function buildWhere(): string
    {
        $parts = [];
        foreach ($this->wheres as $index => $entry) {
            [$connector, $condition] = $entry;
            if ($index === 0) {
                $parts[] = $condition;
            } else {
                $parts[] = "{$connector} {$condition}";
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Ensure a database adapter is available.
     *
     * @throws \RuntimeException If no database adapter is set.
     */
    private function ensureDb(): void
    {
        if ($this->db === null) {
            throw new \RuntimeException('QueryBuilder: No database adapter provided.');
        }
    }

    private function engine(): string
    {
        $this->ensureDb();
        return $this->db->getDatabaseType();
    }
}
