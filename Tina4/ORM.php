<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;

/**
 * ORM base class — active record pattern for database models.
 *
 * Usage:
 *   class User extends ORM {
 *       public string $tableName = 'users';
 *       public string $primaryKey = 'id';
 *       public bool $softDelete = true;
 *   }
 *
 *   $user = new User($db);
 *   $user->name = 'Alice';
 *   $user->save();
 */
abstract class ORM
{
    /** @var string Table name — must be set by subclass */
    public string $tableName = '';

    /** @var string Primary key column name */
    public string $primaryKey = 'id';

    /** @var array<string, string> Map DB column names to PHP property names */
    public array $fieldMapping = [];

    /** @var bool When true, auto-generates fieldMapping from camelCase properties to snake_case DB columns */
    public bool $autoMap = false;

    /** @var bool Whether soft delete is enabled */
    public bool $softDelete = false;

    /** @var array<string, string> Has-one relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasOne = [];

    /** @var array<string, string> Has-many relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasMany = [];

    /** @var array<string, string> Belongs-to relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $belongsTo = [];

    /** @var DatabaseAdapter|null Database connection */
    protected ?DatabaseAdapter $_db = null;

    /** @var array<string, mixed> Model data storage */
    private array $_data = [];

    /** @var bool Whether this record exists in the database */
    private bool $_exists = false;

    /** @var array<string, mixed> Relationship cache for lazy loading */
    private array $_relCache = [];

    /**
     * Create a fluent QueryBuilder pre-configured for this model's table and database.
     *
     * Usage:
     *   $results = User::query()->where('active = ?', [1])->orderBy('name')->get();
     *
     * @return QueryBuilder
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return QueryBuilder::from($instance->tableName, $instance->_db);
    }

    /**
     * Create a new record from an associative array and persist it immediately.
     *
     * Usage:
     *   $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return static The saved ORM instance
     */
    public static function create(array $data = []): static
    {
        $instance = new static(data: $data);
        $instance->save();
        return $instance;
    }

