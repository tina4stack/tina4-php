<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * MongoDB database adapter — uses the mongodb PHP extension + mongodb/mongodb composer package.
 *
 * Connection string formats:
 *   mongodb://host:port/dbname
 *   mongodb://user:pass@host:port/dbname
 *   pymongo://host:port/dbname   (alias for compatibility with Python convention)
 *
 * Requires:
 *   pecl install mongodb
 *   composer require mongodb/mongodb
 *
 * SQL translation notes:
 *   - SELECT   → collection->find() with projection, sort, skip, limit
 *   - INSERT   → collection->insertOne() / insertMany()
 *   - UPDATE   → collection->updateMany()
 *   - DELETE   → collection->deleteMany()
 *   - CREATE TABLE → collection->createCollection() (columns are ignored)
 *
 * Transactions use MongoDB multi-document sessions (requires replica set or mongos).
 * On standalone servers, startTransaction() / commit() / rollback() are no-ops.
 *
 * @phpstan-type MongoFilter array<string, mixed>
 */
class MongoDBAdapter implements DatabaseAdapter
{
    /** @var \MongoDB\Client|null */
    private ?object $client = null;

    /** @var \MongoDB\Database|null */
    private ?object $db = null;

    /** @var \MongoDB\Driver\Session|null Active session for transactions */
    private ?object $session = null;

    private ?string $lastError = null;
    private bool $autoCommit;

    /** @var string|int|null Last inserted ID */
    private string|int|null $lastId = null;

