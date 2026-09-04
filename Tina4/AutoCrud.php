<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;

/**
 * Auto-CRUD — discovers ORM models and auto-generates REST endpoints.
 *
 * Generated endpoints:
 *   GET    /api/{table}        — list with pagination, filtering, sorting
 *   GET    /api/{table}/{id}   — get single record
 *   POST   /api/{table}        — create record
 *   PUT    /api/{table}/{id}   — update record
 *   DELETE /api/{table}/{id}   — delete record
 */
class AutoCrud
{
    /**
     * PAGE-DEC-01: the maximum per-page size the list handler will honour, no
     * matter what a caller asks for via ?limit=/?per_page=. 100 is not an
     * arbitrary pick - it is the SAME row cap ORM::all()/db->fetch() already
     * default to, and the number Node's AutoCrud shares via its own
     * DEFAULT_ROW_CAP constant. Without this a client could request the whole
     * table in one query (?limit=1000000).
     */
    private const MAX_PER_PAGE = 100;

    /** @var array<string, class-string<ORM>> Registered model classes indexed by table name */
    private array $models = [];
    /** @var array<string, bool> tableName -> public-writes flag (default secure) */
    private array $public = [];

    public function __construct(
        private readonly DatabaseAdapter $db,
        private readonly string $prefix = '/api',
    ) {
    }

    /**
     * Register a model class for auto-CRUD.
     *
     * @param class-string<ORM> $modelClass Fully qualified class name
     * @param bool $public If true, write routes (POST/PUT/DELETE) are OPEN (->noAuth()).
     *                     Default false keeps them secure-by-default (token required).
     * @return $this
     */
    public function register(string $modelClass, bool $public = false): self
    {
        $instance = new $modelClass($this->db);
        $tableName = $instance->tableName;

        if ($tableName === '') {
            throw new \InvalidArgumentException("AutoCrud: Model {$modelClass} has no tableName set.");
        }

        $this->models[$tableName] = $modelClass;
        $this->public[$tableName] = $public;
        return $this;
    }

    /**
     * Discover model classes from a directory and register them.
     *
     * @param string $modelsDir Directory to scan (e.g., src/models)
     * @return array<string> List of discovered model class names
     */
    public function discover(string $modelsDir, bool $public = false): array
    {
        $discovered = [];

        if (!is_dir($modelsDir)) {
            return $discovered;
        }

        $files = glob($modelsDir . '/*.php');
        if ($files === false) {
            return $discovered;
        }

        foreach ($files as $file) {
            require_once $file;

            $className = pathinfo($file, PATHINFO_FILENAME);

            // Check common namespaces
            foreach ([$className, "\\{$className}", "App\\{$className}"] as $fqcn) {
                if (class_exists($fqcn) && is_subclass_of($fqcn, ORM::class)) {
                    $this->register($fqcn, $public);
                    $discovered[] = $fqcn;
                    break;
                }
            }
        }

        return $discovered;
    }

    /**
     * Generate and register all CRUD routes for registered models.
     *
     * @return array<int, array{method: string, path: string, table: string}>
     */
    public function generateRoutes(): array
    {
        $generated = [];

        foreach ($this->models as $tableName => $modelClass) {
            $basePath = $this->prefix . '/' . $tableName;
            $prettyName = str_replace('_', ' ', ucwords($tableName, '_'));
            $example = $this->buildExample($modelClass);
            $isPublic = $this->public[$tableName] ?? false;

            // GET /api/{table} — list. 'model' tags the route so Swagger builds a
            // components.schema from the ORM fields and $refs it (modelList -> array).
            Router::get($basePath, $this->createListHandler($modelClass))
                ->swagger(['summary' => "List all {$prettyName}", 'tags' => [$tableName], 'model' => $modelClass, 'modelList' => true]);
            $generated[] = ['method' => 'GET', 'path' => $basePath, 'table' => $tableName];

            // GET /api/{table}/{id} — get one
            Router::get($basePath . '/{id}', $this->createGetHandler($modelClass))
                ->swagger(['summary' => "Get {$prettyName} by ID", 'tags' => [$tableName], 'model' => $modelClass]);
            $generated[] = ['method' => 'GET', 'path' => $basePath . '/{id}', 'table' => $tableName];

            // POST /api/{table} — create (secure-by-default; ->noAuth() only when opted public)
            $postRoute = Router::post($basePath, $this->createCreateHandler($modelClass));
            if ($isPublic) { $postRoute->noAuth(); }
            $postRoute->swagger(['summary' => "Create {$prettyName}", 'tags' => [$tableName], 'example' => $example, 'model' => $modelClass]);
            $generated[] = ['method' => 'POST', 'path' => $basePath, 'table' => $tableName];

            // PUT /api/{table}/{id} — update
            $putRoute = Router::put($basePath . '/{id}', $this->createUpdateHandler($modelClass));
            if ($isPublic) { $putRoute->noAuth(); }
            $putRoute->swagger(['summary' => "Update {$prettyName}", 'tags' => [$tableName], 'example' => $example, 'model' => $modelClass]);
            $generated[] = ['method' => 'PUT', 'path' => $basePath . '/{id}', 'table' => $tableName];

            // DELETE /api/{table}/{id} — delete
            $deleteRoute = Router::delete($basePath . '/{id}', $this->createDeleteHandler($modelClass));
            if ($isPublic) { $deleteRoute->noAuth(); }
            $deleteRoute->swagger(['summary' => "Delete {$prettyName}", 'tags' => [$tableName]]);
            $generated[] = ['method' => 'DELETE', 'path' => $basePath . '/{id}', 'table' => $tableName];
        }

        return $generated;
    }

