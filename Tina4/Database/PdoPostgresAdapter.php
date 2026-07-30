<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * PostgreSQL database adapter over the pdo_pgsql driver — the SILENT fallback
 * used by Database::create() when ext-pgsql (pg_connect) is absent.
 *
 * Externally indistinguishable from PostgresAdapter. pdo_pgsql with
 * ATTR_STRINGIFY_FETCHES=false already returns native int/float/bool, but it
 * leaves NUMERIC a string and hands BYTEA back as a stream resource — so this
 * adapter applies the SAME native-type coercion the native adapter's
 * buildColumnCasters() does (keyed on getColumnMeta native_type):
 *   int2/int4/int8 -> int, bool -> bool, float4/float8/numeric -> float,
 *   bytea -> raw bytes.
 * lastInsertId() is a numeric STRING, matching the native pg_fetch RETURNING id.
 */
class PdoPostgresAdapter implements DatabaseAdapter
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
        return 'postgresql';
    }

    use SqlNormalizerTrait;
    use PdoAdapterTrait;

    public function __construct(
        private readonly string $connectionString,
        ?bool $autoCommit = null,
        private readonly string $username = '',
        private readonly string $password = '',
    ) {
        $this->autoCommit = $this->resolveAutoCommit($autoCommit);
        $this->open();
    }

    protected function engineLabel(): string
    {
        return 'PostgreSQL (PDO)';
    }

    protected function buildDsn(): array
    {
        $input = $this->connectionString;

        // libpq key=value DSN (e.g. "host=.. port=.. dbname=.. user=..").
        if (str_contains($input, '=') && !str_contains($input, '://')) {
            $kv = [];
            foreach (preg_split('/\s+/', trim($input)) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $kv[$k] = $v;
                }
            }
            $user = $kv['user'] ?? $this->username;
            $pass = $kv['password'] ?? $this->password;
            unset($kv['user'], $kv['password']);
            $dsn = 'pgsql:' . implode(';', array_map(
                static fn($k, $v) => "{$k}={$v}",
                array_keys($kv),
                array_values($kv)
            ));
            return [$dsn, $user, $pass, []];
        }

        // URL form: postgres://user:pass@host:port/dbname
        $parts = parse_url($input) ?: [];
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        $user = isset($parts['user']) ? urldecode($parts['user']) : $this->username;
        $pass = isset($parts['pass']) ? urldecode($parts['pass']) : $this->password;

        $dsn = "pgsql:host={$host};port={$port}";
        if ($dbname !== '') {
            $dsn .= ";dbname={$dbname}";
        }
        return [$dsn, $user, $pass, []];
    }

    /** PostgreSQL has a native BOOLEAN — bind booleans as 't'/'f' (matches native). */
    protected function normalizeParams(array $params): array
    {
        return self::normalizeBoolParams($params, nativeBoolean: true);
    }

    /** PG count probe needs a subquery alias. */
    protected function wrapCountSql(string $sql): string
    {
        return "SELECT COUNT(*) as total FROM ({$sql}) AS _count_query";
    }

    /** Matches PostgresAdapter::insert(), which uses RETURNING *. */
    protected function insertReturningClause(): string
    {
        return ' RETURNING *';
    }

    /** Native pg_fetch returns the RETURNING id as a string — match it. */
    protected function normalizeReturnedId(mixed $value): int|string
    {
        return (string) $value;
    }

    /**
     * Non-RETURNING INSERT: read lastval() so getLastId() works on serial PKs,
     * SAVEPOINT-guarded inside a transaction so a lastval() error on a
     * sequence-less table (UUID/hash PK, issue #38) doesn't abort the whole
     * transaction. Mirrors PostgresAdapter::execute().
     */
    protected function captureLastIdAfterWrite(string $sql): void
    {
        if (!$this->isInsert($sql)) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            try {
                $this->lastId = (string) $this->pdo->query('SELECT lastval()')->fetchColumn();
            } catch (\PDOException) {
                // Sequence-less table — leave lastId unchanged.
            }
            return;
        }
        try {
            $this->pdo->exec('SAVEPOINT _t4_lastval_probe');
            try {
                $this->lastId = (string) $this->pdo->query('SELECT lastval()')->fetchColumn();
                $this->pdo->exec('RELEASE SAVEPOINT _t4_lastval_probe');
            } catch (\PDOException) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT _t4_lastval_probe');
            }
        } catch (\PDOException) {
            // SAVEPOINT unavailable — leave lastId unchanged.
        }
    }

    /**
     * Coerce ext-pgsql-string / PDO-native cells to the exact PHP types the
     * native PostgresAdapter returns. Idempotent on already-native values, so
     * it holds regardless of the PDO/libpq version's default typing.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $meta
     */
    protected function coerceRows(array $rows, array $meta): array
    {
        if ($rows === []) {
            return $rows;
        }

        $casters = [];
        foreach ($meta as $column) {
            $name = $column['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $nativeType = $column['native_type'] ?? '';
            $caster = match ($nativeType) {
                'int2', 'int4', 'int8' => static fn($v) => (int) $v,
                'bool' => static fn($v) => is_bool($v) ? $v : ($v === 't' || $v === '1' || $v === 1 || $v === true),
                'float4', 'float8', 'numeric' => static fn($v) => (float) $v,
                'bytea' => static fn($v) => is_resource($v) ? stream_get_contents($v) : $v,
                default => null,
            };
            if ($caster !== null) {
                $casters[$name] = $caster;
            }
        }

        if ($casters === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($casters as $col => $cast) {
                // Skip nulls — a NULL column stays null in every engine.
                if (array_key_exists($col, $row) && $row[$col] !== null) {
                    $row[$col] = $cast($row[$col]);
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
        // to_regclass resolves a (possibly schema-qualified) relation using the
        // search_path, returning NULL if absent — same as PostgresAdapter.
        $rows = $this->query('SELECT to_regclass(?) AS oid', [$table]);
        return count($rows) > 0 && ($rows[0]['oid'] ?? null) !== null;
    }

    public function getColumns(string $table): array
    {
        [$schema, $tbl] = self::splitSchema($table);
        $schema = $schema ?? 'public';
        $sql = "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                    CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN true ELSE false END AS is_primary
                FROM information_schema.columns c
                LEFT JOIN information_schema.key_column_usage kcu
                    ON c.table_name = kcu.table_name AND c.column_name = kcu.column_name
                    AND c.table_schema = kcu.table_schema
                LEFT JOIN information_schema.table_constraints tc
                    ON kcu.constraint_name = tc.constraint_name AND tc.constraint_type = 'PRIMARY KEY'
                WHERE c.table_name = ? AND c.table_schema = ?
                ORDER BY c.ordinal_position";

        $rows = $this->query($sql, [$tbl, $schema]);
        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'primaryKey' => ($row['is_primary'] ?? false) === true || ($row['is_primary'] ?? 'f') === 't',
            ];
        }
        return $columns;
    }

    public function getTables(): array
    {
        $rows = $this->query(
            "SELECT schemaname, tablename FROM pg_tables
             WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
             ORDER BY schemaname, tablename"
        );
        return array_map(
            static fn($r) => $r['schemaname'] === 'public'
                ? $r['tablename']
                : $r['schemaname'] . '.' . $r['tablename'],
            $rows
        );
    }
}
