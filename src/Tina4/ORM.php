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
     * Convert the model to an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->_data;
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
