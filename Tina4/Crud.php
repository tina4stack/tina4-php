<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4;

use Tina4\Database\DatabaseAdapter;

/**
 * Crud (ADR-0094) — a FRONTEND over AutoCrud.
 *
 * {@see toCrud()} renders a complete server-rendered admin UI (searchable,
 * sortable, paginated table + create/edit/delete modals) for an ORM model. It
 * owns NO backend routes: the entire REST backend (GET list, GET /{id}, POST,
 * PUT, DELETE, secure-by-default) is delegated to {@see AutoCrud}, the listing
 * data access to {@see CrudData}, and every byte of HTML to {@see CrudView} —
 * from four app-overridable Frond templates under `crud/` (page, table, form,
 * modals), resolved app-first-then-framework. An app restyles the admin by
 * dropping its own `src/templates/crud/<name>.twig`, no framework fork.
 *
 * Usage:
 *   Router::get("/admin/users", function (Request $request, Response $response) {
 *       return $response->html(Crud::toCrud($request, ['model' => User::class, 'title' => 'Users']));
 *   });
 *
 * A custom `sql` only shapes the LISTING grid (a filter/join/projection); the
 * model still drives columns, the primary key, and every write path, so a
 * custom listing can never create an unauthenticated or divergent write route.
 */
class Crud
{
    /** @var array<string, bool> Tracks which "{prefix}::{table}" backends have been registered. */
    private static array $registeredTables = [];

    /**
     * Reset the per-"{prefix}::{table}" registration guard. Intended for tests
     * that clear the Router and re-register between cases.
     */
    public static function clearRegistry(): void
    {
        self::$registeredTables = [];
    }

    /**
     * Render the CRUD admin page for a model and register its AutoCrud routes.
     *
     * @param Request $request The current request.
     * @param array<string, mixed> $options Options:
     *   - `model`  (required) the ORM model CLASS name (FQCN).
     *   - `sql`    (optional) a listing SELECT; inferred from the model when omitted. Shapes only the displayed grid.
     *   - `title`  (optional) page title (default "CRUD").
     *   - `prefix` (optional) AutoCrud route prefix (default "/api").
     *   - `limit`  (optional) records per page (default 10).
     * @return string The rendered crud/page template.
     * @throws \InvalidArgumentException When no `model` is given (a model drives every write).
     */
    public static function toCrud(Request $request, array $options): string
    {
        $modelClass = self::resolveModelClass($options);
        [$sql, $title, $prefix, $limit] = self::crudOptions($options);

        $instance = new $modelClass();
        $tableName = (string)$instance->tableName;
        $pk = $instance->getDbColumn($instance->getPrimaryKeys()[0]);

        [$columns, $types] = self::modelColumns($instance);

        // Backend: delegate 100% to AutoCrud (idempotent — register once).
        self::registerBackend($modelClass, $instance, $prefix, $tableName);

        // Pagination / search / safe-sort from the query string.
        [$page, $search, $sortCol, $sortDir, $offset] = self::queryState($request, $instance, $sql, $pk, $limit);

        if ($sql !== null) {
            [$records, $total] = CrudData::fetchSqlData($sql, $search, $sortCol, $sortDir, $limit, $offset);
        } else {
            [$records, $total] = CrudData::fetchModelData($instance, $types, $search, $sortCol, $sortDir, $limit, $offset);
        }

        $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
        $apiPath = "{$prefix}/{$tableName}";
        $requestPath = (string)($request->path ?? '/');

        return CrudView::renderPage(
            $title,
            $tableName,
            $pk,
            $columns,
            $types,
            $records,
            $page,
            $totalPages,
            $total,
            $limit,
            $search,
            $sortCol,
            $sortDir,
            $apiPath,
            $requestPath
        );
    }

    /**
     * Validate the `model` option and return the ORM class name it names.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException When no usable `model` option is given.
     */
    private static function resolveModelClass(array $options): string
    {
        $modelClass = $options['model'] ?? null;
        if (!is_string($modelClass) || $modelClass === '' || !class_exists($modelClass) || !is_subclass_of($modelClass, ORM::class)) {
            throw new \InvalidArgumentException('Crud::toCrud requires a "model" option naming an ORM class.');
        }
        return $modelClass;
    }

