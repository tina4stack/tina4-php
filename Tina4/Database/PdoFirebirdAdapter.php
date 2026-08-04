<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * Firebird database adapter over the pdo_firebird driver — the SILENT fallback
 * used by Database::create() when ext-interbase (ibase_ / fbird_ functions) is
 * absent.
 *
 * This is the highest-value fallback: ext-interbase was removed from PHP core
 * in 7.4 (PECL-only, broken on macOS), so FirebirdAdapter throws on most modern
 * builds. pdo_firebird ships with mainstream PHP distributions.
 *
 * Externally mirrors FirebirdAdapter: trims CHAR padding, reads BLOBs into raw
 * byte strings, casts NUMERIC/DECIMAL to float, uses ROWS X TO Y pagination,
 * appends RETURNING * on insert (falling back to a plain INSERT), and preserves
 * the fail-loud contract.
 *
 * Type-fidelity note (pdo_firebird only): the driver reports no `native_type`
 * in getColumnMeta(), so column typing is inferred. INTEGER/SMALLINT/BIGINT
 * arrive as PHP ints already; NUMERIC/DECIMAL carry their decimal scale in
 * `precision` (> 0) and are cast to float to match the native adapter. DOUBLE
 * PRECISION and FLOAT report `precision` 0 with `len` 8/4 — indistinguishable
 * from CHAR(8)/CHAR(4)/BLOB — so they cannot be auto-typed without risking a
 * silent misread of a text column, and are returned as their exact numeric
 * STRING. This is the one documented divergence from the native ext-interbase
 * adapter (which returns DOUBLE/FLOAT as float); cast at the call site
 * (`(float) $row['price']`) when a float is required. Every other type,
 * including full BLOB byte fidelity, is identical to native.
 */
class PdoFirebirdAdapter implements DatabaseAdapter
{
    use AutocommitTrait;

    /**
     * The SQL dialect this adapter speaks.
     *
     * An adapter has to be able to NAME its engine before anything can build
     * DDL for it. Until now the only way to find out was ORM::detectDialect(),
     * a private instanceof chain that type-checks the adapter from outside -
     * so a caller depended on the concrete class rather than the contract, and
     * a new adapter was invisible to it until someone edited that match block.
     *
     * This is the prerequisite for createTable() and addColumn() on the
     * adapter, which is why those two are the last of the contract to land.
     */
    public function getDatabaseType(): string
    {
        return 'firebird';
    }

    use SqlNormalizerTrait;
    use PdoAdapterTrait;

    /**
     * @var string Resolved connection charset (php #160). Precedence: URL
     * ?charset= > explicit charset arg > TINA4_DATABASE_CHARSET env > UTF8.
     */
    private string $charset;

    public function __construct(
        private readonly string $connectionString,
        private readonly string $username = 'SYSDBA',
        private readonly string $password = 'masterkey',
        ?string $charset = null,
        ?bool $autoCommit = null,
    ) {
        // php #160: resolve the DSN charset (shared with the native adapter) so a
        // legacy NONE database is not force-connected as UTF8 (double-encoding).
        $this->charset = FirebirdAdapter::resolveCharset($this->connectionString, $charset);
        $this->autoCommit = $this->resolveAutoCommit($autoCommit);
        $this->open();
    }

    protected function engineLabel(): string
    {
        return 'Firebird (PDO)';
    }

    protected function buildDsn(): array
    {
        $params = $this->parseConnection($this->connectionString);

        $dbPath = $params['database'];
        if ($params['host'] !== '') {
            $dbPath = $params['host'];
            if ($params['port'] > 0 && $params['port'] !== 3050) {
                $dbPath .= '/' . $params['port'];
            }
            $dbPath .= ':' . $params['database'];
        }

        $dsn = "firebird:dbname={$dbPath};charset={$this->charset}";
        return [$dsn, $params['username'], $params['password'], []];
    }

