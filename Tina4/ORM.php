<?php

/**
 * Tina4 - This is not a 4ramework.
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

    /** @var bool Whether soft delete is enabled */
    public bool $softDelete = false;

    /** @var array<string, string> Has-one relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasOne = [];

    /** @var array<string, string> Has-many relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasMany = [];

    /** @var DatabaseAdapter|null Database connection */
    protected ?DatabaseAdapter $_db = null;

    /** @var array<string, mixed> Model data storage */
    private array $_data = [];

    /** @var bool Whether this record exists in the database */
    private bool $_exists = false;

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
     */
    public function __get(string $name): mixed
    {
        return $this->_data[$name] ?? null;
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
    public function save(): bool
    {
        $this->ensureDb();
        $pkValue = $this->getPrimaryKeyValue();

        if ($this->_exists || ($pkValue !== null && $this->recordExists($pkValue))) {
            return $this->update();
        }

        return $this->insert();
    }

    /**
     * Load a record by primary key.
     *
     * @return $this
     */
    public function load(int|string $id): self
    {
        $this->ensureDb();

        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "SELECT * FROM {$this->tableName} WHERE {$pkColumn} = :id";

        if ($this->softDelete) {
            $sql .= " AND is_deleted = 0";
        }

        $rows = $this->_db->query($sql, [':id' => $id]);

        if (!empty($rows)) {
            $this->fill($rows[0]);
            $this->_exists = true;
        }

        return $this;
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

        $result = $this->_db->exec($sql, [':id' => $pkValue]);

        if ($result) {
            $this->_exists = false;
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
     * Convert the model to a dictionary (associative array).
     * Maps to Python: to_dict()
     *
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return $this->_data;
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
     * Convert the model to an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->_data;
    }

    /**
     * Convert the model to an indexed list of values (keys stripped).
     *
     * @return array<int, mixed>
     */
    public function toList(): array
    {
        return array_values($this->_data);
    }

    /**
     * Convert the model to a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        $model->load($id);

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

        return $this->_db->execute($sql);
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
     * Ensure a database connection is available.
     *
     * @throws \RuntimeException If no database adapter is set
     */
    private function ensureDb(): void
    {
        if ($this->_db === null) {
            throw new \RuntimeException('ORM: No database adapter set. Call setDb() first.');
        }
    }
}
