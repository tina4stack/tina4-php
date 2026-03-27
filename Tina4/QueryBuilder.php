<?php

/**
 * Tina4 - This is not a 4ramework.
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
 *   $result = QueryBuilder::from('users', $db)
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
    private array $wheres = [];
    private array $params = [];
    private array $joins = [];
    private array $groupByCols = [];
    private array $havings = [];
    private array $havingParams = [];
    private array $orderByCols = [];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;

    /**
     * Private constructor — use static factory methods.
     */
    private function __construct(string $table, ?DatabaseAdapter $db = null)
    {
        $this->table = $table;
        $this->db = $db;
    }

    /**
     * Create a QueryBuilder for a table.
     *
     * @param string $table Table name.
     * @param DatabaseAdapter|null $db Database adapter (optional).
     * @return self
     */
    public static function from(string $table, ?DatabaseAdapter $db = null): self
    {
        return new self($table, $db);
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
     * @return mixed The result from DatabaseAdapter::fetch().
     */
    public function get(): mixed
    {
        $this->ensureDb();
        $sql = $this->toSql();
        $allParams = array_merge($this->params, $this->havingParams);

        return $this->db->fetch(
            $sql,
            $this->limitVal ?? 100,
            $this->offsetVal ?? 0,
            $allParams
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
        $allParams = array_merge($this->params, $this->havingParams);

        $result = $this->db->fetch(
            $sql,
            1,
            $this->offsetVal ?? 0,
            $allParams
        );

        if (isset($result['data']) && !empty($result['data'])) {
            return $result['data'][0];
        }

        return null;
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
        $this->columns = ['COUNT(*) as cnt'];
        $sql = $this->toSql();
        $this->columns = $original;

        $allParams = array_merge($this->params, $this->havingParams);

        $result = $this->db->fetch(
            $sql,
            1,
            0,
            $allParams
        );

        if (isset($result['data'][0]['cnt'])) {
            return (int)$result['data'][0]['cnt'];
        }
        if (isset($result['data'][0]['CNT'])) {
            return (int)$result['data'][0]['CNT'];
        }

        return 0;
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

        // Fallback
        return [['$where' => $cond], $paramIndex];
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
}