    /** Firebird has no native BOOLEAN below 3.0 — bind booleans as 1/0. */
    protected function normalizeParams(array $params): array
    {
        return self::normalizeBoolParams($params, nativeBoolean: false);
    }

    /** ROWS pagination on a NEW LINE — inline it lands inside a trailing `--` comment. */
    protected function paginate(string $sql, int $limit, int $offset): string
    {
        $startRow = $offset + 1;
        $endRow = $offset + $limit;
        return self::appendSqlClause($sql, "ROWS {$startRow} TO {$endRow}");
    }

    protected function insertReturningClause(): string
    {
        return ' RETURNING *';
    }

    /**
     * pdo_firebird reports PDO::inTransaction()===true even in autocommit mode,
     * so startTransaction() must turn autocommit OFF to establish a real
     * transaction boundary (otherwise every statement auto-commits and
     * rollback() cannot undo it). commit()/rollback() restore autocommit.
     */
    protected function transactionsNeedAutocommitToggle(): bool
    {
        return true;
    }

    /** Firebird pads/returns identifiers with trailing spaces — trim like native. */
    protected function normalizeReturnedId(mixed $value): int|string
    {
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Coerce fetched rows to match the native FirebirdAdapter:
     *   - NUMERIC/DECIMAL (decimal scale in `precision` > 0) -> float
     *   - BLOB streams -> raw byte strings
     *   - CHAR padding -> right-trimmed
     * DOUBLE/FLOAT are left as strings (see the class docblock — precision 0
     * makes them indistinguishable from CHAR/BLOB, so auto-casting would risk
     * corrupting a text column).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $meta
     */
    protected function coerceRows(array $rows, array $meta): array
    {
        if ($rows === []) {
            return $rows;
        }

        // Column names whose values are a fractional NUMERIC/DECIMAL — the only
        // pdo_firebird strings we can safely re-type to float. `precision` here
        // is the SQL scale (NUMERIC(15,2) -> 2), 0 for everything non-decimal.
        $floatCols = [];
        foreach ($meta as $column) {
            $name = $column['name'] ?? null;
            if ($name !== null && (int) ($column['precision'] ?? 0) > 0) {
                $floatCols[$name] = true;
            }
        }

        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                if ($value === null) {
                    continue;
                }
                if (isset($floatCols[$key])) {
                    $row[$key] = (float) $value;
                } elseif (is_resource($value)) {
                    $row[$key] = stream_get_contents($value);
                } elseif (is_string($value)) {
                    $row[$key] = rtrim($value);
                }
            }
        }
        unset($row);
        return $rows;
    }

    public function getDatabase(): string
    {
        return $this->connectionString;
    }

    public function tableExists(string $table): bool
    {
        // Firebird's folding rule is ASYMMETRIC:
        //     CREATE TABLE foo     -> stored as FOO   (unquoted folds to UPPER)
        //     CREATE TABLE "Foo"   -> stored as Foo   (quoted keeps its case)
        //
        // So uppercasing is CORRECT for the unquoted case - the common one - and
        // WRONG for a quoted mixed-case table. Dropping it would not fix that, it
        // would invert which half is broken. tableExists('Foo') is genuinely
        // AMBIGUOUS, so match EITHER spelling.
        //
        // This class is the one that actually runs on PHP 8: ext-interbase has no
        // PHP 8 build (Ubuntu's php8.3-interbase ships only pdo_firebird.so), so
        // the native FirebirdAdapter's ibase_*/fbird_* path is unreachable and
        // every live Firebird test goes through PDO. Fixing only the native class
        // fixes nothing here - which is exactly what happened first time round.
        $rows = $this->query(
            "SELECT RDB\$RELATION_NAME FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BLR IS NULL AND (TRIM(RDB\$RELATION_NAME) = ? OR TRIM(RDB\$RELATION_NAME) = ?)",
            [$table, strtoupper($table)]
        );
        return count($rows) > 0;
    }

    public function getColumns(string $table): array
    {
        $sql = "SELECT RF.RDB\$FIELD_NAME AS field_name,
                       F.RDB\$FIELD_TYPE AS field_type,
                       RF.RDB\$NULL_FLAG AS null_flag,
                       RF.RDB\$DEFAULT_SOURCE AS default_source
                FROM RDB\$RELATION_FIELDS RF
                JOIN RDB\$FIELDS F ON RF.RDB\$FIELD_SOURCE = F.RDB\$FIELD_NAME
                WHERE RF.RDB\$RELATION_NAME = ?
                ORDER BY RF.RDB\$FIELD_POSITION";

        $rows = $this->query($sql, [strtoupper($table)]);

        $typeMap = [
            7 => 'SMALLINT', 8 => 'INTEGER', 10 => 'FLOAT', 12 => 'DATE',
            13 => 'TIME', 14 => 'CHAR', 16 => 'BIGINT', 27 => 'DOUBLE PRECISION',
            35 => 'TIMESTAMP', 37 => 'VARCHAR', 261 => 'BLOB',
        ];

        $pkRows = $this->query(
            "SELECT TRIM(ISG.RDB\$FIELD_NAME) AS pk_field
             FROM RDB\$RELATION_CONSTRAINTS RC
             JOIN RDB\$INDEX_SEGMENTS ISG ON RC.RDB\$INDEX_NAME = ISG.RDB\$INDEX_NAME
             WHERE RC.RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY' AND RC.RDB\$RELATION_NAME = ?",
            [strtoupper($table)]
        );
        $pkFields = array_map('trim', array_column($pkRows, 'PK_FIELD'));

        $columns = [];
        foreach ($rows as $row) {
            $fieldName = trim($row['FIELD_NAME'] ?? $row['field_name'] ?? '');
            $fieldType = (int) ($row['FIELD_TYPE'] ?? $row['field_type'] ?? 0);
            $columns[] = [
                'name' => $fieldName,
                'type' => $typeMap[$fieldType] ?? "TYPE_{$fieldType}",
                'nullable' => ($row['NULL_FLAG'] ?? $row['null_flag'] ?? null) === null,
                'default' => $row['DEFAULT_SOURCE'] ?? $row['default_source'] ?? null,
                'primaryKey' => in_array($fieldName, $pkFields, true),
            ];
        }
        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT TRIM(RDB\$RELATION_NAME) AS table_name FROM RDB\$RELATIONS WHERE RDB\$SYSTEM_FLAG = 0 AND RDB\$VIEW_BLR IS NULL ORDER BY RDB\$RELATION_NAME"
        );
        return array_map(
            static fn($row) => trim($row['TABLE_NAME'] ?? $row['table_name'] ?? ''),
            $rows
        );
    }

    /**
     * Parse a connection string (URL or path) into connection params — same
     * rules as FirebirdAdapter (TINA4_DATABASE_FIREBIRD_PATH override, then
     * FirebirdAdapter::normalizeDbIdentifier on the URL path).
     *
     * @return array{host: string, port: int, username: string, password: string, database: string}
     */
    private function parseConnection(string $input): array
    {
        $envOverride = \Tina4\DotEnv::getEnv('TINA4_DATABASE_FIREBIRD_PATH');

        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            $rawPath = $parts['path'] ?? '';
            $database = ($envOverride !== null && $envOverride !== '')
                ? $envOverride
                : FirebirdAdapter::normalizeDbIdentifier($rawPath);
            return [
                'host' => $parts['host'] ?? '',
                'port' => $parts['port'] ?? 3050,
                'username' => isset($parts['user']) ? urldecode($parts['user']) : $this->username,
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : $this->password,
                'database' => $database,
            ];
        }

        return [
            'host' => '',
            'port' => 3050,
            'username' => $this->username,
            'password' => $this->password,
            'database' => ($envOverride !== null && $envOverride !== '') ? $envOverride : $input,
        ];
    }
}