    /**
     * Normalise the optional page options to [sql, title, prefix, limit]; an
     * absent or non-positive limit becomes 10.
     *
     * @param array<string, mixed> $options
     * @return array{0: ?string, 1: string, 2: string, 3: int}
     */
    private static function crudOptions(array $options): array
    {
        $sql = isset($options['sql']) ? (string)$options['sql'] : null;
        $title = (string)($options['title'] ?? 'CRUD');
        $prefix = (string)($options['prefix'] ?? '/api');
        $limit = (int)($options['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }
        return [$sql, $title, $prefix, $limit];
    }

    /**
     * The model's DB columns in declaration order plus their logical types
     * (used for column alignment).
     *
     * @return array{0: array<int, string>, 1: array<string, string>} [columns, types]
     */
    private static function modelColumns(ORM $instance): array
    {
        $columns = [];
        $types = [];
        foreach ($instance->getFieldDefinitions() as $definition) {
            $columns[] = $definition['column'];
            $types[$definition['column']] = $definition['type'];
        }
        return [$columns, $types];
    }

    /**
     * Resolve the grid's pagination, search term and safe sort (ADR-0069) from
     * the query string.
     *
     * @return array{0: int, 1: string, 2: string, 3: string, 4: int} [page, search, sortCol, sortDir, offset]
     */
    private static function queryState(Request $request, ORM $instance, ?string $sql, string $pk, int $limit): array
    {
        $query = is_array($request->query) ? $request->query : [];
        $page = max((int)($query['page'] ?? 1), 1);
        $search = isset($query['search']) ? trim((string)$query['search']) : '';
        $sortCol = CrudData::crudSortColumn($instance, $sql, isset($query['sort']) ? (string)$query['sort'] : null, $pk);
        $sortDir = (($query['sort_dir'] ?? '') === 'desc') ? 'desc' : 'asc';
        $offset = ($page - 1) * $limit;
        return [$page, $search, $sortCol, $sortDir, $offset];
    }

    /**
     * Render an HTML table fragment from an array of record arrays via
     * crud/table.twig. Inline-editable (contenteditable cells + Save/Delete
     * buttons wired through the template's delegated listener).
     *
     * @param array<int, array<string, mixed>>|null $records
     */
    public static function generateTable(?array $records, string $tableName = 'data', string $primaryKey = 'id', bool $editable = true): string
    {
        return CrudView::table($records, $tableName, $primaryKey, $editable);
    }

    /**
     * Render an HTML form from a field-definition array via crud/form.twig.
     *
     * @param array<int, array<string, mixed>>|null $fields Each: name, type, label, value, required, options.
     */
    public static function generateForm(?array $fields, string $action = '/', string $method = 'POST', string $tableName = 'data'): string
    {
        return CrudView::form($fields, $action, $method, $tableName);
    }

    /**
     * Delegate the ENTIRE backend to AutoCrud. Idempotent per "{prefix}::{table}".
     * If the app already registered the model's routes (e.g. a scaffolded admin
     * route that opened writes with `public: true`), skip — re-registering here
     * would reset that public flag back to secure.
     */
    private static function registerBackend(string $modelClass, ORM $instance, string $prefix, string $tableName): void
    {
        $key = "{$prefix}::{$tableName}";
        if (isset(self::$registeredTables[$key])) {
            return;
        }

        // Routes already present means the app registered them itself — leave
        // them (preserving any public-writes flag) and only mark us done.
        if (Router::match('POST', "{$prefix}/{$tableName}") !== null) {
            self::$registeredTables[$key] = true;
            return;
        }

        $autoCrud = new AutoCrud(self::resolveDb($instance), $prefix);
        $autoCrud->register($modelClass);   // secure by default
        $autoCrud->generateRoutes();
        self::$registeredTables[$key] = true;
    }

    /**
     * The database adapter backing the model (its own, else the bound default).
     */
    private static function resolveDb(ORM $instance): DatabaseAdapter
    {
        return $instance->getDb() ?? ORM::database();
    }
}
