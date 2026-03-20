<?php

/**
 * Tina4 - This is not a 4ramework.
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
    /** @var array<string, class-string<ORM>> Registered model classes indexed by table name */
    private array $models = [];

    public function __construct(
        private readonly DatabaseAdapter $db,
        private readonly string $prefix = '/api',
    ) {
    }

    /**
     * Register a model class for auto-CRUD.
     *
     * @param class-string<ORM> $modelClass Fully qualified class name
     * @return $this
     */
    public function register(string $modelClass): self
    {
        $instance = new $modelClass($this->db);
        $tableName = $instance->tableName;

        if ($tableName === '') {
            throw new \InvalidArgumentException("AutoCrud: Model {$modelClass} has no tableName set.");
        }

        $this->models[$tableName] = $modelClass;
        return $this;
    }

    /**
     * Discover model classes from a directory and register them.
     *
     * @param string $modelsDir Directory to scan (e.g., src/models)
     * @return array<string> List of discovered model class names
     */
    public function discover(string $modelsDir): array
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
                    $this->register($fqcn);
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

            // GET /api/{table} — list
            Router::get($basePath, $this->createListHandler($modelClass));
            $generated[] = ['method' => 'GET', 'path' => $basePath, 'table' => $tableName];

            // GET /api/{table}/{id} — get one
            Router::get($basePath . '/{id}', $this->createGetHandler($modelClass));
            $generated[] = ['method' => 'GET', 'path' => $basePath . '/{id}', 'table' => $tableName];

            // POST /api/{table} — create
            Router::post($basePath, $this->createCreateHandler($modelClass));
            $generated[] = ['method' => 'POST', 'path' => $basePath, 'table' => $tableName];

            // PUT /api/{table}/{id} — update
            Router::put($basePath . '/{id}', $this->createUpdateHandler($modelClass));
            $generated[] = ['method' => 'PUT', 'path' => $basePath . '/{id}', 'table' => $tableName];

            // DELETE /api/{table}/{id} — delete
            Router::delete($basePath . '/{id}', $this->createDeleteHandler($modelClass));
            $generated[] = ['method' => 'DELETE', 'path' => $basePath . '/{id}', 'table' => $tableName];
        }

        return $generated;
    }

    /**
     * Get all registered model classes.
     *
     * @return array<string, class-string<ORM>>
     */
    public function getModels(): array
    {
        return $this->models;
    }

    /**
     * Create a list handler with pagination, filtering, and sorting.
     */
    private function createListHandler(string $modelClass): \Closure
    {
        $db = $this->db;

        return function (Request $request, Response $response) use ($modelClass, $db): Response {
            $model = new $modelClass($db);

            $limit = (int)($request->query['limit'] ?? 10);
            $offset = (int)($request->query['offset'] ?? 0);

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
                $data = array_map(fn(ORM $m) => $m->toArray(), $models);
                return $response->json([
                    'data' => $data,
                    'total' => count($data),
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
            }

            $result = $model->all($limit, $offset);
            $data = array_map(fn(ORM $m) => $m->toArray(), $result['data']);

            return $response->json([
                'data' => $data,
                'total' => $result['total'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
            ]);
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

            if (!$model->exists()) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            return $response->json($model->toArray());
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
                return $response->json($model->toArray(), 201);
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

            if (!$model->exists()) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            $data = is_array($request->body) ? $request->body : [];
            $model->fill($data);

            if ($model->save()) {
                return $response->json($model->toArray());
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

            if (!$model->exists()) {
                return $response->json(['error' => 'Not Found'], 404);
            }

            if ($model->delete()) {
                return $response->json(['message' => 'Deleted']);
            }

            return $response->json(['error' => 'Failed to delete record', 'detail' => $db->error()], 500);
        };
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