    /**
     * @param string    $connectionString  MongoDB URL, e.g. "mongodb://localhost:27017/mydb"
     * @param string    $username          Username (used if not present in URL)
     * @param string    $password          Password (used if not present in URL)
     * @param bool|null $autoCommit        Override auto-commit setting
     */
    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = '',
        private readonly string $password = '',
        ?bool $autoCommit = null,
    ) {
        if (!extension_loaded('mongodb')) {
            throw new \RuntimeException(
                "The mongodb PHP extension is required for MongoDB connections. "
                . "Install: pecl install mongodb && composer require mongodb/mongodb"
            );
        }

        $envAutoCommit = \Tina4\DotEnv::getEnv('TINA4_AUTOCOMMIT');
        $this->autoCommit = $autoCommit ?? ($envAutoCommit !== null
            ? filter_var($envAutoCommit, FILTER_VALIDATE_BOOLEAN)
            : false);

        $this->open();
    }

    public function open(): void
    {
        if ($this->client !== null) {
            return;
        }

        try {
            $params = $this->parseConnectionString($this->connectionString);
            $dsn = $params['dsn'];
            $dbName = $params['database'];

            $options = [];
            if ($params['username'] !== '') {
                $options['username'] = $params['username'];
            }
            if ($params['password'] !== '') {
                $options['password'] = $params['password'];
            }

            $this->client = new \MongoDB\Client($dsn, $options);
            $this->db = $this->client->selectDatabase($dbName);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            throw new \RuntimeException("MongoDBAdapter: Failed to connect: {$e->getMessage()}");
        }
    }

    public function close(): void
    {
        if ($this->session !== null) {
            try {
                $this->session->endSession();
            } catch (\Exception) {
                // Ignore errors on close
            }
            $this->session = null;
        }
        $this->client = null;
        $this->db = null;
    }

    // ── Query methods ──────────────────────────────────────────────────────

    /**
     * Execute a SQL-like query and return results as associative arrays.
     *
     * Supports SELECT, INSERT, UPDATE, DELETE, CREATE TABLE.
     * All other statements are passed to execute() and return an empty array.
     */
    public function query(string $sql, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        $sql = $this->substitutePlaceholders($sql, $params);
        $verb = $this->getVerb($sql);

        try {
            return match ($verb) {
                'SELECT' => $this->executeSelect($sql),
                'INSERT' => $this->executeInsertQuery($sql),
                'UPDATE' => $this->executeUpdateQuery($sql),
                'DELETE' => $this->executeDeleteQuery($sql),
                'CREATE' => $this->executeCreateTable($sql),
                default  => [],
            };
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function fetch(string $sql, int $limit = 100, int $offset = 0, array $params = []): array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Count total without pagination
            $total = $this->countForSql($sql, $params);

            // Inject LIMIT / OFFSET into SQL if not already present
            $sqlNorm = preg_replace('/--.*$/m', '', $sql);
            if (stripos($sqlNorm, ' LIMIT ') === false) {
                $sql .= " LIMIT {$limit} OFFSET {$offset}";
            }

            $data = $this->query($sql, $params);

            return [
                'data'   => $data,
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'data'   => [],
                'total'  => 0,
                'limit'  => $limit,
                'offset' => $offset,
            ];
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // Force limit 1 for efficiency
            $sqlNorm = preg_replace('/--.*$/m', '', $sql);
            if (stripos($sqlNorm, ' LIMIT ') === false) {
                $sql .= ' LIMIT 1 OFFSET 0';
            }

            $rows = $this->query($sql, $params);
            return $rows[0] ?? null;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function execute(string $sql, array $params = []): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        $sql = $this->substitutePlaceholders($sql, $params);
        $verb = $this->getVerb($sql);

        try {
            $result = match ($verb) {
                'SELECT' => !empty($this->executeSelect($sql)),
                'INSERT' => $this->executeInsertQuery($sql) !== [],
                'UPDATE' => $this->executeUpdateQuery($sql) !== [],
                'DELETE' => $this->executeDeleteQuery($sql) !== [],
                'CREATE' => $this->executeCreateTable($sql) !== [],
                'DROP'   => $this->executeDropCollection($sql),
                default  => false,
            };
            return (bool) $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        $count = 0;
        foreach ($paramsList as $params) {
            if ($this->execute($sql, $params)) {
                $count++;
            }
        }
        return $count;
    }

    // ── CRUD helpers ───────────────────────────────────────────────────────

    public function insert(string $table, array $data): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $collection = $this->db->selectCollection($table);
            $sessionOpts = $this->sessionOptions();

            if (isset($data[0]) && is_array($data[0])) {
                // Multiple documents
                $result = $collection->insertMany(
                    array_map([$this, 'prepareDocument'], $data),
                    $sessionOpts
                );
                return $result->getInsertedCount() > 0;
            }

            $result = $collection->insertOne(
                $this->prepareDocument($data),
                $sessionOpts
            );
            $this->lastId = (string) $result->getInsertedId();
            return $result->getInsertedCount() > 0;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            $collection = $this->db->selectCollection($table);
            $filter = $where !== '' ? $this->parseWhere($this->substitutePlaceholders($where, $whereParams)) : [];
            $result = $collection->updateMany(
                $filter,
                ['$set' => $data],
                $this->sessionOptions()
            );
            return $result->getModifiedCount() >= 0;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        $this->ensureOpen();
        $this->lastError = null;

        try {
            // List of assoc arrays — delete each
            if (is_array($filter) && isset($filter[0]) && is_array($filter[0])) {
                foreach ($filter as $row) {
                    if (!$this->delete($table, $row)) {
                        return false;
                    }
                }
                return true;
            }

            // Assoc array — treat as exact-match filter
            if (is_array($filter)) {
                $mongoFilter = [];
                foreach ($filter as $col => $val) {
                    $mongoFilter[$col] = $val;
                }
                $collection = $this->db->selectCollection($table);
                $result = $collection->deleteMany($mongoFilter, $this->sessionOptions());
                return $result->getDeletedCount() >= 0;
            }

            // String WHERE clause
            $mongoFilter = $filter !== '' ? $this->parseWhere($this->substitutePlaceholders($filter, $whereParams)) : [];
            $collection = $this->db->selectCollection($table);
            $result = $collection->deleteMany($mongoFilter, $this->sessionOptions());
            return $result->getDeletedCount() >= 0;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    // ── Schema introspection ───────────────────────────────────────────────

    public function tableExists(string $table): bool
    {
        $this->ensureOpen();
        try {
            $names = $this->getCollectionNames();
            return in_array($table, $names, true);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function getTables(): array
    {
        $this->ensureOpen();
        try {
            return $this->getCollectionNames();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function getColumns(string $table): array
    {
        $this->ensureOpen();
        try {
            // Sample one document to infer the schema
            $collection = $this->db->selectCollection($table);
            $doc = $collection->findOne([], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);

            if ($doc === null) {
                return [];
            }

            $columns = [];
            foreach ($doc as $field => $value) {
                $columns[] = [
                    'name'     => $field,
                    'type'     => $this->inferType($value),
                    'nullable' => true,
                    'default'  => null,
                    'primary'  => $field === '_id',
                ];
            }
            return $columns;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function lastInsertId(): int|string
    {
        return $this->lastId ?? 0;
    }

    // ── Transaction management ─────────────────────────────────────────────

    public function startTransaction(): void
    {
        $this->ensureOpen();
        try {
            $this->session = $this->client->startSession();
            $this->session->startTransaction();
        } catch (\Exception $e) {
            // Standalone MongoDB does not support multi-document transactions;
            // treat as a no-op so single-connection code still works.
            $this->lastError = $e->getMessage();
            $this->session = null;
        }
    }

    public function commit(): void
    {
        if ($this->session === null) {
            return;
        }
        try {
            $this->session->commitTransaction();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        } finally {
            $this->session->endSession();
            $this->session = null;
        }
    }

    public function rollback(): void
    {
        if ($this->session === null) {
            return;
        }
        try {
            $this->session->abortTransaction();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
        } finally {
            $this->session->endSession();
            $this->session = null;
        }
    }

    public function error(): ?string
    {
        return $this->lastError;
    }

    // ── Accessor helpers ───────────────────────────────────────────────────

    /**
     * Return the underlying MongoDB\Client (for advanced usage).
     *
     * @return \MongoDB\Client|null
     */
    public function getConnection(): ?object
    {
        return $this->client;
    }

    /**
     * Return the underlying MongoDB\Database handle.
     *
     * @return \MongoDB\Database|null
     */
    public function getDatabase(): ?object
    {
        return $this->db;
    }

    // ── Internal SQL-to-MongoDB translation ───────────────────────────────

    /**
     * Execute a SELECT SQL statement against MongoDB.
     *
     * Parses: SELECT [cols] FROM [table] [WHERE ...] [ORDER BY ...] [LIMIT n] [OFFSET n]
     *
     * @return array<int, array<string, mixed>>
     */
    private function executeSelect(string $sql): array
    {
        $parsed = $this->parseSelect($sql);

        $collection = $this->db->selectCollection($parsed['table']);
        $projection = $this->buildProjection($parsed['columns']);
        $filter = $parsed['where'] !== '' ? $this->parseWhere($parsed['where']) : [];
        $sort = $parsed['orderBy'] !== '' ? $this->parseOrderBy($parsed['orderBy']) : [];

        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        if (!empty($projection)) {
            $options['projection'] = $projection;
        }
        if (!empty($sort)) {
            $options['sort'] = $sort;
        }
        if ($parsed['limit'] > 0) {
            $options['limit'] = $parsed['limit'];
        }
        if ($parsed['offset'] > 0) {
            $options['skip'] = $parsed['offset'];
        }
        if ($this->session !== null) {
            $options['session'] = $this->session;
        }

        $cursor = $collection->find($filter, $options);
        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = $this->normaliseDocument($doc);
        }
        return $rows;
    }

    /**
     * Parse a SELECT SQL string into its components.
     *
     * @return array{table: string, columns: string, where: string, orderBy: string, limit: int, offset: int}
     */
    private function parseSelect(string $sql): array
    {
        $result = [
            'table'   => '',
            'columns' => '*',
            'where'   => '',
            'orderBy' => '',
            'limit'   => 0,
            'offset'  => 0,
        ];

        // Normalise whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // LIMIT n
        if (preg_match('/\bLIMIT\s+(\d+)/i', $sql, $m)) {
            $result['limit'] = (int) $m[1];
            $sql = preg_replace('/\bLIMIT\s+\d+/i', '', $sql);
        }

        // OFFSET n
        if (preg_match('/\bOFFSET\s+(\d+)/i', $sql, $m)) {
            $result['offset'] = (int) $m[1];
            $sql = preg_replace('/\bOFFSET\s+\d+/i', '', $sql);
        }

        // ORDER BY
        if (preg_match('/\bORDER\s+BY\s+(.+?)(?:\bWHERE\b|\bGROUP\b|\bHAVING\b|$)/i', $sql, $m)) {
            $result['orderBy'] = trim($m[1]);
            $sql = preg_replace('/\bORDER\s+BY\s+.+?(?=\bWHERE\b|\bGROUP\b|\bHAVING\b|$)/i', '', $sql);
        }

        // WHERE
        if (preg_match('/\bWHERE\s+(.+?)(?:\bORDER\b|\bGROUP\b|\bLIMIT\b|\bOFFSET\b|$)/i', $sql, $m)) {
            $result['where'] = trim($m[1]);
            $sql = preg_replace('/\bWHERE\s+.+?(?=\bORDER\b|\bGROUP\b|\bLIMIT\b|\bOFFSET\b|$)/i', '', $sql);
        }

        // SELECT cols FROM table
        if (preg_match('/SELECT\s+(.+?)\s+FROM\s+(\S+)/i', $sql, $m)) {
            $result['columns'] = trim($m[1]);
            $result['table']   = trim($m[2], '`"[]');
        }

        return $result;
    }

    /**
     * Execute an INSERT SQL statement.
     *
     * Parses: INSERT INTO table (col1, col2, ...) VALUES (v1, v2, ...)
     *
     * @return array{} Always returns empty array (not a SELECT)
     */
    private function executeInsertQuery(string $sql): array
    {
        // INSERT INTO table (cols) VALUES (vals)
        if (!preg_match(
            '/INSERT\s+INTO\s+(\S+)\s*\(([^)]+)\)\s*VALUES\s*\((.+)\)/is',
            $sql,
            $m
        )) {
            throw new \RuntimeException("MongoDBAdapter: Cannot parse INSERT: {$sql}");
        }

        $table = trim($m[1], '`"[]');
        $cols = array_map('trim', explode(',', $m[2]));
        $vals = $this->splitCsvValues($m[3]);

        if (count($cols) !== count($vals)) {
            throw new \RuntimeException("MongoDBAdapter: INSERT column/value count mismatch");
        }

        $doc = [];
        foreach ($cols as $i => $col) {
            $doc[trim($col, '`"[]')] = $this->castValue($vals[$i]);
        }

        $collection = $this->db->selectCollection($table);
        $result = $collection->insertOne($this->prepareDocument($doc), $this->sessionOptions());
        $this->lastId = (string) $result->getInsertedId();

        return [];
    }

    /**
     * Execute an UPDATE SQL statement.
     *
     * Parses: UPDATE table SET col1 = v1, col2 = v2 [WHERE ...]
     *
     * @return array{} Always returns empty array
     */
    private function executeUpdateQuery(string $sql): array
    {
        // UPDATE table SET ... WHERE ...
        if (!preg_match(
            '/UPDATE\s+(\S+)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is',
            $sql,
            $m
        )) {
            throw new \RuntimeException("MongoDBAdapter: Cannot parse UPDATE: {$sql}");
        }

        $table  = trim($m[1], '`"[]');
        $setStr = trim($m[2]);
        $whereStr = isset($m[3]) ? trim($m[3]) : '';

        $setData = $this->parseSetClause($setStr);
        $filter = $whereStr !== '' ? $this->parseWhere($whereStr) : [];

        $collection = $this->db->selectCollection($table);
        $collection->updateMany($filter, ['$set' => $setData], $this->sessionOptions());

        return [];
    }

    /**
     * Execute a DELETE SQL statement.
     *
     * Parses: DELETE FROM table [WHERE ...]
     *
     * @return array{} Always returns empty array
     */
    private function executeDeleteQuery(string $sql): array
    {
        if (!preg_match(
            '/DELETE\s+FROM\s+(\S+)(?:\s+WHERE\s+(.+))?$/is',
            $sql,
            $m
        )) {
            throw new \RuntimeException("MongoDBAdapter: Cannot parse DELETE: {$sql}");
        }

        $table = trim($m[1], '`"[]');
        $whereStr = isset($m[2]) ? trim($m[2]) : '';
        $filter = $whereStr !== '' ? $this->parseWhere($whereStr) : [];

        $collection = $this->db->selectCollection($table);
        $collection->deleteMany($filter, $this->sessionOptions());

        return [];
    }

    /**
     * Execute a CREATE TABLE statement — creates a MongoDB collection.
     * Column definitions are ignored; MongoDB is schema-less.
     *
     * @return array{} Always returns empty array
     */
    private function executeCreateTable(string $sql): array
    {
        if (!preg_match('/CREATE\s+(?:TABLE|COLLECTION)\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)/i', $sql, $m)) {
            return [];
        }

        $table = trim($m[1], '`"[]');

        if (!$this->tableExists($table)) {
            $this->db->createCollection($table);
        }

        return [];
    }

    /**
     * Execute a DROP TABLE statement — drops a MongoDB collection.
     */
    private function executeDropCollection(string $sql): bool
    {
        if (!preg_match('/DROP\s+(?:TABLE|COLLECTION)\s+(?:IF\s+EXISTS\s+)?(\S+)/i', $sql, $m)) {
            return false;
        }

        $table = trim($m[1], '`"[]');
        $this->db->selectCollection($table)->drop();
        return true;
    }

    // ── WHERE clause parser ────────────────────────────────────────────────

    /**
     * Parse a SQL WHERE clause string into a MongoDB filter document.
     *
     * Supports:
     *   - =, !=, <>, >, >=, <, <=
     *   - LIKE  → $regex
     *   - NOT LIKE → $not $regex
     *   - IN (...)   → $in
     *   - NOT IN (...) → $nin
     *   - IS NULL / IS NOT NULL
     *   - AND, OR (top-level)
     *   - Quoted string literals and numeric literals
     *
     * @param string $where WHERE clause without the "WHERE" keyword
     * @return array<string, mixed> MongoDB filter document
     */
    private function parseWhere(string $where): array
    {
        $where = trim($where);
        if ($where === '' || $where === '1=1' || $where === '1 = 1') {
            return [];
        }

        // Try to split on top-level OR first
        $orParts = $this->splitOnKeyword($where, 'OR');
        if (count($orParts) > 1) {
            $orBranches = array_map([$this, 'parseWhere'], $orParts);
            return ['$or' => $orBranches];
        }

        // Try to split on top-level AND
        $andParts = $this->splitOnKeyword($where, 'AND');
        if (count($andParts) > 1) {
            $andBranches = array_map([$this, 'parseWhere'], $andParts);
            return ['$and' => $andBranches];
        }

        // Single condition
        return $this->parseSingleCondition(trim($where));
    }

    /**
     * Parse a single WHERE condition into a MongoDB filter element.
     *
     * @return array<string, mixed>
     */
    private function parseSingleCondition(string $condition): array
    {
        $condition = trim($condition);

        // Strip surrounding parens
        if (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $inner = substr($condition, 1, -1);
            // Only strip if the parens genuinely wrap the whole expression
            if ($this->splitOnKeyword($inner, 'OR') || $this->splitOnKeyword($inner, 'AND')) {
                return $this->parseWhere($inner);
            }
        }

        // IS NULL / IS NOT NULL
        if (preg_match('/^(\w+)\s+IS\s+NOT\s+NULL$/i', $condition, $m)) {
            return [$m[1] => ['$ne' => null]];
        }
        if (preg_match('/^(\w+)\s+IS\s+NULL$/i', $condition, $m)) {
            return [$m[1] => null];
        }

        // NOT LIKE
        if (preg_match('/^(\w+)\s+NOT\s+LIKE\s+(.+)$/i', $condition, $m)) {
            $pattern = $this->likeToRegex(trim($m[2], "'\""));
            return [$m[1] => ['$not' => new \MongoDB\BSON\Regex($pattern, 'i')]];
        }

        // LIKE
        if (preg_match('/^(\w+)\s+LIKE\s+(.+)$/i', $condition, $m)) {
            $pattern = $this->likeToRegex(trim($m[2], "'\""));
            return [$m[1] => new \MongoDB\BSON\Regex($pattern, 'i')];
        }

        // NOT IN (...)
        if (preg_match('/^(\w+)\s+NOT\s+IN\s*\((.+)\)$/i', $condition, $m)) {
            $values = $this->parseInList($m[2]);
            return [$m[1] => ['$nin' => $values]];
        }

        // IN (...)
        if (preg_match('/^(\w+)\s+IN\s*\((.+)\)$/i', $condition, $m)) {
            $values = $this->parseInList($m[2]);
            return [$m[1] => ['$in' => $values]];
        }

        // Comparison: >=, <=, !=, <>, >, <, =
        if (preg_match('/^(\w+)\s*(>=|<=|!=|<>|>|<|=)\s*(.+)$/i', $condition, $m)) {
            $field    = $m[1];
            $operator = $m[2];
            $rawValue = trim($m[3]);
            $value    = $this->castLiteralValue($rawValue);

            $mongoOp = match ($operator) {
                '='  => null,      // direct equality
                '!=' , '<>' => '$ne',
                '>'  => '$gt',
                '>=' => '$gte',
                '<'  => '$lt',
                '<=' => '$lte',
            };

            return $mongoOp === null
                ? [$field => $value]
                : [$field => [$mongoOp => $value]];
        }

        // Fall through — return an empty filter rather than crashing
        return [];
    }

    /**
     * Split a WHERE string on a top-level keyword (AND/OR), respecting parentheses and quotes.
     *
     * @return string[] Parts; empty array if keyword not found at top level
     */
    private function splitOnKeyword(string $expr, string $keyword): array
    {
        $parts = [];
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $current = '';
        $len = strlen($expr);
        $kw = strtoupper($keyword);
        $kwLen = strlen($kw) + 2; // " AND " / " OR "

        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                } elseif ($depth === 0) {
                    // Check for keyword boundary
                    $ahead = strtoupper(substr($expr, $i, $kwLen));
                    if ($ahead === " {$kw} ") {
                        $parts[] = $current;
                        $current = '';
                        $i += $kwLen - 1;
                        continue;
                    }
                }
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return count($parts) > 1 ? $parts : [];
    }

    // ── Projection, sort, and misc helpers ────────────────────────────────

    /**
     * Build a MongoDB projection document from a SELECT column list.
     *
     * @return array<string, int>
     */
    private function buildProjection(string $columns): array
    {
        $columns = trim($columns);
        if ($columns === '*') {
            return [];
        }

        $projection = [];
        foreach (explode(',', $columns) as $col) {
            $col = trim($col, " `\"[]");
            // Handle aliases: col AS alias
            if (preg_match('/^(\S+)\s+AS\s+(\S+)$/i', $col, $m)) {
                $col = trim($m[1], '`"[]');
            }
            if ($col !== '') {
                $projection[$col] = 1;
            }
        }

        return $projection;
    }

    /**
     * Parse ORDER BY clause into MongoDB sort document.
     *
     * @return array<string, int>
     */
    private function parseOrderBy(string $orderBy): array
    {
        $sort = [];
        foreach (explode(',', $orderBy) as $part) {
            $part = trim($part);
            if (preg_match('/^(\S+)\s+(ASC|DESC)$/i', $part, $m)) {
                $sort[trim($m[1], '`"[]')] = strtoupper($m[2]) === 'DESC' ? -1 : 1;
            } elseif ($part !== '') {
                $sort[trim($part, '`"[]')] = 1;
            }
        }
        return $sort;
    }

    /**
     * Parse a SET clause from an UPDATE statement.
     *
     * @return array<string, mixed>
     */
    private function parseSetClause(string $setStr): array
    {
        $data = [];
        // Split on commas not inside quotes
        $parts = preg_split('/,(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $setStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(\w+)\s*=\s*(.+)$/', $part, $m)) {
                $data[trim($m[1], '`"[]')] = $this->castLiteralValue(trim($m[2]));
            }
        }
        return $data;
    }

    /**
     * Parse an IN list "(v1, v2, v3)" into an array of cast values.
     *
     * @return mixed[]
     */
    private function parseInList(string $list): array
    {
        $values = [];
        foreach (explode(',', $list) as $v) {
            $values[] = $this->castLiteralValue(trim($v));
        }
        return $values;
    }

    /**
     * Convert a SQL LIKE pattern to a regex pattern.
     *
     * %  → .*
     * _  → .
     */
    private function likeToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '/');
        $escaped = str_replace(['\\%', '\\_'], ['.*', '.'], $escaped);
        return "^{$escaped}$";
    }

    /**
     * Count documents matching the WHERE clause of a SELECT for pagination totals.
     */
    private function countForSql(string $sql, array $params): int
    {
        try {
            $sql = $this->substitutePlaceholders($sql, $params);
            $parsed = $this->parseSelect($sql);
            $collection = $this->db->selectCollection($parsed['table']);
            $filter = $parsed['where'] !== '' ? $this->parseWhere($parsed['where']) : [];
            return (int) $collection->countDocuments($filter);
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Substitute positional (?) or named (:name) placeholders with literal values.
     *
     * This is needed because MongoDB operations are not SQL — we resolve the
     * parameters into the SQL string so the parser sees concrete values.
     */
    private function substitutePlaceholders(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // Named placeholders: :name or :1
        if (!array_is_list($params)) {
            foreach ($params as $key => $value) {
                $placeholder = str_starts_with((string)$key, ':') ? $key : ":{$key}";
                $sql = str_replace($placeholder, $this->quoteValue($value), $sql);
            }
            return $sql;
        }

        // Positional ?
        $values = array_values($params);
        $idx = 0;
        return preg_replace_callback('/\?/', function () use (&$idx, $values) {
            $val = $values[$idx] ?? null;
            $idx++;
            return $this->quoteValue($val);
        }, $sql);
    }

    /**
     * Quote a PHP value for inline substitution into SQL text.
     */
    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Escape single quotes
        return "'" . str_replace("'", "\\'", (string) $value) . "'";
    }

    /**
     * Cast a raw SQL literal value to the appropriate PHP type.
     */
    private function castLiteralValue(string $raw): mixed
    {
        $raw = trim($raw);

        if (strtoupper($raw) === 'NULL') {
            return null;
        }
        if (strtoupper($raw) === 'TRUE') {
            return true;
        }
        if (strtoupper($raw) === 'FALSE') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (preg_match('/^-?\d+\.\d+$/', $raw)) {
            return (float) $raw;
        }
        // Quoted string
        if ((str_starts_with($raw, "'") && str_ends_with($raw, "'"))
            || (str_starts_with($raw, '"') && str_ends_with($raw, '"'))) {
            return stripcslashes(substr($raw, 1, -1));
        }

        return $raw;
    }

    /**
     * Cast a value returned from a SQL placeholder substitution.
     * Same as castLiteralValue but accepts already-typed PHP values.
     */
    private function castValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->castLiteralValue($value);
        }
        return $value;
    }

    /**
     * Split CSV values from a VALUES (...) clause, respecting quoted strings.
     *
     * @return string[]
     */
    private function splitCsvValues(string $csv): array
    {
        $values = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < strlen($csv); $i++) {
            $ch = $csv[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $current .= $ch;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $current .= $ch;
            } elseif ($ch === ',' && !$inSingle && !$inDouble) {
                $values[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if ($current !== '') {
            $values[] = trim($current);
        }

        return $values;
    }

    /**
     * Convert a MongoDB document to a plain PHP assoc array,
     * serialising ObjectId and other BSON types to strings.
     *
     * @param array<string, mixed>|\ArrayObject<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function normaliseDocument(mixed $doc): array
    {
        $row = [];
        foreach ((array) $doc as $key => $value) {
            $row[$key] = $this->normaliseBsonValue($value);
        }
        return $row;
    }

    private function normaliseBsonValue(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\BSON\ObjectId) {
            return (string) $value;
        }
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \MongoDB\BSON\Decimal128) {
            return (float) (string) $value;
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $this->normaliseDocument($value);
        }
        if (is_array($value)) {
            return array_map([$this, 'normaliseBsonValue'], $value);
        }
        return $value;
    }

    /**
     * Prepare a document for insertion: remove null _id to let MongoDB auto-generate.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function prepareDocument(array $doc): array
    {
        if (array_key_exists('_id', $doc) && $doc['_id'] === null) {
            unset($doc['_id']);
        }
        return $doc;
    }

    /**
     * Infer a human-readable type string from a PHP value.
     */
    private function inferType(mixed $value): string
    {
        return match (true) {
            $value instanceof \MongoDB\BSON\ObjectId => 'ObjectId',
            $value instanceof \MongoDB\BSON\UTCDateTime => 'DateTime',
            is_int($value) => 'integer',
            is_float($value) => 'double',
            is_bool($value) => 'boolean',
            is_array($value) => 'array',
            is_null($value) => 'null',
            default => 'string',
        };
    }

    /**
     * Build session options array for collection operations.
     *
     * @return array<string, mixed>
     */
    private function sessionOptions(): array
    {
        return $this->session !== null ? ['session' => $this->session] : [];
    }

    /**
     * Retrieve all collection names in the current database.
     *
     * @return string[]
     */
    private function getCollectionNames(): array
    {
        $names = [];
        foreach ($this->db->listCollectionNames() as $name) {
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Extract the first SQL verb from a statement.
     */
    private function getVerb(string $sql): string
    {
        $sql = ltrim($sql);
        return strtoupper(strtok($sql, " \t\n\r"));
    }

    /**
     * Parse a MongoDB connection URL and return DSN + components.
     *
     * Normalises 'pymongo://' to 'mongodb://'.
     *
     * @return array{dsn: string, database: string, username: string, password: string}
     */
    private function parseConnectionString(string $url): array
    {
        // Normalise pymongo scheme
        $url = preg_replace('/^pymongo:\/\//i', 'mongodb://', $url);

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("MongoDBAdapter: Invalid connection string: {$url}");
        }

        $host     = $parts['host'] ?? 'localhost';
        $port     = $parts['port'] ?? 27017;
        $database = ltrim($parts['path'] ?? '/tina4', '/') ?: 'tina4';
        $username = isset($parts['user']) ? urldecode($parts['user']) : $this->username;
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : $this->password;

        // Rebuild DSN without the database path (MongoDB\Client takes db name separately)
        $dsn = "mongodb://{$host}:{$port}";

        return compact('dsn', 'database', 'username', 'password');
    }

    /**
     * Ensure the database connection is open.
     */
    private function ensureOpen(): void
    {
        if ($this->client === null) {
            $this->open();
        }
    }
}
