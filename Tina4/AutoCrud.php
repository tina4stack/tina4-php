<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseResult;

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
            $page   = (int)($request->query['page'] ?? 1);
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
                // TRUE total for the filter: a COUNT over the SAME predicate
                // find() built ("<column> = ?" per key, ANDed), NEVER the
                // rows-returned count - a page-2 request must report 250, not 20.
                // Mirror find()'s column mapping so the count matches the page.
                $conditions = [];
                $params = [];
                foreach ($filter as $key => $value) {
                    $conditions[] = $model->getDbColumn($key) . ' = ?';
                    $params[] = $value;
                }
                $total = $model->count(implode(' AND ', $conditions), $params);
            } else {
                $models = $model->all($limit, $offset);
                $total = $model->count();
            }

            $records = array_map(fn(ORM $m) => $m->toDict(), $models);

            // ONE canonical envelope (ADR-0043): describe THIS page's result and
            // let DatabaseResult::toPaginate() derive the exact seven snake_case
            // keys - records, total, page, per_page, total_pages, limit, offset.
            // No second, divergent envelope is built here.
            $result = new DatabaseResult(
                records: $records,
                count: $total,
                limit: $limit,
                offset: $offset,
            );

            return $response->json($result->toPaginate());
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
            $data = is_array($request->body) ? $request->body : [];
            $model = new $modelClass($db, $data);

            if ($model->save()) {
                return $response->json($model->toDict(), 201);
            }

            return $response->json(['error' => 'Failed to create record', 'detail' => $db->error()], 500);
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

            $data = is_array($request->body) ? $request->body : [];
            $model->fill($data);

            if ($model->save()) {
                return $response->json($model->toDict());
            }

            return $response->json(['error' => 'Failed to update record', 'detail' => $db->error()], 500);
        };
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