    /**
     * Convert a snake_case name to camelCase.
     */
    private static function snakeToCamel(string $name): string
    {
        $name = strtolower($name); // normalise uppercase column names (Firebird/Oracle)
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    /**
     * Convert a camelCase name to snake_case.
     */
    private static function camelToSnake(string $name): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    /**
     * Create a new ORM instance.
     *
     * @param DatabaseAdapter|null $db Database adapter
     * @param array<string, mixed> $data Initial data to populate
     */
    public function __construct(?DatabaseAdapter $db = null, array $data = [])
    {
        $this->_db = $db;

        if (!empty($data)) {
            $this->fill($data);
        }
    }

    /**
     * Set a property value via magic setter.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->_data[$name] = $value;
    }

    /**
     * Get a property value via magic getter.
     * Supports lazy loading for relationships defined in $hasOne, $hasMany, and $belongsTo.
     */
    public function __get(string $name): mixed
    {
        // Check regular data first
        if (array_key_exists($name, $this->_data)) {
            return $this->_data[$name];
        }

        // Check relationship cache
        if (array_key_exists($name, $this->_relCache)) {
            return $this->_relCache[$name];
        }

        // Lazy load has-one relationships
        if (isset($this->hasOne[$name])) {
            $this->_relCache[$name] = $this->_loadRelationship($name, $this->hasOne[$name], 'hasOne');
            return $this->_relCache[$name];
        }

        // Lazy load has-many relationships
        if (isset($this->hasMany[$name])) {
            $this->_relCache[$name] = $this->_loadRelationship($name, $this->hasMany[$name], 'hasMany');
            return $this->_relCache[$name];
        }

        // Lazy load belongs-to relationships
        if (isset($this->belongsTo[$name])) {
            $this->_relCache[$name] = $this->_loadRelationship($name, $this->belongsTo[$name], 'belongsTo');
            return $this->_relCache[$name];
        }

        return null;
    }

    /**
     * Check if a property is set.
     */
    public function __isset(string $name): bool
    {
        return isset($this->_data[$name]);
    }

    /**
     * Unset a property.
     */
    public function __unset(string $name): void
    {
        unset($this->_data[$name]);
    }

    /**
     * Set the database adapter.
     *
     * @return $this
     */
    public function setDb(DatabaseAdapter $db): self
    {
        $this->_db = $db;
        return $this;
    }

    /**
     * Get the database adapter.
     */
    public function getDb(): ?DatabaseAdapter
    {
        return $this->_db;
    }

    /**
     * Fill the model with data from an associative array.
     *
     * @return $this
     */
    public function fill(array $data): self
    {
        // When autoMap is enabled, auto-generate fieldMapping for incoming
        // snake_case DB columns that map to camelCase property names.
        // Explicit $fieldMapping entries always take precedence.
        if ($this->autoMap) {
            $existingMappedColumns = array_values($this->fieldMapping);
            foreach (array_keys($data) as $col) {
                // Skip columns that are already mapped (as values in fieldMapping)
                if (in_array($col, $existingMappedColumns, true)) {
                    continue;
                }
                $camel = self::snakeToCamel($col);
                if ($camel !== $col && !isset($this->fieldMapping[$camel])) {
                    $this->fieldMapping[$camel] = $col;
                }
            }
        }

        $reverseMapping = array_flip($this->fieldMapping);

        foreach ($data as $key => $value) {
            // Map DB column to PHP property if mapping exists
            $propName = $reverseMapping[$key] ?? $key;
            $this->_data[$propName] = $value;
        }

        return $this;
    }

    /**
     * Save the model — INSERT or UPDATE (upsert).
     *
     * @return bool True on success
     */
    /**
     * Insert or update. Returns $this on success (fluent), false on failure.
     */
    public function save(): static|false
    {
        $this->ensureDb();
        $this->_relCache = [];
        $pkValue = $this->getPrimaryKeyValue();

        $this->_db->startTransaction();
        try {
            if ($this->_exists || ($pkValue !== null && $this->recordExists($pkValue))) {
                $this->update();
            } else {
                $this->insert();
            }
            $this->_db->commit();
        } catch (\Exception $e) {
            $this->_db->rollback();
            return false;
        }

        $this->_exists = true;
        return $this;
    }

    /**
     * Find a record by primary key.
     *
     * @return $this
     */
    public function findById(int|string $id): self
    {
        $this->ensureDb();
        $this->_relCache = [];

        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "SELECT * FROM {$this->tableName} WHERE {$pkColumn} = ?";
        if ($this->softDelete) {
            $sql .= " AND is_deleted = 0";
        }

        $result = $this->selectOne($sql, [$id]);
        if ($result !== null) {
            $this->fill($result->getData());
            $this->_exists = true;
        }

        return $this;
    }

    /**
     * Load a record into this instance via selectOne.
     *
     * Returns true if a record was found and loaded, false otherwise.
     *
     * @param string $sql    Raw SQL query
     * @param array  $params Bind parameters
     * @param ?array $include Relationship names to eager-load
     * @return bool
     */
    public function load(string $sql, array $params = [], ?array $include = null): bool
    {
        $result = $this->selectOne($sql, $params, $include);
        if ($result === null) {
            return false;
        }
        $this->fill($result->getData());
        $this->_exists = true;
        return true;
    }

    /**
     * Delete the record (or soft delete if enabled).
     *
     * @return bool True on success
     */
    public function delete(): bool
    {
        $this->ensureDb();

        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return false;
        }

        $pkColumn = $this->getDbColumn($this->primaryKey);

        if ($this->softDelete) {
            $sql = "UPDATE {$this->tableName} SET is_deleted = 1 WHERE {$pkColumn} = :id";
        } else {
            $sql = "DELETE FROM {$this->tableName} WHERE {$pkColumn} = :id";
        }

        $this->_db->startTransaction();
        try {
            $result = $this->_db->exec($sql, [':id' => $pkValue]);
            if ($result) {
                $this->_exists = false;
            }
            $this->_db->commit();
        } catch (\Exception $e) {
            $this->_db->rollback();
            throw $e;
        }

        return $result;
    }