    /**
     * Get all registered model classes.
     *
     * @return array<string, class-string<ORM>>
     */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * Clear all registered models (useful for testing).
     */
    public function clear(): void
    {
        $this->models = [];
    }

    /**
     * Create a list handler with pagination, filtering, and sorting.
     */
    private function createListHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $model = new $modelClass($db);

            // Accept limit/offset (canonical) or per_page/page (aliases)
            $limit  = (int)($request->query['limit'] ?? $request->query['per_page'] ?? 10);
            // PAGE-DEC-01: cap an oversized ?limit=/?per_page= BEFORE it is used to
            // derive $offset below, so the offset lines up with the size actually
            // used (a client can no longer request the whole table in one query).
            $limit  = min($limit, self::MAX_PER_PAGE);
            $page   = (int)($request->query['page'] ?? 1);
            // PAGE-DEC-01 (already correct here): a page <= 1 forces offset 0 - the
            // reference behaviour Python/Ruby/Node were made to match.
            $offset = (int)($request->query['offset'] ?? ($page > 1 ? ($page - 1) * $limit : 0));

            // Build filter from query params
            $filter = [];
            if (isset($request->query['filter']) && is_array($request->query['filter'])) {
                $filter = $request->query['filter'];
            }

            // Build order by from sort param
            $orderBy = null;
            if (isset($request->query['sort'])) {
                $orderBy = $this->parseSortParam($request->query['sort']);
            }

            if (!empty($filter)) {
                $models = $model->find($filter, $limit, $offset, $orderBy);
            } else {
                $models = $model->all($limit, $offset);
            }

