<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;
use Tina4\SqlTranslation;

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
    public bool $autoMap = true;

    /** @var bool Whether soft delete is enabled */
    public bool $softDelete = false;

    /** @var bool When true, auto-registers this model for CRUD route generation via AutoCrud */
    public bool $autoCrud = false;

    /** @var array<string, string> Has-one relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasOne = [];

    /** @var array<string, string> Has-many relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $hasMany = [];

    /** @var array<string, string> Belongs-to relationships: ['propertyName' => 'ForeignModel.foreign_key'] */
    public array $belongsTo = [];

    /**
     * Foreign key field declarations — auto-wires belongsTo on this model and hasMany on the referenced model.
     *
     * Format:
     *   'user_id' => 'User'                                          // simple: model name
     *   'user_id' => ['model' => 'User', 'related_name' => 'posts']  // extended: with custom has-many key
     *
     * @var array<string, string|array>
     */
    public array $foreignKeys = [];

    /**
     * Per-model database connection. May be:
     *   - a DatabaseAdapter instance (direct binding), or
     *   - a string naming a connection registered via ORM::bindDatabase($db, name: '...'), or
     *   - null → resolves from the global default / App / TINA4_DATABASE_URL.
     *
     * Declared public so subclasses can override it (e.g. `public DatabaseAdapter|string|null $_db = 'analytics';`).
     * PHP property types are invariant, so an override must repeat this exact type.
     *
     * @var DatabaseAdapter|string|null
     */
    public DatabaseAdapter|string|null $_db = null;

    /** @var DatabaseAdapter|null Global default database for static methods (set via ORM::bindDatabase or App::setDatabase) */
    private static ?DatabaseAdapter $_globalDb = null;

    /** @var array<string, DatabaseAdapter> Named connection registry (set via ORM::bindDatabase($db, name: '...')) */
    protected static array $_namedDbs = [];

    /**
     * Cross-model registry: maps target model class name → list of has-many relationship specs.
     * Populated by _processForeignKeys() when a model declares $foreignKeys.
     *
     * @var array<string, array<int, array{key: string, spec: string}>>
     */
    private static array $_fkRegistry = [];

    /**
     * Bind a database to ORM models. Equivalent to Python's bind_database(db, name=None).
     *
     *   - $name === null → set the global default used by all models without a $_db.
     *   - $name !== null → register a named connection. A model can then select it via
     *     `public DatabaseAdapter|string|null $_db = '<name>';`.
     *
     * @param DatabaseAdapter $db   The connection to bind.
     * @param string|null     $name Optional connection name (e.g. 'analytics', 'audit').
     */
    public static function bindDatabase(DatabaseAdapter $db, ?string $name = null): void
    {
        if ($name === null) {
            self::$_globalDb = $db;
        } else {
            self::$_namedDbs[$name] = $db;
        }
    }

    /**
     * Get the global database if one is set.
     */
    public static function getGlobalDb(): ?DatabaseAdapter
    {
        return self::$_globalDb;
    }

    /**
     * Resolve the connection for a model instance.
     *
     * Resolution order:
     *   1. $instance->_db is a DatabaseAdapter → use it directly.
     *   2. $instance->_db is a string → look it up in the named-connection registry.
     *   3. $instance->_db is null → fall back to resolveDb() (global → App → .env).
     *
     * @throws \RuntimeException If a named connection is referenced but not registered.
     */
    protected static function resolveDbFor(ORM $instance): DatabaseAdapter
    {
        $db = $instance->_db;
        if ($db instanceof DatabaseAdapter) {
            return $db;
        }
        if (is_string($db)) {
            if (!isset(self::$_namedDbs[$db])) {
                throw new \RuntimeException(
                    "Named database '{$db}' not found. "
                    . "Call ORM::bindDatabase(\$db, name: '{$db}') first."
                );
            }
            return self::$_namedDbs[$db];
        }
        return static::resolveDb();
    }

    /**
     * Resolve the global/default database for static methods.
     * Checks: global $_globalDb → App::getDatabase() → Database::fromEnv()
     */
    protected static function resolveDb(): DatabaseAdapter
    {
        if (self::$_globalDb !== null) {
            return self::$_globalDb;
        }
        $appDb = App::getDatabase();
        if ($appDb !== null) {
            return $appDb;
        }
        $envDb = Database\Database::fromEnv();
        if ($envDb !== null) {
            return $envDb;
        }
        throw new \RuntimeException('No database configured. Call ORM::bindDatabase(), App::setDatabase(), or set TINA4_DATABASE_URL in .env');
    }

    /**
     * Cause of the most recent failed {@see save()} (a validation message or a
     * driver error), or null when the last save() succeeded. Mirrors the
     * adapter's getError()/error(): after save() returns false a caller using
     * the documented `if (!$model->save())` contract can still recover the real
     * cause via {@see getError()} / {@see error()} / this property — the failure
     * never vanishes silently. Cleared to null on a successful save().
     *
     * @var string|null
     */
    public ?string $lastError = null;

    /** @var array<string, mixed> Storage for undeclared/dynamic properties only (from joins, extras) */
    private array $_dynamicProps = [];

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
    /**
     * Create a fluent QueryBuilder for this model's table.
     *
     * Works as both static and instance method:
     *   User::query()->where('active = ?', [1])->get();
     *   $user->query()->where('name LIKE ?', ['%alice%'])->get();
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return QueryBuilder::fromTable($instance->tableName, static::resolveDbFor($instance));
    }

    /**
     * Create a new record from an associative array and persist it immediately.
     *
     * Usage:
     *   $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
     *
     * v3.13.39: if the underlying {@see save()} fails (validation errors or a
     * driver error), create() returns false — it does NOT hand back a
     * possibly-unsaved instance, so a failed insert can never masquerade as a
     * success. The failure cause is logged and available on the (discarded)
     * instance's getError() via the same path save() uses.
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return static|false The saved ORM instance, or false when save() failed.
     */
    public static function create(array $data = []): static|false
    {
        $instance = new static(data: $data);
        if ($instance->save() === false) {
            return false;
        }
        return $instance;
    }

    /**
     * Convert a snake_case name to camelCase.
     */
    public static function snakeToCamel(string $name): string
    {
        $name = strtolower($name); // normalise uppercase column names (Firebird/Oracle)
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    /**
     * Convert a camelCase name to snake_case.
     */
    public static function camelToSnake(string $name): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    /**
     * Derive the default foreign-key column that points at the given model's
     * primary key: the model's (short) class name, lowercased, + "_id".
     *
     * v3.13.39: aligns the FK default with the Python master
     * (`f"{Model.__name__.lower()}_id"`). The old PHP rule naively stripped a
     * trailing "s" off the *table name* (`rtrim($tableName, 's') . '_id'`),
     * which broke for table names that don't pluralise by adding "s" (e.g.
     * "people" → "people_id" wanted "person_id"; "status" → "statu_id") and
     * disagreed with the auto-wired has-many key derivation in
     * _processForeignKeys() (which already uses the class name). Deriving from
     * the class name makes both sides consistent: a Post → User has-many
     * defaults to the FK column "user_id" on posts.
     *
     * @param object|string $modelOrClass An ORM instance or its class name.
     */
    public static function defaultForeignKey(object|string $modelOrClass): string
    {
        $class = is_object($modelOrClass) ? $modelOrClass::class : $modelOrClass;
        $shortName = basename(str_replace('\\', '/', $class));
        return strtolower($shortName) . '_id';
    }

    /**
     * Create a new ORM instance.
     *
     * @param DatabaseAdapter|null $db Database adapter
     * @param array<string, mixed> $data Initial data to populate
     */
    public function __construct(DatabaseAdapter|array|string|null $db = null, array $data = [])
    {
        // The first arg is the DB adapter (legacy) OR the record data itself —
        // an array (new Widget($body)) or a JSON object string
        // (new Widget('{"id":1}')). Detect by type so all of these work:
        //   new Widget($db, $data)   new Widget(data: $body)
        //   new Widget($body)        new Widget('{"json":1}')
        if ($db instanceof DatabaseAdapter) {
            $this->_db = $db;
        } elseif (is_string($db)) {
            $decoded = json_decode($db, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException(static::class . '(): invalid JSON string passed to constructor.');
            }
            $data = $decoded;
        } elseif (is_array($db)) {
            $data = $db;
        }

        // A single model is one record — reject a non-empty list with a clear
        // message (parity with Python/Ruby/Node).
        if (!empty($data) && array_is_list($data)) {
            throw new \InvalidArgumentException(
                static::class . '() expects an object/associative array or JSON object string '
                . 'for one record — got a list. Map over the list to build many records.'
            );
        }

        if (!empty($data)) {
            $this->fill($data);
        }

        // Auto-wire relationships from $foreignKeys declarations
        $this->_processForeignKeys();

        // Auto-register for CRUD if flagged
        if ($this->autoCrud && $this->tableName !== '') {
            static $autoCrudRegistered = [];
            $class = static::class;
            if (!isset($autoCrudRegistered[$class])) {
                $autoCrudRegistered[$class] = true;
                try {
                    $crud = new AutoCrud(static::resolveDbFor($this));
                    $crud->register($class);
                    $crud->generateRoutes();
                } catch (\Throwable $e) {
                    // Silently skip if AutoCrud not available or DB not ready
                }
            }
        }
    }

    /**
     * Magic setter — stores undeclared properties in _dynamicProps.
     * Declared public properties are set directly by PHP (this never fires for them).
     */
    public function __set(string $name, mixed $value): void
    {
        $this->_dynamicProps[$name] = $value;
    }

    /**
     * Magic getter — checks dynamic props, then lazy-loads relationships.
     * Declared public properties are read directly by PHP (this never fires for them).
     */
    public function __get(string $name): mixed
    {
        // Check dynamic properties (from joins, extras)
        if (array_key_exists($name, $this->_dynamicProps)) {
            return $this->_dynamicProps[$name];
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

        // Merge any FK-registry-registered has-many entries for this class
        $registryEntries = self::$_fkRegistry[static::class] ?? [];
        foreach ($registryEntries as $entry) {
            if (!isset($this->hasMany[$entry['key']])) {
                $this->hasMany[$entry['key']] = $entry['spec'];
            }
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
        return isset($this->_dynamicProps[$name]);
    }

    /**
     * Unset a property.
     */
    public function __unset(string $name): void
    {
        unset($this->_dynamicProps[$name]);
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
                // Skip keys that are already camelCase (no underscores, not
                // all-uppercase) — they came from getData() or user code,
                // not from the database. Running snakeToCamel on them would
                // lowercase the first char and corrupt the key.
                if (!str_contains($col, '_') && !ctype_upper($col)) {
                    continue;
                }
                $camel = self::snakeToCamel($col);
                if ($camel !== $col && !isset($this->fieldMapping[$camel])) {
                    $this->fieldMapping[$camel] = $col;
                }
            }
        }

        $reverseMapping = array_flip($this->fieldMapping);
        $jsonColumns = $this->jsonColumns();

        foreach ($data as $key => $value) {
            // Map DB column to PHP property if mapping exists
            $propName = $reverseMapping[$key] ?? $key;

            // A JSON column comes back from the driver as a JSON string (SQLite
            // TEXT, MySQL JSON, PostgreSQL JSONB via the text protocol, MSSQL
            // NVARCHAR). Decode it to the dict/list the `array`-typed property
            // expects (parity with Python's JSONField parse-on-read). A value
            // that is already an array (fresh user input, e.g.
            // new Doc(['payload' => ['x' => 1]])) is left untouched; null stays
            // null for a nullable `?array` column.
            if (isset($jsonColumns[$propName]) && is_string($value)) {
                $decoded = json_decode($value, true);
                if ($decoded !== null || trim($value) === 'null') {
                    $value = $decoded;
                }
            }

            // Set directly on the object — declared properties get set natively,
            // undeclared ones go through __set → _dynamicProps
            $this->$propName = $value;
        }

        return $this;
    }

    /**
     * Insert or update. Returns $this on success (fluent), false on failure.
     *
     * Fails loud, never silent (the same principle the adapter's execute()
     * follows by RAISING). On *any* failure path save() returns false — keeping
     * the contract callers rely on (`if (!$model->save()) { ... }`) — but it
     * also (a) records the real cause on {@see $lastError} so the caller can
     * recover it via {@see getError()} / {@see error()} after the fact, and
     * (b) logs it via {@see Log::error} with model/table context. It never
     * raises and never changes the `static|false` return shape. On success the
     * recorded error is cleared.
     *
     * Two distinct failure paths, both loud:
     *
     *   1. **Validation** (v3.13.39): {@see validate()} runs FIRST. If it
     *      returns errors, save() records them on $lastError, logs them, and
     *      returns false WITHOUT touching the database — an invalid model never
     *      reaches the driver.
     *   2. **Database** (v3.13.39): a driver error (NOT NULL, duplicate PK,
     *      missing table, …) is rolled back, the real cause is captured
     *      (preferring the adapter's getError()/error(), falling back to the
     *      exception text), logged with model/table context, recorded on
     *      $lastError, and false is returned — the cause is no longer swallowed.
     *
     * @return static|false
     */
    public function save(): static|false
    {
        $this->ensureDb();

        // ── Change 2: validate() is enforced. An invalid model never reaches
        // the driver — fail loud (record + log), return false, write nothing. ──
        $errors = $this->validate();
        if (!empty($errors)) {
            $this->lastError = implode('; ', $errors);
            Log::error(
                static::class . '::save() refused: validation failed — ' . $this->lastError,
                ['table' => $this->tableName]
            );
            return false;
        }

        $this->_relCache = [];
        $pkValue = $this->getPrimaryKeyValue();

        // A blank PK (null / 0 / '') means "not set yet" — the engine will
        // assign it (SERIAL, or a `uuid ... DEFAULT gen_random_uuid()` etc.), so
        // this is a fresh INSERT. Treating '' / 0 as set used to run the
        // recordExists() probe with an empty value: on a PostgreSQL `uuid` PK
        // `WHERE id = ''` raises "invalid input syntax for type uuid", which
        // aborts the whole transaction and the real INSERT then failed with
        // "current transaction is aborted" (issue #256). insert() drops the same
        // blank PK from the payload, so the two decisions stay in lockstep.
        $pkIsSet = $pkValue !== null && $pkValue !== 0 && $pkValue !== '';

        $this->_db->startTransaction();
        try {
            if ($this->_exists || ($pkIsSet && $this->recordExists($pkValue))) {
                $ok = $this->update();
            } else {
                $ok = $this->insert();
            }

            // Two failure shapes, both honoured so save() keeps its
            // documented `save(): static|false` contract:
            //   1. A bound raw adapter's low-level exec() still returns false
            //      on a bad statement (e.g. an UPDATE referencing a public
            //      model property with no matching DB column) — issue #114.
            //   2. The Database facade's execute()/exec() now RAISES on a SQL
            //      error (FAIL LOUD contract) — caught below.
            // Before #114 added the false-check, a bare false slipped through,
            // the empty transaction got committed, and save() returned $this —
            // silent data loss.
            if ($ok === false) {
                $this->_db->rollback();
                // ── Change 1: fail loud, never silent. Capture the REAL cause
                // (adapter getError()/error()), record it, and log it. ──
                $this->lastError = $this->dbError() ?? 'save failed';
                Log::error(
                    static::class . "::save() failed for table '{$this->tableName}': " . $this->lastError,
                    ['table' => $this->tableName]
                );
                return false;
            }

            $this->_db->commit();
        } catch (\Throwable $e) {
            $this->_db->rollback();
            // ── Change 1: fail loud, never silent. Keep the false return
            // contract, but capture the REAL cause (prefer the adapter's
            // getError(), which execute()/exec() populate, falling back to the
            // exception text) on $lastError so it survives, and log it with
            // model/table context. ──
            $this->lastError = $this->dbError() ?? $e->getMessage();
            Log::error(
                static::class . "::save() failed for table '{$this->tableName}': " . $this->lastError,
                ['table' => $this->tableName]
            );
            return false;
        }

        $this->lastError = null;
        $this->_exists = true;
        return $this;
    }

    /**
     * Return the cause of the most recent failed {@see save()}, or null.
     *
     * Mirrors the adapter's getError(). After save() returns false — whether
     * from validation or a driver error — the real cause is retrievable here
     * (and on {@see $lastError}) so a caller using the `if (!$model->save())`
     * contract can still surface it. Cleared to null on a successful save().
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Alias for {@see getError()} — mirrors the adapter's error() accessor name
     * so callers can use whichever convention their adapter uses.
     */
    public function error(): ?string
    {
        return $this->lastError;
    }

    /**
     * Read the underlying driver error from the bound connection, trying the
     * facade's getError() first (set by Database::execute()/exec() on a raised
     * failure) and falling back to the adapter-level error(). Returns null when
     * neither yields a cause.
     */
    private function dbError(): ?string
    {
        $db = $this->_db;
        if (is_object($db) && method_exists($db, 'getError')) {
            $msg = $db->getError();
            if ($msg !== null && $msg !== '') {
                return $msg;
            }
        }
        if (is_object($db) && method_exists($db, 'error')) {
            $msg = $db->error();
            if ($msg !== null && $msg !== '') {
                return $msg;
            }
        }
        return null;
    }

    /**
     * Find a record by primary key.
     *
     * @return $this
     */
    /**
     * Find a single record by primary key.
     * Can be called statically: User::findById(1)
     *
     * @return static|null The found instance or null
     */
    public static function findById(int|string $id, ?array $include = null): ?static
    {
        $instance = new static();
        $db = static::resolveDbFor($instance);

        $pkColumn = $instance->getDbColumn($instance->primaryKey);
        $sql = "SELECT * FROM {$instance->tableName} WHERE {$pkColumn} = ?";
        if ($instance->softDelete) {
            $sql .= " AND is_deleted = 0";
        }

        $row = $db->fetchOne($sql, [$id]);
        if ($row === null) {
            return null;
        }

        $model = new static($db);
        $model->fill($row);
        $model->_exists = true;
        if ($include !== null) {
            $instances = [$model];
            static::eagerLoad($instances, $include, $db);
        }
        return $model;
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
    public function load(?string $filter = null, array $params = [], ?array $include = null): bool
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName}";

        if ($filter === null) {
            // No args — use the primary key value already set on this instance
            $pkValue = $this->getPrimaryKeyValue();
            if ($pkValue === null) {
                return false;
            }
            $pkColumn = $this->getDbColumn($this->primaryKey);
            $sql .= " WHERE {$pkColumn} = ?";
            $params = [$pkValue];
        } else {
            // Filter string — WHERE clause without "WHERE"
            $sql .= " WHERE {$filter}";
        }

        if ($this->softDelete) {
            $sql .= ($filter === null ? " AND" : " AND") . " is_deleted = 0";
        }

        $result = $this->selectOne($sql, $params, $include);
        if ($result === null) {
            return false;
        }

        // Copy the result's state directly — no double-fill.
        // selectOne() already ran fill() on $result with the raw DB data,
        // which built the correct fieldMapping. We inherit that mapping
        // and copy all properties.
        $this->fieldMapping = array_merge($this->fieldMapping, $result->fieldMapping);
        $this->_dynamicProps = $result->_dynamicProps;

        // Copy declared properties from result
        foreach ($result->getModelProperties() as $prop => $value) {
            $this->$prop = $value;
        }

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
            $result = $this->_db->execute($sql, [':id' => $pkValue]);
            if ($result === false) {
                // A bound raw adapter's exec() returns false on a bad statement
                // (the facade RAISES — caught below). Roll back the started
                // transaction instead of committing an EMPTY one. v3.13.39: a
                // commit() on the false path used to commit nothing, masking
                // the failure as a clean delete.
                $this->_db->rollback();
                return false;
            }
            $this->_exists = false;
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
    /**
     * Find records.
     *
     * Polymorphic — behaviour depends on the first argument:
     *   - **scalar** (int|string): primary-key lookup, returns a single instance or null.
     *     Equivalent to {@see findById()}. This is the form used by Python/Ruby/Node.js
     *     parity (`User::find(1)`).
     *   - **array**: filter-based lookup (column => value pairs, AND-ed), returns an array
     *     of matching instances. Preserves the historical PHP behaviour for back-compat
     *     (`User::find(['name' => 'Alice'])`).
     *
     * Can be called statically: User::find(1)  /  User::find(['name' => 'Alice'])
     * Or on an instance:        $user->find(1) /  $user->find(['name' => 'Alice'])
     *
     * @param array<string, mixed>|int|string $filter PK value (scalar) or column => value pairs (array)
     * @param int $limit Max records (filter mode only)
     * @param int $offset Starting offset (filter mode only)
     * @param string|null $orderBy ORDER BY clause (filter mode only)
     * @param array<string>|null $include Relationships to eager-load
     * @return array<int, static>|static|null Array (filter mode), single instance or null (PK mode)
     */
    public static function find(array|int|string $filter = [], int $limit = 100, int $offset = 0, ?string $orderBy = null, ?array $include = null): array|static|null
    {
        // Scalar form — primary-key lookup (parity with Python/Ruby/Node.js find()).
        if (!is_array($filter)) {
            return static::findById($filter, $include);
        }

        $instance = new static();
        $db = static::resolveDbFor($instance);

        $conditions = [];
        $params = [];

        foreach ($filter as $key => $value) {
            $dbColumn = $instance->getDbColumn($key);
            $conditions[] = "{$dbColumn} = ?";
            $params[] = $value;
        }

        if ($instance->softDelete) {
            $conditions[] = "is_deleted = 0";
        }

        $sql = "SELECT * FROM {$instance->tableName}";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }

        $result = $db->fetch($sql, $params, $limit, $offset);

        $models = [];
        $rows = is_array($result) ? ($result['data'] ?? $result) : $result->records;
        foreach ($rows as $row) {
            $model = new static($db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        if ($include !== null && !empty($models)) {
            static::eagerLoad($models, $include, $db);
        }

        return $models;
    }

    /**
     * Get all records with pagination.
     *
     * @return array<int, static>
     */
    public function all(int $limit = 100, int $offset = 0, ?array $include = null, ?string $orderBy = null): array
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName}";

        if ($this->softDelete) {
            $sql .= " WHERE is_deleted = 0";
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }

        $result = $this->_db->fetch($sql, [], $limit, $offset);

        $models = [];
        foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        if ($include !== null && !empty($models)) {
            static::eagerLoad($models, $include, $this->_db);
        }

        return $models;
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
    /** @return array<string, mixed> */
    /**
     * Convert to associative array.
     *
     * @param array|null $include  Relationships to eager-load
     * @param string     $case     Key casing: 'snake' (default — matches Python/Ruby/DB columns), 'camel' (PHP property names)
     * @return array<string, mixed>
     *
     * @deprecated since 3.11.22 — default $case changed from 'camel' to 'snake' to match
     *             Python (tina4_python.orm.ORM.to_dict) and Ruby. Pass $case='camel' to keep
     *             camelCase keys.
     */
    public function toDict(?array $include = null, string $case = 'snake'): array
    {
        $modelProps = $this->getModelProperties();
        if ($case === 'snake') {
            // Map camelCase keys back to snake_case DB column names
            $result = [];
            foreach ($modelProps as $key => $value) {
                $result[$this->fieldMapping[$key] ?? $key] = $value;
            }
        } else {
            $result = $modelProps;
        }

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
                        fn(ORM $r) => $r->toDict(!empty($nested) ? $nested : null, $case),
                        $related
                    );
                } elseif ($related instanceof ORM) {
                    $result[$relName] = $related->toDict(!empty($nested) ? $nested : null, $case);
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
    /** @return array<string, mixed> */
    public function toAssoc(?array $include = null): array
    {
        return $this->toDict($include);
    }

    /**
     * Convert the model to a stdClass object.
     *
     * @return object
     */
    /** @return object */
    public function toObject(): object
    {
        return (object) $this->toDict();
    }

    /**
     * Convert the model to an indexed list of values (keys stripped).
     * Matches Python/Ruby/Node.js toArray() semantics.
     *
     * @return array<int, mixed>
     */
    /** @return array<int, mixed> */
    public function toArray(): array
    {
        return array_values($this->getModelProperties());
    }

    /**
     * Alias for toArray().
     *
     * @return array<int, mixed>
     */
    /** @return array<int, mixed> */
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
     * Check whether a record with the given primary key value exists in the database.
     */
    public function exists(int|string $pkValue): bool
    {
        $db = static::resolveDbFor($this);
        $pkColumn = $this->getDbColumn($this->primaryKey);
        $sql = "SELECT 1 FROM {$this->tableName} WHERE {$pkColumn} = ?";
        if ($this->softDelete) {
            $sql .= " AND is_deleted = 0";
        }
        return $db->fetchOne($sql, [$pkValue]) !== null;
    }

    /**
     * Get the primary key value.
     */
    public function getPrimaryKeyValue(): int|string|null
    {
        // Read directly from the property (declared or dynamic)
        return $this->{$this->primaryKey} ?? null;
    }

    /**
     * Get the DB column name for a property (applying field mapping).
     */
    public function getDbColumn(string $property): string
    {
        return $this->fieldMapping[$property] ?? $property;
    }

    /**
     * Read a foreign-key value off this model given the DB *column* name.
     *
     * A foreignKeys declaration like `['author_id' => 'Author']` names the FK
     * by its snake_case DB column. With autoMap on, fill() stores that value
     * under the camelCase property `authorId` and records
     * `fieldMapping = ['authorId' => 'author_id']`. So reading the column name
     * directly (`$this->author_id`) returns null. This reverse-maps the column
     * back to its property (via fieldMapping, falling back to snakeToCamel for
     * autoMap models) and reads the value from the declared property or the
     * dynamic-property bag — whichever holds it.
     *
     * Used by belongsTo(), belongsToMethod(), and the eager-load belongsTo /
     * hasMany-grouping branches so the documented snake_case FK form resolves.
     *
     * @param string $fkColumn The FK column name (e.g. "author_id").
     * @return mixed The FK value, or null if unset.
     */
    public function resolveFkValue(string $fkColumn): mixed
    {
        // column → property: explicit reverse mapping wins, then autoMap's
        // snakeToCamel, finally the column name itself (no mapping at all).
        $reverse = array_flip($this->fieldMapping);
        $property = $reverse[$fkColumn] ?? null;
        if ($property === null) {
            $camel = self::snakeToCamel($fkColumn);
            $property = $camel !== $fkColumn ? $camel : $fkColumn;
        }

        // Declared public property set natively by PHP — read it directly.
        if (property_exists($this, $property) && isset($this->$property)) {
            return $this->$property;
        }

        // Otherwise it's a dynamic property (undeclared extra from fill()).
        if (array_key_exists($property, $this->_dynamicProps)) {
            return $this->_dynamicProps[$property];
        }

        // Last resort: the raw column name may itself be a dynamic property
        // (e.g. autoMap disabled and no fieldMapping entry).
        if (array_key_exists($fkColumn, $this->_dynamicProps)) {
            return $this->_dynamicProps[$fkColumn];
        }
        if (property_exists($this, $fkColumn) && isset($this->$fkColumn)) {
            return $this->$fkColumn;
        }

        return null;
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
    public function select(string $sql, array $params = [], int $limit = 20, int $offset = 0, ?array $include = null): array
    {
        $this->ensureDb();
        $result = $this->_db->fetch($sql, $params, $limit, $offset);

        $models = [];
        foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        if ($include !== null && !empty($models)) {
            static::eagerLoad($models, $include, $this->_db);
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
            $instances = [$model];
            static::eagerLoad($instances, $include, $this->_db);
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
    public function where(string $filterSql, array $params = [], int $limit = 20, int $offset = 0, ?array $include = null): array
    {
        $this->ensureDb();

        $sql = "SELECT * FROM {$this->tableName} WHERE {$filterSql}";
        if ($this->softDelete) {
            $sql = "SELECT * FROM {$this->tableName} WHERE ({$filterSql}) AND is_deleted = 0";
        }

        $result = $this->_db->fetch($sql, $params, $limit, $offset);

        $models = [];
        foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
            $model = new static($this->_db);
            $model->fill($row);
            $model->_exists = true;
            $models[] = $model;
        }

        if ($include !== null && !empty($models)) {
            static::eagerLoad($models, $include, $this->_db);
        }

        return $models;
    }

    /**
     * Find a record by primary key or throw an exception.
     * Maps to Python: find_or_fail(pk_value)
     *
     * @throws \RuntimeException If the record is not found
     */
    public static function findOrFail(int|string $id): static
    {
        $model = static::findById($id);

        if ($model === null) {
            $instance = new static();
            throw new \RuntimeException("Record not found in {$instance->tableName} with {$instance->primaryKey} = {$id}");
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

        // v3.13.39: wrap exec()+commit() in a started transaction. Previously
        // commit() was called with NO startTransaction() — committing whatever
        // ambient/implicit transaction happened to be open (or nothing at all).
        $this->_db->startTransaction();
        try {
            $result = $this->_db->execute($sql, [':id' => $pkValue]);
            if ($result === false) {
                $this->_db->rollback();
                return false;
            }
            $this->_exists = false;
            $this->_db->commit();
        } catch (\Exception $e) {
            $this->_db->rollback();
            throw $e;
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

        // v3.13.39: wrap exec()+commit() in a started transaction. Previously
        // commit() was called with NO startTransaction() — committing whatever
        // ambient/implicit transaction happened to be open (or nothing at all).
        $this->_db->startTransaction();
        try {
            $result = $this->_db->execute($sql, [':id' => $pkValue]);
            if ($result === false) {
                $this->_db->rollback();
                return false;
            }
            $this->_exists = true;
            $this->is_deleted = 0;
            $this->_db->commit();
        } catch (\Exception $e) {
            $this->_db->rollback();
            throw $e;
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
        $result = $this->_db->fetch($sql, $params, $limit, $offset);

        $models = [];
        foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
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
    /** @return array<int, string> */
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
    /**
     * Register a reusable query scope on the class.
     *
     * Usage:
     *   (new User())->scope("active", "active = ?", [1]);
     *   $users = User::active();           // calls where("active = ?", [1])
     *   $users = User::active(10, 5);      // with limit/offset
     *
     * @param string $name      Scope name — becomes a static method on the class
     * @param string $filterSql WHERE clause
     * @param array  $params    Bind parameters
     */
    public function scope(string $name, string $filterSql, array $params = []): void
    {
        static::$_scopes[$name] = ['filter' => $filterSql, 'params' => $params];
    }

    /** @var array<string, array{filter: string, params: array}> */
    protected static array $_scopes = [];

    /**
     * Magic static call handler for registered scopes.
     */
    public static function __callStatic(string $name, array $arguments): array
    {
        if (isset(static::$_scopes[$name])) {
            $scope = static::$_scopes[$name];
            $limit = $arguments[0] ?? 20;
            $offset = $arguments[1] ?? 0;
            return (new static())->where($scope['filter'], $scope['params'], $limit, $offset);
        }
        throw new \BadMethodCallException("Scope '{$name}' is not defined on " . static::class);
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
            $foreignKey = self::defaultForeignKey($this);
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
            $foreignKey = self::defaultForeignKey($this);
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
            $foreignKey = self::defaultForeignKey($related);
        }

        $fkValue = $this->resolveFkValue($foreignKey);
        if ($fkValue === null) {
            return null;
        }

        return $relatedClass::findById($fkValue);
    }

    /**
     * Create the table for this model from its declared column definitions.
     * Maps to Python: create_table()
     *
     * Generates engine-aware CREATE TABLE DDL from the model's typed public
     * properties. PHP models declare columns as typed public properties (e.g.
     * `public int $id = 0; public string $name = ''; public bool $active = false;`)
     * rather than Field objects, so types are derived from the property's
     * declared PHP type:
     *
     *   int        → INTEGER
     *   float      → REAL
     *   bool       → engine-aware boolean (see below)
     *   \DateTime / \DateTimeInterface, or a name ending in _at / *date* / *time*
     *              → engine-aware timestamp (see below)
     *   string (default) → VARCHAR(255)
     *
     * Engine-aware boolean type (mirrors tina4-python v3.13.16):
     *   postgresql / mysql → BOOLEAN   (native; psycopg/pgsql binds PHP bool as BOOLEAN)
     *   mssql              → BIT
     *   sqlite / firebird / other → INTEGER
     *
     * Engine-aware datetime type:
     *   postgresql / firebird → TIMESTAMP  (neither has a DATETIME type)
     *   else                  → DATETIME
     *
     * The primary key column gets `PRIMARY KEY AUTOINCREMENT`, which
     * SqlTranslation::autoIncrementSyntax() rewrites per engine
     * (SERIAL on PG, AUTO_INCREMENT on MySQL, IDENTITY(1,1) on MSSQL, etc.).
     *
     * Returns false (not true) when the CREATE actually fails — the adapter
     * swallows the driver error into error() and returns false, so claiming
     * success here would be a silent, misleading pass on PostgreSQL.
     *
     * @return bool True on success, false when the DDL failed.
     */
    public function createTable(): bool
    {
        $this->ensureDb();

        if ($this->_db->tableExists($this->tableName)) {
            return true;
        }

        $dialect = $this->detectDialect();

        // Engine-aware boolean column type. SQLite has no native bool and
        // Firebird's BOOLEAN round-trip is uneven across versions, so both
        // stay on INTEGER; PG/MySQL get native BOOLEAN; MSSQL gets BIT.
        $boolSql = match ($dialect) {
            'postgresql', 'mysql' => 'BOOLEAN',
            'mssql' => 'BIT',
            default => 'INTEGER',
        };

        // PostgreSQL and Firebird have no DATETIME type — emit TIMESTAMP.
        $datetimeSql = in_array($dialect, ['postgresql', 'firebird'], true)
            ? 'TIMESTAMP'
            : 'DATETIME';

        // Engine-aware JSON column type (parity with Python's JSONField DDL).
        // PostgreSQL gets native JSONB (indexable, canonical); MySQL native
        // JSON; MSSQL stores JSON as NVARCHAR(MAX) (its json1 functions read
        // that); Firebird has no TEXT type so it uses a text BLOB; SQLite and
        // everything else store the json text in TEXT (queryable via json1).
        $jsonSql = match ($dialect) {
            'postgresql' => 'JSONB',
            'mysql'      => 'JSON',
            'mssql'      => 'NVARCHAR(MAX)',
            'firebird'   => 'BLOB SUB_TYPE TEXT',
            default      => 'TEXT',
        };

        $pkProperty = $this->primaryKey;
        $colDefs = [];

        foreach ($this->getColumnDefinitions() as $name => $def) {
            $type = $def['type'];
            $colName = $this->resolveDbColumn($name);
            $isPk = ($name === $pkProperty);

            $sqlType = match ($type) {
                'int'      => 'INTEGER',
                'float'    => 'REAL',
                'bool'     => $boolSql,
                'datetime' => $datetimeSql,
                'json'     => $jsonSql,
                default    => 'VARCHAR(255)',
            };

            if ($isPk) {
                // v3.13.39: honour the DECLARED primary-key type. An integer PK
                // is auto-increment (INTEGER PRIMARY KEY AUTOINCREMENT, then
                // translated per engine below). A string/uuid/natural PK is
                // caller-supplied — it must NOT be forced to an autoincrement
                // INTEGER column (which silently coerces "GC-100" to 0). It
                // gets its real type + PRIMARY KEY and no AUTOINCREMENT.
                if ($type === 'int') {
                    $colDefs[] = implode(' ', [$colName, 'INTEGER', 'PRIMARY KEY', 'AUTOINCREMENT']);
                } else {
                    $colDefs[] = implode(' ', [$colName, $sqlType, 'PRIMARY KEY']);
                }
                continue;
            }

            $parts = [$colName, $sqlType];

            // Engine-aware boolean DEFAULT: a native BOOLEAN column (PG/MySQL)
            // needs TRUE/FALSE; an INTEGER- or BIT-backed bool (SQLite, Firebird,
            // MSSQL) needs 1/0. `DEFAULT 0` on a PG BOOLEAN raises
            // "default expression is of type integer".
            if ($type === 'bool' && $def['hasDefault'] && is_bool($def['default'])) {
                if ($boolSql === 'BOOLEAN') {
                    $parts[] = 'DEFAULT ' . ($def['default'] ? 'TRUE' : 'FALSE');
                } else {
                    $parts[] = 'DEFAULT ' . ($def['default'] ? '1' : '0');
                }
            }

            $colDefs[] = implode(' ', $parts);
        }

        // Always guarantee a primary key column even if the model declares
        // no typed properties beyond the framework defaults.
        if (empty($colDefs)) {
            $pkColumn = $this->resolveDbColumn($pkProperty);
            $colDefs[] = "{$pkColumn} INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (" . implode(', ', $colDefs) . ")";

        // Translate the generic AUTOINCREMENT to the engine's syntax
        // (SERIAL on PG, AUTO_INCREMENT on MySQL, IDENTITY(1,1) on MSSQL, …).
        $sql = SqlTranslation::autoIncrementSyntax($sql, $dialect);

        // execute() now RAISES on a failed CREATE (parity with the Python
        // master, whose create_table does try { execute; commit } catch (e)
        // { Log.error(...); return false }). createTable() keeps its bool
        // contract by catching that and returning false. We still verify the
        // table actually exists afterwards — the only engine-agnostic proof
        // the CREATE took effect — so a silently no-op DDL is caught too.
        try {
            $this->_db->execute($sql);
            $this->_db->commit();
        } catch (\Throwable $e) {
            Log::error("createTable failed for {$this->tableName}: " . $e->getMessage(), ['sql' => $sql]);
            return false;
        }

        if (!$this->_db->tableExists($this->tableName)) {
            Log::error("createTable failed for {$this->tableName}: " . ($this->_db->error() ?? 'unknown error'), ['sql' => $sql]);
            return false;
        }

        return true;
    }

    /**
     * Public field-definition map for the seeder (FakeData::seedOrm / seedModels).
     *
     * Mirrors Python's `orm_class._fields`: returns one entry per seedable
     * column, keyed by the *property* name, in the shape FakeData::forField()
     * understands plus the metadata the seeder needs for ordering and FK-value
     * resolution:
     *
     *   [
     *     'authorId' => [
     *       'type'           => 'int',          // logical type (int|float|bool|datetime|string)
     *       'column'         => 'author_id',    // resolved DB column name
     *       'primary_key'    => false,
     *       'auto_increment' => false,
     *       'foreign_key'    => ['model' => 'Author', 'related_name' => 'posts'],  // or null
     *     ],
     *     ...
     *   ]
     *
     * The primary key is flagged `primary_key => true`. An integer PK named
     * like an auto-increment id is flagged `auto_increment => true` so the
     * seeder skips it (parity with Python's auto-increment PK skip).
     *
     * @return array<string, array{type: string, column: string, primary_key: bool, auto_increment: bool, foreign_key: array{model: string, related_name: ?string}|null}>
     */
    public function getFieldDefinitions(): array
    {
        // Normalise the FK declarations to a column => ['model','related_name'] map.
        $fkByColumn = [];
        foreach ($this->foreignKeys as $fkColumn => $config) {
            if (is_string($config)) {
                $fkByColumn[$fkColumn] = ['model' => $config, 'related_name' => null];
            } elseif (is_array($config)) {
                $fkByColumn[$fkColumn] = [
                    'model' => $config['model'] ?? '',
                    'related_name' => $config['related_name'] ?? null,
                ];
            }
        }

        $defs = [];
        foreach ($this->getColumnDefinitions() as $name => $def) {
            $column = $this->resolveDbColumn($name);
            $isPk = ($name === $this->primaryKey) || ($column === $this->primaryKey);
            // Auto-increment heuristic: an integer primary key is treated as
            // database-generated (skipped by the seeder), matching Python's
            // auto_increment PK skip and createTable()'s AUTOINCREMENT PK.
            $autoIncrement = $isPk && $def['type'] === 'int';

            $foreignKey = $fkByColumn[$column] ?? $fkByColumn[$name] ?? null;

            $defs[$name] = [
                'type'           => $def['type'],
                'column'         => $column,
                'primary_key'    => $isPk,
                'auto_increment' => $autoIncrement,
                'foreign_key'    => $foreignKey,
            ];
        }

        return $defs;
    }

    /**
     * Resolve a property name to its DB column name, applying the same
     * autoMap (camelCase → snake_case) + fieldMapping path that getDbData()
     * uses for INSERT/UPDATE. This guarantees the DDL column names match the
     * column names the ORM later emits on save() — otherwise createTable()
     * would emit `createdat` while INSERT emits `created_at`.
     */
    private function resolveDbColumn(string $name): string
    {
        if ($this->autoMap && !isset($this->fieldMapping[$name])) {
            $snaked = self::camelToSnake($name);
            if ($snaked !== $name) {
                $this->fieldMapping[$name] = $snaked;
            }
        }
        return $this->getDbColumn($name);
    }

    /**
     * Map this model's declared typed public properties to column definitions.
     * Each entry is ['type' => logical type, 'hasDefault' => bool,
     * 'default' => mixed], where logical type is one of
     * 'int' | 'float' | 'bool' | 'datetime' | 'string'. The primary key is
     * always included even when its property is not declared on the subclass.
     *
     * @return array<string, array{type: string, hasDefault: bool, default: mixed}>
     */
    private function getColumnDefinitions(): array
    {
        static $frameworkProps = [
            'tableName', 'primaryKey', 'fieldMapping', 'autoMap',
            'softDelete', 'autoCrud', 'hasOne', 'hasMany', 'belongsTo',
            'foreignKeys', 'tableFilter',
            // $_db is a connection selector, never a column — exclude it even
            // when a subclass redeclares it (e.g. public $_db = 'analytics';).
            '_db',
        ];

        $columns = [];
        $ref = new \ReflectionObject($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            if (in_array($name, $frameworkProps, true)) {
                continue;
            }
            // Skip ORM base-class framework properties; only map subclass columns.
            if ($prop->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $columns[$name] = [
                'type'       => $this->logicalTypeFor($prop, $name),
                'hasDefault' => $prop->hasDefaultValue(),
                'default'    => $prop->hasDefaultValue() ? $prop->getDefaultValue() : null,
            ];
        }

        // The primary key must always be a column even if undeclared.
        if (!isset($columns[$this->primaryKey])) {
            $columns = [$this->primaryKey => ['type' => 'int', 'hasDefault' => false, 'default' => null]] + $columns;
        }

        return $columns;
    }

    /**
     * The set of property names that map to a JSON document column (an
     * `array`-typed property — the PHP equivalent of Python's JSONField).
     * Returned as name => true for O(1) membership checks on the save/hydrate
     * hot paths. Cached per model class: the type map is fixed by the class's
     * declared properties, so it is computed once and reused.
     *
     * @return array<string, true>
     */
    private function jsonColumns(): array
    {
        static $cache = [];
        $class = static::class;
        if (!isset($cache[$class])) {
            $names = [];
            foreach ($this->getColumnDefinitions() as $name => $def) {
                if ($def['type'] === 'json') {
                    $names[$name] = true;
                }
            }
            $cache[$class] = $names;
        }
        return $cache[$class];
    }

    /**
     * Derive a logical column type from a property's declared PHP type,
     * falling back to a name heuristic for datetime columns.
     */
    private function logicalTypeFor(\ReflectionProperty $prop, string $name): string
    {
        $phpType = $prop->getType();
        $typeName = ($phpType instanceof \ReflectionNamedType) ? $phpType->getName() : null;

        // Normalise camelCase to snake_case so the datetime name heuristic
        // matches both `created_at` and `createdAt`.
        $snaked = self::camelToSnake($name);

        return match (true) {
            $typeName === 'int'   => 'int',
            $typeName === 'float' => 'float',
            $typeName === 'bool'  => 'bool',
            // An `array`-typed property is a JSON document column. This is the
            // idiomatic PHP equivalent of Python's JSONField: the dict/list is
            // json_encode()d on write and json_decode()d back on read. Nullable
            // (`?array`) still reports 'array' here, so both forms map to JSON.
            $typeName === 'array' => 'json',
            $typeName === \DateTime::class
                || $typeName === \DateTimeImmutable::class
                || $typeName === \DateTimeInterface::class => 'datetime',
            (bool) preg_match('/(_at$|date|time)/i', $snaked) => 'datetime',
            default => 'string',
        };
    }

    /**
     * Resolve the SQL dialect for the current connection, unwrapping the
     * Database / CachedDatabase facades to reach the concrete adapter.
     * Returns one of: postgresql, mysql, mssql, firebird, sqlite, odbc,
     * mongodb, sqlite (default).
     */
    private function detectDialect(): string
    {
        $adapter = $this->_db;
        // Unwrap Database facade and CachedDatabase wrapper.
        while (method_exists($adapter, 'getAdapter')) {
            $next = $adapter->getAdapter();
            if ($next === $adapter || !$next instanceof DatabaseAdapter) {
                break;
            }
            $adapter = $next;
        }

        return match (true) {
            $adapter instanceof \Tina4\Database\PostgresAdapter => 'postgresql',
            $adapter instanceof \Tina4\Database\MySQLAdapter    => 'mysql',
            $adapter instanceof \Tina4\Database\MSSQLAdapter    => 'mssql',
            $adapter instanceof \Tina4\Database\FirebirdAdapter => 'firebird',
            $adapter instanceof \Tina4\Database\MongoDBAdapter  => 'mongodb',
            $adapter instanceof \Tina4\Database\ODBCAdapter     => 'odbc',
            default => 'sqlite',
        };
    }

    /**
     * Run a raw SQL query and cache the results for `$ttl` seconds.
     * Results are tagged with the model class name so clearCache() invalidates them all.
     * Maps to Python: cached(sql, params, ttl, limit, offset)
     *
     * @param string     $sql    Raw SELECT SQL
     * @param array      $params Bound parameters
     * @param int        $ttl    Cache lifetime in seconds (default 60)
     * @param int        $limit  Max results
     * @param int        $offset Starting offset
     * @param array|null $include Relationship names to eager-load
     * @return array<int, static>
     */
    public function cached(string $sql, array $params = [], int $ttl = 60, int $limit = 20, int $offset = 0, ?array $include = null): array
    {
        $cacheKey = static::class . ':' . SqlTranslation::queryKey($sql, $params) . ":{$limit}:{$offset}";
        $hit = SqlTranslation::cacheGet($cacheKey);
        if ($hit !== null) {
            return $hit;
        }

        $result = $this->select($sql, $params, $limit, $offset, $include);
        SqlTranslation::cacheSet($cacheKey, $result, $ttl);
        return $result;
    }

    /**
     * Clear all cached query results for this model class.
     * Maps to Python: clear_cache()
     */
    public function clearCache(): void
    {
        // SqlTranslation cache doesn't support tag-based clearing, so we clear all.
        // For per-model isolation, prefix keys are used but a full clear is the safe option.
        SqlTranslation::cacheClear();
    }

    /**
     * Get all data (for internal use by AutoCrud etc.).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->getModelProperties();
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

        // Drop the primary key from the payload when it's unset (null or 0) so
        // the database's auto-increment / sequence can assign a value. Without
        // this, getDbData() serialises the declared `public int $id = 0` default
        // and produces `INSERT INTO t (id, ...) VALUES (0, ...)` — SQLite stores
        // id=0 literally, auto-increment never fires, lastInsertId() returns 0,
        // and the property never syncs back. (See issue #102.)
        $pkColumn = $this->getDbColumn($this->primaryKey);
        if (array_key_exists($pkColumn, $data) && ($data[$pkColumn] === null || $data[$pkColumn] === 0 || $data[$pkColumn] === '')) {
            unset($data[$pkColumn]);
        }

        if (empty($data)) {
            return false;
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));

        $params = [];
        foreach ($data as $col => $value) {
            $params[":{$col}"] = $value;
        }

        // Whether the caller supplied the PK. A DB-side default (SERIAL, or a
        // `uuid ... DEFAULT gen_random_uuid()`) means the value is generated by
        // the engine and must be read back; a caller-set natural key must NOT
        // be overwritten. We dropped null/0/'' above, so "absent from payload"
        // is exactly "unset" — read it back; otherwise keep what the caller set.
        $pkUnset = !array_key_exists($pkColumn, $data);

        $sql = "INSERT INTO {$this->tableName} ({$columns}) VALUES ({$placeholders})";

        // PostgreSQL fix (issue #256): a plain INSERT emits no value the driver
        // can hand back, and `lastval()` only works for SERIAL/sequence PKs — on
        // a UUID/text/ULID PK it gives nothing (or a stale wrong number), so
        // lastInsertId() stayed 0 and the generated id was never surfaced. Add
        // RETURNING <pk> so the engine returns the ACTUAL written PK (a UUID
        // string stays a string; a SERIAL integer stays an integer). The other
        // engines keep the lastval()/lastInsertId() path unchanged.
        $usingReturning = $this->detectDialect() === 'postgresql' && $pkUnset;
        if ($usingReturning) {
            $sql .= " RETURNING {$pkColumn}";
            $execResult = $this->_db->execute($sql, $params);
            // execute() returns a DatabaseResult for a RETURNING statement and,
            // for the Database facade, also records the PK on lastInsertId().
            $result = $execResult !== false;
        } else {
            $result = $this->_db->exec($sql, $params);
        }

        if ($result) {
            $this->_exists = true;
            // Adopt the engine-assigned id only when the caller did not set the
            // PK. RETURNING (PG) and lastInsertId() (other engines) both surface
            // the written value; a UUID string round-trips as a string.
            if ($pkUnset) {
                $newId = null;
                if ($usingReturning && isset($execResult) && $execResult instanceof DatabaseResult) {
                    $records = $execResult->records;
                    if (!empty($records)) {
                        $newId = $records[0][$pkColumn] ?? reset($records[0]);
                    }
                }
                // Fall back to lastInsertId() (the RETURNING capture also lands
                // there) for non-PG engines and as a safety net.
                if ($newId === null || $newId === false) {
                    $newId = $this->_db->lastInsertId();
                }
                if ($newId !== null && $newId !== false) {
                    $this->{$this->primaryKey} = $newId;
                }
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
    /**
     * Get all model data as DB column → value pairs for insert/update.
     *
     * Reads directly from public properties (declared on the subclass)
     * and _dynamicProps (undeclared extras). Uses fieldMapping to convert
     * PHP property names to DB column names.
     */
    private function getDbData(): array
    {
        $data = [];
        $props = $this->getModelProperties();
        $jsonColumns = $this->jsonColumns();

        foreach ($props as $name => $value) {
            // Auto-generate fieldMapping for camelCase → snake_case
            if ($this->autoMap && !isset($this->fieldMapping[$name])) {
                $snaked = self::camelToSnake($name);
                if ($snaked !== $name) {
                    $this->fieldMapping[$name] = $snaked;
                }
            }
            // A JSON column serialises its dict/list to a JSON string for the
            // driver (parity with Python's JSONField.to_db). A non-serialisable
            // value throws \JsonException — save() catches it, rolls back, and
            // returns false with the cause on getError() (fail loud, no silent
            // drop). null passes through as SQL NULL; a value already a string
            // is left as-is (a caller who pre-encoded stays in control).
            if (isset($jsonColumns[$name]) && $value !== null && !is_string($value)) {
                $value = json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }
            $column = $this->getDbColumn($name);
            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * Get all user-defined model properties as name → value.
     *
     * Collects declared public properties from the subclass (not the ORM
     * base class framework props) plus any dynamic properties set via __set.
     */
    private function getModelProperties(): array
    {
        static $frameworkProps = [
            'tableName', 'primaryKey', 'fieldMapping', 'autoMap',
            'softDelete', 'autoCrud', 'hasOne', 'hasMany', 'belongsTo',
            'foreignKeys', 'tableFilter',
            // $_db is a connection selector, never a column — exclude it even
            // when a subclass redeclares it (e.g. public $_db = 'analytics';).
            '_db',
        ];

        $props = [];

        // Declared public properties on the subclass
        $ref = new \ReflectionObject($this);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) continue;
            $name = $prop->getName();
            if (in_array($name, $frameworkProps, true)) continue;
            if ($prop->getDeclaringClass()->getName() === self::class) continue;
            if (!$prop->isInitialized($this)) continue;
            $props[$name] = $this->$name;
        }

        // Dynamic properties (from joins, undeclared fields)
        foreach ($this->_dynamicProps as $name => $value) {
            if (!isset($props[$name])) {
                $props[$name] = $value;
            }
        }

        return $props;
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
     * Auto-wire belongsTo on this model and register hasMany on the referenced model
     * based on the $foreignKeys declarations.
     *
     * Called from constructor. Idempotent — skips entries already present.
     */
    private function _processForeignKeys(): void
    {
        if (empty($this->foreignKeys)) {
            return;
        }

        $declaringClass = static::class;

        foreach ($this->foreignKeys as $fkColumn => $config) {
            // Normalise config
            if (is_string($config)) {
                $referencedModel = $config;
                $relatedName = null;
            } else {
                $referencedModel = $config['model'] ?? '';
                $relatedName = $config['related_name'] ?? null;
            }

            if ($referencedModel === '') {
                continue;
            }

            // Derive belongsTo key: strip trailing _id
            $belongsKey = preg_replace('/_id$/', '', $fkColumn);

            // Add to $this->belongsTo if not already declared
            if (!isset($this->belongsTo[$belongsKey])) {
                $this->belongsTo[$belongsKey] = "{$referencedModel}.{$fkColumn}";
            }

            // Determine has-many key on the referenced model
            if ($relatedName !== null) {
                $hasManyKey = $relatedName;
            } else {
                // Default: declaring class name lowercased + 's'
                $shortName = basename(str_replace('\\', '/', $declaringClass));
                $hasManyKey = strtolower($shortName) . 's';
            }

            // Register in the cross-model FK registry so the referenced model
            // gets the has-many entry injected lazily via __get()
            $entry = ['key' => $hasManyKey, 'spec' => "{$declaringClass}.{$fkColumn}"];
            $existing = self::$_fkRegistry[$referencedModel] ?? [];
            foreach ($existing as $e) {
                if ($e['key'] === $hasManyKey && $e['spec'] === $entry['spec']) {
                    $entry = null;
                    break;
                }
            }
            if ($entry !== null) {
                self::$_fkRegistry[$referencedModel][] = $entry;
            }
        }
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
                $foreignKey = self::defaultForeignKey($this);
            }
            return $this->hasOneMethod($relatedClass, $foreignKey);
        } elseif ($type === 'hasMany') {
            if ($foreignKey === null) {
                $foreignKey = self::defaultForeignKey($this);
            }
            return $this->hasManyMethod($relatedClass, $foreignKey);
        } elseif ($type === 'belongsTo') {
            if ($foreignKey === null) {
                $foreignKey = self::defaultForeignKey($relatedClass);
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
    public static function eagerLoad(array &$instances, array $include, ?DatabaseAdapter $db = null): void
    {
        if (empty($instances)) {
            return;
        }

        if ($db === null) {
            $db = static::getDb();
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
                    $foreignKey = self::defaultForeignKey($sample);
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
                $result = $db->fetch($sql, $pkValues, count($pkValues) * 1000, 0);

                $related = [];
                foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
                    $model = new $relatedClass($db);
                    $model->fill($row);
                    $model->_exists = true;
                    $related[] = $model;
                }

                // Eager load nested
                if (!empty($nested) && !empty($related)) {
                    self::eagerLoad($related, $nested, $db);
                }

                // Group by FK — resolveFkValue() reverse-maps the FK column
                // through fieldMapping so a snake_case FK on a camelCase
                // (autoMap) property still groups correctly.
                $grouped = [];
                foreach ($related as $record) {
                    $fkVal = $record->resolveFkValue($foreignKey);
                    // Skip records whose FK is null — they cannot match any parent PK
                    // (avoids PHP 8.5 "null as array offset" deprecation)
                    if ($fkVal === null) {
                        continue;
                    }
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
                    $foreignKey = self::defaultForeignKey($relatedClass);
                }

                $fkValues = [];
                foreach ($instances as $inst) {
                    $fkVal = $inst->resolveFkValue($foreignKey);
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
                $result = $db->fetch($sql, $fkValues, count($fkValues) * 10, 0);

                $lookup = [];
                foreach (is_array($result) ? ($result['data'] ?? $result) : $result->records as $row) {
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
                    $fkVal = $inst->resolveFkValue($foreignKey);
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
        $fkValue = $this->resolveFkValue($foreignKey);
        if ($fkValue === null) {
            return null;
        }

        return $relatedClass::findById($fkValue);
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
        // Already a live adapter — nothing to do.
        if ($this->_db instanceof DatabaseAdapter) {
            return;
        }

        // String → named-connection registry (throws a clear error if missing).
        if (is_string($this->_db)) {
            $this->_db = static::resolveDbFor($this);
            return;
        }

        // null → global default → App → TINA4_DATABASE_URL.
        $this->_db = static::resolveDb();
    }
}