    /**
     * Find records matching a filter.
     *
     * @param array<string, mixed> $filter Key-value pairs for WHERE conditions
     * @param int $limit Max results
     * @param int $offset Starting offset
     * @param string|null $orderBy ORDER BY clause
     * @return array<int, static> Array of model instances
     */
    public function find(array $filter = [], int $limit = 100, int $offset = 0, ?string $orderBy = null): array
    {
        $this->ensureDb();

        $conditions = [];
        $params = [];
        $paramIndex = 0;

        foreach ($filter as $key => $value) {
            $dbColumn = $this->getDbColumn($key);
            $paramName = ':p' . $paramIndex;
            $conditions[] = "{$dbColumn} = {$paramName}";
            $params[$paramName] = $value;
            $paramIndex++;
        }

        if ($this->softDelete) {
            $conditions[] = "is_deleted = 0";
        }

        $sql = "SELECT * FROM {$this->tableName}";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }

        $result = $this->_db->fetch($sql, $limit, $offset, $params);

        $models = [];
        foreach ($result['data'] as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Get all records with pagination.
     *
     * @return array{data: array<int, static>, total: int, limit: int, offset: int}
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName}";

        if ($this->softDelete) {
            $sql .= " WHERE is_deleted = 0";
        }

        $result = $this->_db->fetch($sql, $limit, $offset);