            // Dogfood ADR-0064: find()/all() return a ModelCollection that
            // already carries the TRUE total for the filter (the fetch COUNT
            // probe, ignoring limit/offset, respecting soft-delete) and emits the
            // exact seven-key envelope of DatabaseResult::toPaginate() (ADR-0043)
            // - records, total, page, per_page, total_pages, limit, offset. No
            // second COUNT query, no hand-built envelope, no page-2 mismatch.
            return $response->json($models->toPaginate());
        };
    }

    /**
     * Create a get-one handler.
     */
    private function createGetHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $model = new $modelClass($db);
            $model->load($request->params['id']);

            if (!$model->exists($request->params['id'])) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            return $response->json($model->toDict());
        };
    }

    /**
     * Create a create handler.
     */
    private function createCreateHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $rawData = is_array($request->body) ? $request->body : [];
            // CRUD-MASS-ASSIGNMENT: allow-list before the body ever reaches
            // the model (guards is_deleted + strips the PK - see the helper).
            $probe = new $modelClass($db);
            $data = $this->allowListedData($probe, $rawData, true);

            $model = new $modelClass($db, $data);

            // CRUD-VALIDATION-STATUS (CRUD-DEC-01): a consistent 422 with the
            // FIELD errors on invalid input - checked BEFORE save() so an
            // invalid model never reaches the driver, and the error source
            // is the model's OWN validate(), never the adapter's bare
            // error() this handler used to read regardless of whether the
            // DB was ever touched.
            $errors = $model->validate();
            if (!empty($errors)) {
                return $response->json(['error' => 'Validation failed', 'detail' => $errors], 422);
            }

            if ($model->save()) {
                return $response->json($model->toDict(), 201);
            }

            // A genuine driver failure (NOT NULL, duplicate key, missing
            // table, ...) - validate() already proved the model itself is
            // valid, so this really is a save()/DB problem. getError() is
            // save()'s OWN recorded cause (it prefers the real adapter error
            // over a stale/unrelated one), not the bare adapter accessor.
            return $response->json(['error' => 'Failed to create record', 'detail' => $model->getError()], 500);
        };
    }

    /**
     * Create an update handler.
     */
    private function createUpdateHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $model = new $modelClass($db);
            $model->load($request->params['id']);

            if (!$model->exists($request->params['id'])) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            $rawData = is_array($request->body) ? $request->body : [];
            // CRUD-MASS-ASSIGNMENT: allow-list - the row is addressed by the
            // URL {id}, never by the body (see the helper).
            $data = $this->allowListedData($model, $rawData, false);
            $model->fill($data);

            // CRUD-VALIDATION-STATUS / CRUD-PUT-NOVALIDATE: validated
            // explicitly (same as create) - 422 with field errors, never a
            // 500. Because load() ran first, an untouched required field
            // keeps the value already in the DB (it was valid when
            // written), so this is naturally a partial-update check: only
            // what the body actually changed is freshly validated.
            $errors = $model->validate();
            if (!empty($errors)) {
                return $response->json(['error' => 'Validation failed', 'detail' => $errors], 422);
            }

            if ($model->save()) {
                return $response->json($model->toDict());
            }

            return $response->json(['error' => 'Failed to update record', 'detail' => $model->getError()], 500);
        };
    }

    /**
     * Guard a write body's DANGEROUS keys before it reaches fill()/the
     * constructor (CRUD-MASS-ASSIGNMENT).
     *
     * PHP models may declare columns as typed properties OR leave them
     * fully dynamic - {@see ORM::getModelProperties()} explicitly merges in
     * `__set()`-captured dynamic properties by design, and AutoCrudV3Test's
     * own `CrudItem` fixture declares NO typed properties at all, relying
     * entirely on dynamic assignment. So there is no closed "known columns"
     * set to allow-list against without locking a dynamic-property model's
     * create/update out of every column (measured: a strict allow-list on
     * `getFieldDefinitions()` alone turns `testCreateItem` red - `name`
     * never reaches the row, NOT NULL fails). This is a DENY-list of the two
     * genuinely dangerous keys instead: `is_deleted` is never
     * client-writable (soft-delete is mutated only by delete()/restore());
     * the primary key is never taken from the body - insert() only drops a
     * FALSY client PK (a truthy one can flip save() onto its update()
     * branch, silently overwriting an unrelated existing row), and on a PUT
     * a body PK would move update()'s own WHERE clause off the
     * URL-addressed row (fill() assigns properties before save()/pkWhere()
     * run) - so it is stripped on create AND update, except a genuinely
     * natural (single-column, non-auto-increment) key on CREATE, where a
     * caller-chosen key is the documented way to create a row
     * (buildExample() keeps such a key in the sample body).
     *
     * @param array<string, mixed> $data
     */
    private function allowListedData(ORM $probe, array $data, bool $isCreate): array
    {
        $pkProps = $probe->getPrimaryKeys();
        $defs = $probe->getFieldDefinitions();
        $reverseMapping = array_flip($probe->fieldMapping);

        $singlePk = count($pkProps) === 1 ? $pkProps[0] : null;
        $autoIncrement = $singlePk !== null && ($defs[$singlePk]['auto_increment'] ?? false);
        $stripPk = $isCreate ? !($singlePk !== null && !$autoIncrement) : true;

        $allowed = [];
        foreach ($data as $key => $value) {
            $propName = $reverseMapping[$key] ?? $key;
            if ($propName === 'is_deleted') {
                continue;
            }
            if ($stripPk && in_array($propName, $pkProps, true)) {
                continue;
            }
            $allowed[$key] = $value;
        }
        return $allowed;
    }

    /**
     * Create a delete handler.
     */
    private function createDeleteHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $model = new $modelClass($db);
            $model->load($request->params['id']);

            if (!$model->exists($request->params['id'])) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            if ($model->delete()) {
                return $response->json(['message' => 'Deleted']);
            }

            return $response->json(['error' => 'Failed to delete record', 'detail' => $db->error()], 500);
        };
    }

    /**
     * Build a sample request body from the ORM model's database columns.
     *
     * Generates an associative array with column names as keys and example
     * values based on column types, suitable for Swagger request body examples.
     *
     * @param class-string<ORM> $modelClass
     * @return array<string, mixed>
     */
    private function buildExample(string $modelClass): array
    {
        $instance = new $modelClass($this->db);
        $tableName = $instance->tableName;
        $primaryKey = $instance->primaryKey ?? 'id';
        $example = [];

        try {
            $columns = $this->db->getColumns($tableName);
            foreach ($columns as $col) {
                $name = $col['column_name'] ?? $col['name'] ?? '';
                if ($name === '' || strcasecmp($name, $primaryKey) === 0) {
                    continue; // Skip primary key
                }
                $type = strtolower($col['data_type'] ?? $col['type'] ?? 'text');
                if (str_contains($type, 'int')) {
                    $example[$name] = 0;
                } elseif (str_contains($type, 'float') || str_contains($type, 'double') ||
                          str_contains($type, 'decimal') || str_contains($type, 'numeric') ||
                          str_contains($type, 'real')) {
                    $example[$name] = 0.0;
                } elseif (str_contains($type, 'bool')) {
                    $example[$name] = true;
                } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                    $example[$name] = '2024-01-01T00:00:00';
                } else {
                    $example[$name] = 'string';
                }
            }
        } catch (\Throwable $e) {
            // If column introspection fails, return an empty example
        }

        return $example;
    }

    /**
     * Parse a sort parameter string into an ORDER BY clause.
     *
     * Format: "-name,created_at" means "name DESC, created_at ASC"
     */
    private function parseSortParam(string $sort): string
    {
        $parts = explode(',', $sort);
        $clauses = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_starts_with($part, '-')) {
                $clauses[] = substr($part, 1) . ' DESC';
            } else {
                $clauses[] = $part . ' ASC';
            }
        }

        return implode(', ', $clauses);
    }
}