        $models = [];
        foreach ($result['data'] as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        return [
            'data' => $models,
            'total' => $result['total'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ];
    }

    /**
     * Count records matching conditions (respects soft delete and tableFilter).
     *
     * @param ?string $conditions Optional WHERE clause
     * @param array   $params     Bind parameters
     * @return int
     */
    public function count(?string $conditions = null, array $params = []): int
    {
        $this->ensureDb();

        $whereParts = [];
        if ($this->softDelete) {
            $whereParts[] = "is_deleted = 0";
        }
        if ($this->tableFilter) {
            $whereParts[] = $this->tableFilter;
        }
        if ($conditions !== null) {
            $whereParts[] = "({$conditions})";
        }

        $sql = "SELECT COUNT(*) as cnt FROM {$this->tableName}";
        if (!empty($whereParts)) {
            $sql .= " WHERE " . implode(" AND ", $whereParts);
        }

        $row = $this->_db->fetchOne($sql, $params);
        return $row ? (int)$row['cnt'] : 0;
    }

    /**
     * Convert the model to a dictionary (associative array).
     *
     * Optionally includes relationships via dot notation:
     *   $user->toDict(['posts', 'profile'])
     *   $user->toDict(['posts.comments'])
     *
     * @param array<string>|null $include Relationship names to include (supports dot notation for nesting)
     * @return array<string, mixed>
     */
    public function toDict(?array $include = null): array
    {
        $result = $this->_data;

        if ($include !== null) {
            // Group includes: top-level and nested
            $topLevel = [];
            foreach ($include as $inc) {
                $parts = explode('.', $inc, 2);
                $relName = $parts[0];
                if (!isset($topLevel[$relName])) {
                    $topLevel[$relName] = [];
                }
                if (count($parts) > 1) {
                    $topLevel[$relName][] = $parts[1];
                }
            }

            foreach ($topLevel as $relName => $nested) {
                // Access the relationship (triggers lazy load via __get)
                $related = $this->__get($relName);
                if ($related === null) {
                    $result[$relName] = null;
                } elseif (is_array($related)) {
                    $result[$relName] = array_map(
                        fn(ORM $r) => $r->toDict(!empty($nested) ? $nested : null),
                        $related
                    );
                } elseif ($related instanceof ORM) {
                    $result[$relName] = $related->toDict(!empty($nested) ? $nested : null);
                }
            }
        }

        return $result;
    }

    /**
     * Alias for toDict() — PHP-idiomatic name.
     *
     * @return array<string, mixed>
     */
    public function toAssoc(?array $include = null): array
    {
        return $this->toDict($include);
    }

    /**
     * Convert the model to an object (alias for toDict).
     *
     * @return array<string, mixed>
     */
    public function toObject(): array
    {
        return $this->toDict();
    }

    /**
     * Convert the model to an indexed list of values (keys stripped).
     * Matches Python/Ruby/Node.js toArray() semantics.
     *
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return array_values($this->_data);
    }

    /**
     * Alias for toArray().
     *
     * @return array<int, mixed>
     */
    public function toList(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the model to a JSON string.
     *
     * @param array<string>|null $include Relationship names to include (supports dot notation)
     */
    public function toJson(?array $include = null): string
    {
        $data = $this->toDict($include);
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Check if this model instance exists in the database.
     */
    public function exists(): bool
    {
        return $this->_exists;
    }

    /**
     * Get the primary key value.
     */
    public function getPrimaryKeyValue(): int|string|null
    {
        return $this->_data[$this->primaryKey] ?? null;
    }

    /**
     * Get the DB column name for a property (applying field mapping).
     */
    public function getDbColumn(string $property): string
    {
        return $this->fieldMapping[$property] ?? $property;
    }

    /**
     * Run a raw SQL SELECT and return model instances.
     * Maps to Python: select(sql, params, limit, skip)
     *
     * @param string $sql SQL SELECT statement
     * @param array $params Bound parameters
     * @param int $limit Max results
     * @param int $offset Starting offset
     * @return array<int, static>
     */
    public function select(string $sql, array $params = [], int $limit = 20, int $offset = 0): array
    {
        $this->ensureDb();
        $result = $this->_db->fetch($sql, $limit, $offset, $params);

        $models = [];
        foreach ($result['data'] as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Return a single ORM instance for a raw SQL query, or null if no rows match.
     * Maps to Python: select_one(sql, params, include)
     *
     * @param string     $sql     Raw SELECT SQL
     * @param array      $params  Bound parameters
     * @param array|null $include Relationship names to eager-load (dot notation supported)
     * @return static|null
     */
    public function selectOne(string $sql, array $params = [], ?array $include = null): ?static
    {
        $this->ensureDb();
        $row = $this->_db->fetchOne($sql, $params);
        if ($row === null) {
            return null;
        }
        $model = new static($this->_db);
        $model->fill($row);
        $model->_exists = true;
        if ($include !== null) {
            $adapter = $this->_db instanceof Database
                ? $this->_db->getAdapter()
                : $this->_db;
            $instances = [$model];
            static::eagerLoad($instances, $include, $adapter);
        }
        return $model;
    }

    /**
     * Query records with a WHERE clause.
     * Maps to Python: where(filter_sql, params, limit, skip)
     *
     * @param string $filterSql WHERE clause (without "WHERE")
     * @param array $params Bound parameters
     * @param int $limit Max results
     * @param int $offset Starting offset
     * @return array<int, static>
     */
    public function where(string $filterSql, array $params = [], int $limit = 20, int $offset = 0): array
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName} WHERE {$filterSql}";
        if ($this->softDelete) {
            $sql = "SELECT * FROM {$this->tableName} WHERE ({$filterSql}) AND is_deleted = 0";
        }

        $result = $this->_db->fetch($sql, $limit, $offset, $params);

        $models = [];
        foreach ($result['data'] as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Find a record by primary key or throw an exception.
     * Maps to Python: find_or_fail(pk_value)
     *
     * @throws \RuntimeException If the record is not found
     */
    public function findOrFail(int|string $id): static
    {
        $model = new static($this->_db);
        $model->findById($id);

        if (!$model->exists()) {
            throw new \RuntimeException("Record not found in {$this->tableName} with {$this->primaryKey} = {$id}");
        }

        return $model;
    }

    /**
     * Force-delete a record (bypass soft delete).
     * Maps to Python: force_delete()
     */
    public function forceDelete(): bool
    {
        $this->ensureDb();

        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return false;
        }

        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "DELETE FROM {$this->tableName} WHERE {$pkColumn} = :id";
        $result = $this->_db->exec($sql, [':id' => $pkValue]);

        if ($result) {
            $this->_exists = false;
        }

        $this->_db->commit();
        return $result;
    }

    /**
     * Restore a soft-deleted record.
     * Maps to Python: restore()
     */
    public function restore(): bool
    {
        if (!$this->softDelete) {
            return false;
        }

        $this->ensureDb();

        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return false;
        }

        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "UPDATE {$this->tableName} SET is_deleted = 0 WHERE {$pkColumn} = :id";
        $result = $this->_db->exec($sql, [':id' => $pkValue]);

        if ($result) {
            $this->_exists = true;
            $this->_data['is_deleted'] = 0;
        }

        $this->_db->commit();
        return $result;
    }

    /**
     * Query records including soft-deleted ones.
     * Maps to Python: with_trashed(filter_sql, params, limit, skip)
     *
     * @param string $filterSql WHERE clause (default "1=1" for all)
     * @param array $params Bound parameters
     * @param int $limit Max results
     * @param int $offset Starting offset
     * @return array<int, static>
     */
    public function withTrashed(string $filterSql = '1=1', array $params = [], int $limit = 20, int $offset = 0): array
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName} WHERE {$filterSql}";
        $result = $this->_db->fetch($sql, $limit, $offset, $params);

        $models = [];
        foreach ($result['data'] as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Validate the model data. Override in subclasses to add rules.
     * Maps to Python: validate()
     *
     * @return array<string> List of validation error messages (empty = valid)
     */
    public function validate(): array
    {
        return [];
    }

    /**
     * Apply a named scope query.
     * Maps to Python: scope(name, filter_sql, params)
     *
     * @param string $name Scope name (for identification)
     * @param string $filterSql WHERE clause
     * @param array $params Bound parameters
     * @return array<int, static>
     */
    public function scope(string $name, string $filterSql, array $params = []): array
    {
        return $this->where($filterSql, $params);
    }

    /**
     * Get a has-one related model.
     * Maps to Python: has_one(related_class, foreign_key)
     *
     * @param string $relatedClass Fully qualified class name of the related ORM model
     * @param string|null $foreignKey Foreign key column (defaults to thisTable_id)
     * @return static|null The related model or null
     */
    public function hasOne(string $relatedClass, ?string $foreignKey = null): ?ORM
    {
        $this->ensureDb();
        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return null;
        }

        if ($foreignKey === null) {
            $foreignKey = rtrim($this->tableName, 's') . '_id';
        }

        /** @var ORM $related */
        $related = new $relatedClass($this->_db);
        $results = $related->where("{$foreignKey} = :fk", [':fk' => $pkValue], 1);
        return $results[0] ?? null;
    }

    /**
     * Get has-many related models.
     * Maps to Python: has_many(related_class, foreign_key, limit, skip)
     *
     * @param string $relatedClass Fully qualified class name of the related ORM model
     * @param string|null $foreignKey Foreign key column (defaults to thisTable_id)
     * @param int $limit Max results
     * @param int $offset Starting offset
     * @return array<int, static>
     */
    public function hasMany(string $relatedClass, ?string $foreignKey = null, int $limit = 100, int $offset = 0): array
    {
        $this->ensureDb();
        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return [];
        }

        if ($foreignKey === null) {
            $foreignKey = rtrim($this->tableName, 's') . '_id';
        }

        /** @var ORM $related */
        $related = new $relatedClass($this->_db);
        return $related->where("{$foreignKey} = :fk", [':fk' => $pkValue], $limit, $offset);
    }

    /**
     * Get the parent model in a belongs-to relationship.
     * Maps to Python: belongs_to(related_class, foreign_key)
     *
     * @param string $relatedClass Fully qualified class name of the parent ORM model
     * @param string|null $foreignKey Foreign key column on THIS model (defaults to relatedTable_id)
     * @return static|null The parent model or null
     */
    public function belongsTo(string $relatedClass, ?string $foreignKey = null): ?ORM
    {
        $this->ensureDb();

        /** @var ORM $related */
        $related = new $relatedClass($this->_db);

        if ($foreignKey === null) {
            $foreignKey = rtrim($related->tableName, 's') . '_id';
        }

        $fkValue = $this->_data[$foreignKey] ?? null;
        if ($fkValue === null) {
            return null;
        }

        $parent = new $relatedClass($this->_db);
        $parent->load($fkValue);
        return $parent->exists() ? $parent : null;
    }

    /**
     * Create the table for this model from its column definitions.
     * Maps to Python: create_table()
     *
     * Uses getColumns() on the adapter to introspect, falls back to
     * generating DDL from the model's data keys if the table does not exist.
     *
     * @param array<string, string> $columns Column definitions: ['name' => 'TEXT', 'age' => 'INTEGER']
     *                                       If empty, uses existing model data keys with TEXT type.
     * @return bool True on success
     */
    public function createTable(array $columns = []): bool
    {
        $this->ensureDb();

        if ($this->_db->tableExists($this->tableName)) {
            return true;
        }

        if (empty($columns)) {
            // No column definitions — create a minimal table with just the primary key
            $pkColumn = $this->getDbColumn($this->primaryKey);
            $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} ({$pkColumn} INTEGER PRIMARY KEY AUTOINCREMENT)";
        } else {
            $colDefs = [];
            foreach ($columns as $colName => $colType) {
                $colDefs[] = "{$colName} {$colType}";
            }
            $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (" . implode(', ', $colDefs) . ")";
        }

        $result = $this->_db->execute($sql);
        $this->_db->commit();
        return $result;
    }

    /**
     * Get all data (for internal use by AutoCrud etc.).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->_data;
    }

    /**
     * Mark this model as existing in the database.
     */
    public function markAsExisting(): void
    {
        $this->_exists = true;
    }

    /**
     * Insert a new record.
     */
    private function insert(): bool
    {
        $data = $this->getDbData();

        if (empty($data)) {
            return false;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));

        $params = [];
        foreach ($data as $col => $value) {
            $params[":{$col}"] = $value;
        }

        $sql = "INSERT INTO {$this->tableName} ({$columns}) VALUES ({$placeholders})";
        $result = $this->_db->exec($sql, $params);

        if ($result) {
            $this->_exists = true;
            // Set the auto-generated ID if not already set
            $pkValue = $this->getPrimaryKeyValue();
            if ($pkValue === null || $pkValue === 0) {
                $this->_data[$this->primaryKey] = $this->_db->lastInsertId();
            }
        }

        return $result;
    }

    /**
     * Update an existing record.
     */
    private function update(): bool
    {
        $data = $this->getDbData();
        $pkColumn = $this->getDbColumn($this->primaryKey);
        $pkValue = $this->getPrimaryKeyValue();

        // Remove primary key from update data
        unset($data[$pkColumn]);

        if (empty($data)) {
            return true; // Nothing to update
        }

        $setClauses = [];
        $params = [];

        foreach ($data as $col => $value) {
            $setClauses[] = "{$col} = :{$col}";
            $params[":{$col}"] = $value;
        }

        $params[':pk_id'] = $pkValue;

        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $setClauses) . " WHERE {$pkColumn} = :pk_id";

        return $this->_db->exec($sql, $params);
    }

    /**
     * Get the model data mapped to DB column names.
     *
     * @return array<string, mixed>
     */
    private function getDbData(): array
    {
        // When autoMap is enabled, ensure camelCase properties have
        // snake_case mappings before converting to DB column names.
        if ($this->autoMap) {
            foreach (array_keys($this->_data) as $prop) {
                if (!isset($this->fieldMapping[$prop])) {
                    $snaked = self::camelToSnake($prop);
                    if ($snaked !== $prop) {
                        $this->fieldMapping[$prop] = $snaked;
                    }
                }
            }
        }

        $data = [];

        foreach ($this->_data as $prop => $value) {
            $column = $this->getDbColumn($prop);
            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * Check if a record with the given primary key exists.
     */
    private function recordExists(int|string $id): bool
    {
        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "SELECT COUNT(*) as cnt FROM {$this->tableName} WHERE {$pkColumn} = :id";
        $rows = $this->_db->query($sql, [':id' => $id]);
        return !empty($rows) && (int)$rows[0]['cnt'] > 0;
    }

    /**
     * Load a relationship by parsing the definition string ('ModelClass.foreign_key').
     *
     * @param string $name Property name
     * @param string $definition Relationship definition (e.g., 'Post.user_id')
     * @param string $type 'hasOne', 'hasMany', or 'belongsTo'
     * @return ORM|array|null
     */
    private function _loadRelationship(string $name, string $definition, string $type): ORM|array|null
    {
        $this->ensureDb();

        $parts = explode('.', $definition, 2);
        $relatedClass = $parts[0];
        $foreignKey = $parts[1] ?? null;

        if ($type === 'hasOne') {
            if ($foreignKey === null) {
                $foreignKey = rtrim($this->tableName, 's') . '_id';
            }
            return $this->hasOneMethod($relatedClass, $foreignKey);
        } elseif ($type === 'hasMany') {
            if ($foreignKey === null) {
                $foreignKey = rtrim($this->tableName, 's') . '_id';
            }
            return $this->hasManyMethod($relatedClass, $foreignKey);
        } elseif ($type === 'belongsTo') {
            if ($foreignKey === null) {
                /** @var ORM $temp */
                $temp = new $relatedClass($this->_db);
                $foreignKey = rtrim($temp->tableName, 's') . '_id';
            }
            return $this->belongsToMethod($relatedClass, $foreignKey);
        }

        return null;
    }

    /**
     * Eager load relationships for a collection of model instances (prevents N+1).
     *
     * @param array<ORM> $instances Model instances
     * @param array<string> $include Relationship names (supports dot notation for nesting)
     * @param DatabaseAdapter $db Database adapter
     */
    public static function eagerLoad(array &$instances, array $include, DatabaseAdapter $db): void
    {
        if (empty($instances)) {
            return;
        }

        $sample = $instances[0];

        // Group includes: top-level and nested
        $topLevel = [];
        foreach ($include as $inc) {
            $parts = explode('.', $inc, 2);
            $relName = $parts[0];
            if (!isset($topLevel[$relName])) {
                $topLevel[$relName] = [];
            }
            if (count($parts) > 1) {
                $topLevel[$relName][] = $parts[1];
            }
        }

        foreach ($topLevel as $relName => $nested) {
            $definition = null;
            $type = null;

            if (isset($sample->hasOne[$relName])) {
                $definition = $sample->hasOne[$relName];
                $type = 'hasOne';
            } elseif (isset($sample->hasMany[$relName])) {
                $definition = $sample->hasMany[$relName];
                $type = 'hasMany';
            } elseif (isset($sample->belongsTo[$relName])) {
                $definition = $sample->belongsTo[$relName];
                $type = 'belongsTo';
            }

            if ($definition === null) {
                continue;
            }

            $defParts = explode('.', $definition, 2);
            $relatedClass = $defParts[0];
            $foreignKey = $defParts[1] ?? null;

            if ($type === 'hasOne' || $type === 'hasMany') {
                if ($foreignKey === null) {
                    $foreignKey = rtrim($sample->tableName, 's') . '_id';
                }

                $pkValues = [];
                foreach ($instances as $inst) {
                    $pkVal = $inst->getPrimaryKeyValue();
                    if ($pkVal !== null) {
                        $pkValues[] = $pkVal;
                    }
                }

                if (empty($pkValues)) {
                    continue;
                }

                /** @var ORM $relTemplate */
                $relTemplate = new $relatedClass($db);
                $placeholders = implode(',', array_fill(0, count($pkValues), '?'));
                $sql = "SELECT * FROM {$relTemplate->tableName} WHERE {$foreignKey} IN ({$placeholders})";
                $result = $db->fetch($sql, count($pkValues) * 1000, 0, $pkValues);

                $related = [];
                foreach ($result['data'] as $row) {
                    $model = new $relatedClass($db);
                    $model->fill($row);
                    $model->_exists = true;
                    $related[] = $model;
                }

                // Eager load nested
                if (!empty($nested) && !empty($related)) {
                    self::eagerLoad($related, $nested, $db);
                }

                // Group by FK
                $grouped = [];
                foreach ($related as $record) {
                    $fkVal = $record->__get($foreignKey);
                    $grouped[$fkVal][] = $record;
                }

                foreach ($instances as $inst) {
                    $pkVal = $inst->getPrimaryKeyValue();
                    $records = $grouped[$pkVal] ?? [];
                    if ($type === 'hasOne') {
                        $inst->_relCache[$relName] = $records[0] ?? null;
                    } else {
                        $inst->_relCache[$relName] = $records;
                    }
                }

            } elseif ($type === 'belongsTo') {
                /** @var ORM $relTemplate */
                $relTemplate = new $relatedClass($db);
                if ($foreignKey === null) {
                    $foreignKey = rtrim($relTemplate->tableName, 's') . '_id';
                }

                $fkValues = [];
                foreach ($instances as $inst) {
                    $fkVal = $inst->__get($foreignKey);
                    if ($fkVal !== null) {
                        $fkValues[$fkVal] = true;
                    }
                }
                $fkValues = array_keys($fkValues);

                if (empty($fkValues)) {
                    continue;
                }

                $placeholders = implode(',', array_fill(0, count($fkValues), '?'));
                $relPk = $relTemplate->primaryKey;
                $sql = "SELECT * FROM {$relTemplate->tableName} WHERE {$relPk} IN ({$placeholders})";
                $result = $db->fetch($sql, count($fkValues) * 10, 0, $fkValues);

                $lookup = [];
                foreach ($result['data'] as $row) {
                    $model = new $relatedClass($db);
                    $model->fill($row);
                    $model->_exists = true;
                    $lookup[$model->getPrimaryKeyValue()] = $model;
                }

                if (!empty($nested) && !empty($lookup)) {
                    $lookupList = array_values($lookup);
                    self::eagerLoad($lookupList, $nested, $db);
                }

                foreach ($instances as $inst) {
                    $fkVal = $inst->__get($foreignKey);
                    $inst->_relCache[$relName] = $lookup[$fkVal] ?? null;
                }
            }
        }
    }

    /**
     * Internal: has-one query (used by lazy and explicit loading).
     */
    private function hasOneMethod(string $relatedClass, string $foreignKey): ?ORM
    {
        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return null;
        }

        /** @var ORM $related */
        $related = new $relatedClass($this->_db);
        $results = $related->where("{$foreignKey} = :fk", [':fk' => $pkValue], 1);
        return $results[0] ?? null;
    }

    /**
     * Internal: has-many query (used by lazy and explicit loading).
     */
    private function hasManyMethod(string $relatedClass, string $foreignKey, int $limit = 100, int $offset = 0): array
    {
        $pkValue = $this->getPrimaryKeyValue();
        if ($pkValue === null) {
            return [];
        }

        /** @var ORM $related */
        $related = new $relatedClass($this->_db);
        return $related->where("{$foreignKey} = :fk", [':fk' => $pkValue], $limit, $offset);
    }

    /**
     * Internal: belongs-to query (used by lazy and explicit loading).
     */
    private function belongsToMethod(string $relatedClass, string $foreignKey): ?ORM
    {
        $fkValue = $this->_data[$foreignKey] ?? null;
        if ($fkValue === null) {
            return null;
        }

        $parent = new $relatedClass($this->_db);
        $parent->load($fkValue);
        return $parent->exists() ? $parent : null;
    }

    /**
     * Set a relationship cache value directly (used by eager loading).
     */
    public function setRelCache(string $name, mixed $value): void
    {
        $this->_relCache[$name] = $value;
    }

    /**
     * Clear the relationship cache.
     */
    public function clearRelCache(): void
    {
        $this->_relCache = [];
    }

    /**
     * Ensure a database connection is available.
     *
     * @throws \RuntimeException If no database adapter is set
     */
    private function ensureDb(): void
    {
        if ($this->_db !== null) {
            return;
        }

        // Try auto-discovery from DATABASE_URL
        $discovered = \Tina4\Database\Database::fromEnv();
        if ($discovered !== null) {
            $this->_db = $discovered;
            return;
        }

        throw new \RuntimeException('ORM: No database connection. Call setDb() first or set DATABASE_URL in .env');
    }
}
