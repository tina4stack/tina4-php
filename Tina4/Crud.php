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
 * PUT, DELETE, secure-by-default) is delegated to {@see AutoCrud}, and the UI's
 * JavaScript talks to those routes over fetch(). The HTML comes from four
 * app-overridable Frond templates under `crud/` (page, table, form, modals),
 * resolved app-first-then-framework — the same resolution the error pages use —
 * so an app restyles the admin by dropping its own `src/templates/crud/<name>.twig`,
 * no framework fork.
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
        $modelClass = $options['model'] ?? null;
        if (!is_string($modelClass) || $modelClass === '' || !class_exists($modelClass) || !is_subclass_of($modelClass, ORM::class)) {
            throw new \InvalidArgumentException('Crud::toCrud requires a "model" option naming an ORM class.');
        }

        $sql    = isset($options['sql']) ? (string)$options['sql'] : null;
        $title  = (string)($options['title'] ?? 'CRUD');
        $prefix = (string)($options['prefix'] ?? '/api');
        $limit  = (int)($options['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }

        $instance  = new $modelClass();
        $tableName  = (string)$instance->tableName;
        $pk         = $instance->getDbColumn($instance->getPrimaryKeys()[0]);

        // DB columns in declaration order + their logical types (for alignment).
        $columns = [];
        $types   = [];
        foreach ($instance->getFieldDefinitions() as $definition) {
            $columns[] = $definition['column'];
            $types[$definition['column']] = $definition['type'];
        }

        // Backend: delegate 100% to AutoCrud (idempotent — register once).
        self::registerBackend($modelClass, $instance, $prefix, $tableName);

        // Pagination / search / safe-sort from the query string.
        $query    = is_array($request->query) ? $request->query : [];
        $page     = max((int)($query['page'] ?? 1), 1);
        $search   = isset($query['search']) ? trim((string)$query['search']) : '';
        $sortCol  = self::crudSortColumn($instance, $sql, isset($query['sort']) ? (string)$query['sort'] : null, $pk);
        $sortDir  = (($query['sort_dir'] ?? '') === 'desc') ? 'desc' : 'asc';
        $offset   = ($page - 1) * $limit;

        if ($sql !== null) {
            [$records, $total] = self::fetchSqlData($sql, $search, $sortCol, $sortDir, $limit, $offset);
        } else {
            [$records, $total] = self::fetchModelData($instance, $types, $search, $sortCol, $sortDir, $limit, $offset);
        }

        $totalPages  = $total > 0 ? (int)ceil($total / $limit) : 1;
        $apiPath     = "{$prefix}/{$tableName}";
        $requestPath = (string)($request->path ?? '/');

        return self::renderPage(
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
     * Render an HTML table fragment from an array of record arrays via
     * crud/table.twig. Inline-editable (contenteditable cells + Save/Delete
     * buttons wired through the template's delegated listener).
     *
     * @param array<int, array<string, mixed>>|null $records
     * @return string
     */
    public static function generateTable(?array $records, string $tableName = 'data', string $primaryKey = 'id', bool $editable = true): string
    {
        $records = $records ?? [];
        $columns = $records === [] ? [] : array_keys($records[0]);

        return self::render('table', self::tableData(
            $columns,
            $records,
            $primaryKey,
            $tableName,
            $editable,
            false,
            $editable,
            null,
            '',
            null,
            'asc',
            1,
            $limit = 10,
            "crud-{$tableName}",
            []
        ));
    }

    /**
     * Render an HTML form from a field-definition array via crud/form.twig.
     *
     * @param array<int, array<string, mixed>>|null $fields Each: name, type, label, value, required, options.
     * @return string
     */
    public static function generateForm(?array $fields, string $action = '/', string $method = 'POST', string $tableName = 'data'): string
    {
        $verb = strtoupper($method);
        return self::render('form', [
            'wrap' => true,
            'form_id_attr' => '',
            'action' => self::h($action),
            'form_method' => self::h($verb),
            'method_override' => in_array($verb, ['PUT', 'PATCH', 'DELETE'], true) ? $verb : null,
            'edit' => false,
            'modal_footer' => false,
            'submit_button' => true,
            'fields' => array_map([self::class, 'buildCustomField'], $fields ?? []),
        ]);
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

    /**
     * Build the page template data and render page + table + modals (each via
     * its own template, so every sub-template is independently app-overridable).
     *
     * @param array<int, string> $columns
     * @param array<string, string> $types
     * @param array<int, array<string, mixed>> $records
     */
    private static function renderPage(
        string $title,
        string $tableName,
        string $pk,
        array $columns,
        array $types,
        array $records,
        int $page,
        int $totalPages,
        int $total,
        int $limit,
        string $search,
        string $sortCol,
        string $sortDir,
        string $apiPath,
        string $requestPath
    ): string {
        $editableColumns = array_values(array_filter($columns, static fn (string $c): bool => $c !== $pk));

        $tableHtml = self::render('table', self::tableData(
            $columns,
            $records,
            $pk,
            $tableName,
            false,
            true,
            false,
            $requestPath,
            $search,
            $sortCol,
            $sortDir,
            $page,
            $limit,
            null,
            $types
        ));

        $modalsHtml = self::renderModals($editableColumns, $pk);

        return self::render('page', [
            'title' => self::h($title),
            'search' => self::h($search),
            'request_path' => self::h($requestPath),
            'info_count' => count($records),
            'info_total' => $total,
            'info_page' => $page,
            'info_total_pages' => $totalPages,
            'table_html' => $tableHtml,
            'modals_html' => $modalsHtml,
            'show_pagination' => $totalPages > 1,
            'controls' => self::pageControls($page, $totalPages, $requestPath, $search, $sortCol, $sortDir, $limit),
            'config_json' => self::jsConfig($apiPath, $pk, $columns, $editableColumns, $types, $limit, $search, $sortCol, $sortDir, $page),
        ]);
    }

    /**
     * Render the create/edit/delete modal shell (crud/modals.twig), with the
     * create and edit forms rendered through crud/form.twig.
     *
     * @param array<int, string> $editableColumns
     */
    private static function renderModals(array $editableColumns, string $pk): string
    {
        return self::render('modals', [
            'create_form' => self::renderModalForm('create', $editableColumns, $pk, false),
            'edit_form' => self::renderModalForm('edit', $editableColumns, $pk, true),
        ]);
    }

    /**
     * A modal's create/edit form — fields + the Cancel/Save footer — via
     * crud/form.twig.
     *
     * @param array<int, string> $columns
     */
    private static function renderModalForm(string $mode, array $columns, string $pk, bool $edit): string
    {
        $fields = [];
        foreach ($columns as $col) {
            $label = self::prettyLabel($col);
            $fields[] = [
                'id' => "{$mode}-{$col}",
                'name' => self::h($col),
                'label' => self::h($label),
                'value' => '',
                'placeholder' => self::h('Enter ' . strtolower($label)),
                'type' => 'text',
                'required_attr' => '',
                'input' => true,
            ];
        }

        return self::render('form', [
            'wrap' => true,
            'form_id_attr' => " id=\"form-{$mode}\"",
            'action' => '',
            'form_method' => 'POST',
            'method_override' => null,
            'edit' => $edit,
            'mode' => $mode,
            'pk' => self::h($pk),
            'modal_footer' => true,
            'submit_button' => false,
            'fields' => $fields,
        ]);
    }

    /**
     * Build the data array crud/table.twig consumes: escaped headers (with sort
     * links + indicator + per-column alignment when sortable), rows carrying
     * pre-joined escaped+aligned <td> cell HTML, and the mutually-exclusive
     * editable/readonly flags.
     *
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $records
     * @param array<string, string> $types
     * @return array<string, mixed>
     */
    private static function tableData(
        array $columns,
        array $records,
        string $pk,
        string $tableName,
        bool $editable,
        bool $sortable,
        bool $inlineScript,
        ?string $requestPath,
        string $search,
        ?string $sortCol,
        string $sortDir,
        int $page,
        int $limit,
        ?string $tableId,
        array $types
    ): array {
        $aligns = [];
        foreach ($columns as $col) {
            $aligns[] = self::columnAlignment($types, $col);
        }

        $headers = [];
        foreach ($columns as $index => $col) {
            $header = ['label' => self::h(self::prettyLabel($col)), 'align' => $aligns[$index]];
            if ($sortable) {
                $nextDir = ((string)$sortCol === (string)$col && $sortDir === 'asc') ? 'desc' : 'asc';
                $header['sortable'] = true;
                $header['col'] = self::h($col);
                $header['next_dir'] = $nextDir;
                $header['url'] = self::sortUrl((string)$requestPath, $col, $nextDir, $page, $search, $limit);
                $header['indicator'] = self::sortIndicator((string)$sortCol, $col, $sortDir);
            } else {
                $header['plain'] = true;
            }
            $headers[] = $header;
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => self::h((string)self::cellValue($record, $pk)),
                'cells' => self::buildCells($columns, $record, $editable, $aligns),
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'empty' => $records === [],
            'colspan' => count($columns) + 1,
            'editable' => $editable,
            'readonly' => !$editable,
            'inline_script' => $inlineScript,
            'table_name' => self::h($tableName),
            'table_id_attr' => $tableId !== null ? ' id="' . self::h($tableId) . '"' : '',
        ];
    }

    /**
     * The pre-joined <td> cells for one row, every value HTML-escaped and
     * carrying its column alignment class.
     *
     * @param array<int, string> $columns
     * @param array<string, mixed> $record
     * @param array<int, string> $aligns
     */
    private static function buildCells(array $columns, array $record, bool $editable, array $aligns): string
    {
        $cells = '';
        foreach ($columns as $index => $col) {
            $value = self::h((string)self::cellValue($record, $col));
            $css = $aligns[$index];
            if ($editable) {
                $cells .= "<td class=\"{$css}\" contenteditable=\"true\" data-field=\"" . self::h($col) . "\">{$value}</td>";
            } else {
                $cells .= "<td class=\"{$css}\">{$value}</td>";
            }
        }
        return $cells;
    }

    /**
     * Column alignment from the model's declared field type: numeric columns
     * (int/float/decimal) align right, everything else left. With no type (the
     * generateTable fragment) every column aligns left.
     *
     * @param array<string, string> $types
     */
    private static function columnAlignment(array $types, string $col): string
    {
        $type = $types[$col] ?? null;
        return in_array($type, ['int', 'float', 'decimal'], true) ? 'text-end' : 'text-start';
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function cellValue(array $record, string $col): mixed
    {
        if (array_key_exists($col, $record)) {
            return $record[$col];
        }
        return null;
    }

    /**
     * A field array for crud/form.twig built from a generateForm field def.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function buildCustomField(array $field): array
    {
        $name = (string)($field['name'] ?? '');
        $label = (string)($field['label'] ?? ucfirst($name));
        $value = $field['value'] ?? '';
        $required = !empty($field['required']) ? ' required' : '';

        $base = [
            'id' => self::h($name),
            'name' => self::h($name),
            'label' => self::h($label),
            'value' => self::h((string)$value),
            'placeholder' => '',
            'required_attr' => $required,
        ];

        switch ((string)($field['type'] ?? 'string')) {
            case 'text':
                return $base + ['textarea' => true];
            case 'boolean':
                return $base + ['checkbox' => true, 'checked_attr' => $value ? ' checked' : ''];
            case 'select':
                return $base + ['select' => true, 'options_html' => self::buildOptions($field['options'] ?? [], $value)];
            case 'date':
                return $base + ['input' => true, 'type' => 'date'];
            case 'integer':
            case 'number':
            case 'float':
            case 'decimal':
                return $base + ['input' => true, 'type' => 'number'];
            default:
                return $base + ['input' => true, 'type' => 'text'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private static function buildOptions(array $options, mixed $selectedValue): string
    {
        $html = '';
        foreach ($options as $opt) {
            $selected = (string)($opt['value'] ?? '') === (string)$selectedValue ? ' selected' : '';
            $html .= '<option value="' . self::h((string)($opt['value'] ?? '')) . "\"{$selected}>" . self::h((string)($opt['label'] ?? '')) . '</option>';
        }
        return $html;
    }

    /**
     * Resolve the safe ORDER BY column (ADR-0069): a model field (resolved to
     * its DB column) or a column of the SQL query's own result set, else the
     * primary key. A rendered page ignores a bad sort, it never errors.
     */
    private static function crudSortColumn(ORM $instance, ?string $sql, ?string $requested, string $pk): string
    {
        if ($requested === null || $requested === '') {
            return $pk;
        }
        if ($sql === null) {
            return $instance->resolveFieldColumn($requested) ?? $pk;
        }
        $cols = self::sqlResultColumns($sql);
        if (!in_array($requested, $cols, true)) {
            return $instance->resolveFieldColumn($requested) ?? $pk;
        }
        return $requested;
    }

    /**
     * Fetch a page of records from the model (ADR-0069 safe search across the
     * model's declared string columns).
     *
     * @param array<string, string> $types
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function fetchModelData(ORM $instance, array $types, string $search, string $sort, string $sortDir, int $limit, int $offset): array
    {
        $orderBy = "{$sort} " . strtoupper($sortDir);

        if ($search === '') {
            $collection = $instance->all($limit, $offset, null, $orderBy);
            $total = $instance->count();
            return [self::collectionToRecords($collection), $total];
        }

        $searchable = [];
        foreach ($types as $column => $type) {
            if ($type === 'string') {
                $searchable[] = $column;
            }
        }

        if ($searchable === []) {
            $collection = $instance->all($limit, $offset, null, $orderBy);
            return [self::collectionToRecords($collection), $instance->count()];
        }

        $clause = implode(' OR ', array_map(static fn (string $col): string => "{$col} LIKE ?", $searchable));
        $params = array_fill(0, count($searchable), "%{$search}%");
        $collection = $instance->where($clause, $params, $limit, $offset, null, $orderBy);
        return [self::collectionToRecords($collection), $collection->getTotalRecords()];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collectionToRecords(ModelCollection $collection): array
    {
        $records = [];
        foreach ($collection as $model) {
            $records[] = $model->toDict();
        }
        return $records;
    }

    /**
     * Fetch a page of rows for a custom listing SQL. The SQL shapes the DISPLAY
     * only; writes/GET always go through AutoCrud.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function fetchSqlData(string $sql, string $search, string $sort, string $sortDir, int $limit, int $offset): array
    {
        $db = ORM::database();
        $base = self::stripOrderAndLimit($sql);
        $dir = strtoupper($sortDir);

        if ($search === '') {
            $query = "{$base} ORDER BY {$sort} {$dir}";
            return self::normaliseFetch($db->fetch($query, [], $limit, $offset));
        }

        $cols = self::extractColumns($sql);
        $searchParts = array_map(static fn (string $col): string => "CAST({$col} AS TEXT) LIKE ?", $cols);
        $where = implode(' OR ', $searchParts);
        $params = array_fill(0, count($cols), "%{$search}%");
        $query = "SELECT * FROM ({$base}) AS _crud_sub WHERE {$where} ORDER BY {$sort} {$dir}";
        return self::normaliseFetch($db->fetch($query, $params, $limit, $offset));
    }

    /**
     * Normalise an adapter fetch() result (a DatabaseResult, or the SQLite
     * adapter's {data,total,limit,offset} array, or a bare row list) to
     * [rows, total].
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function normaliseFetch(mixed $result): array
    {
        if ($result instanceof \Tina4\Database\DatabaseResult) {
            return [$result->records, $result->count];
        }
        if (is_array($result) && array_key_exists('data', $result)) {
            $rows = is_array($result['data']) ? $result['data'] : [];
            return [$rows, (int)($result['total'] ?? count($rows))];
        }
        $rows = is_array($result) ? $result : [];
        return [$rows, count($rows)];
    }

    /**
     * The query with any ORDER BY / LIMIT clause removed, line by line with
     * plain string operations so it stays linear on any input.
     */
    private static function stripOrderAndLimit(string $sql): string
    {
        $out = [];
        foreach (preg_split('/(?<=\n)/', $sql) ?: [$sql] as $line) {
            $out[] = self::cutFromKeyword(self::cutFromKeyword($line, 'ORDER BY '), 'LIMIT ');
        }
        return trim(implode('', $out));
    }

    private static function cutFromKeyword(string $line, string $keyword): string
    {
        $ending = str_ends_with($line, "\n") ? "\n" : '';
        $content = $ending === '' ? $line : substr($line, 0, -1);
        $at = stripos($content, $keyword);
        if ($at === false || strlen($content) <= $at + strlen($keyword)) {
            return $line;
        }
        return substr($content, 0, $at) . $ending;
    }

    /**
     * @return array<int, string>
     */
    private static function sqlResultColumns(string $sql): array
    {
        try {
            $db = ORM::database();
        } catch (\Throwable $e) {
            return [];
        }
        $base = self::stripOrderAndLimit($sql);
        [$rows] = self::normaliseFetch($db->fetch("SELECT * FROM ({$base}) AS _crud_sub", [], 1, 0));
        return $rows === [] ? [] : array_keys($rows[0]);
    }

    /**
     * @return array<int, string>
     */
    private static function extractColumns(string $sql): array
    {
        if (!preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $match)) {
            return ['*'];
        }
        $colsStr = trim($match[1]);
        if ($colsStr === '*') {
            return ['*'];
        }
        $columns = [];
        foreach (explode(',', $colsStr) as $c) {
            $c = trim($c);
            if (preg_match('/\bAS\s+(\w+)/i', $c, $m)) {
                $columns[] = $m[1];
            } elseif (str_contains($c, '.')) {
                $parts = explode('.', $c);
                $columns[] = trim(end($parts));
            } else {
                $columns[] = $c;
            }
        }
        return $columns;
    }

    /**
     * One flat list of pagination controls (Prev, numbered pages, Next) for
     * crud/page.twig. Each carries exactly one of active/inactive.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pageControls(int $page, int $totalPages, string $requestPath, string $search, string $sortCol, string $sortDir, int $limit): array
    {
        if ($totalPages <= 1) {
            return [];
        }

        $controls = [];
        if ($page > 1) {
            $controls[] = ['label' => 'Prev', 'page' => $page - 1, 'active' => false, 'inactive' => true,
                'url' => self::pageUrl($requestPath, $page - 1, $search, $sortCol, $sortDir, $limit)];
        }

        $startPage = max($page - 3, 1);
        $endPage = min($startPage + 6, $totalPages);
        $startPage = max($endPage - 6, 1);
        for ($p = $startPage; $p <= $endPage; $p++) {
            $controls[] = ['label' => $p, 'page' => $p, 'active' => ($p === $page), 'inactive' => ($p !== $page),
                'url' => self::pageUrl($requestPath, $p, $search, $sortCol, $sortDir, $limit)];
        }

        if ($page < $totalPages) {
            $controls[] = ['label' => 'Next', 'page' => $page + 1, 'active' => false, 'inactive' => true,
                'url' => self::pageUrl($requestPath, $page + 1, $search, $sortCol, $sortDir, $limit)];
        }
        return $controls;
    }

    private static function pageUrl(string $requestPath, int $p, string $search, string $sortCol, string $sortDir, int $limit): string
    {
        $query = "page={$p}&search=" . rawurlencode($search)
            . '&sort=' . rawurlencode($sortCol)
            . "&sort_dir={$sortDir}&limit={$limit}";
        return self::h("{$requestPath}?{$query}");
    }

    private static function sortUrl(string $requestPath, string $col, string $nextDir, int $page, string $search, int $limit): string
    {
        $query = 'sort=' . rawurlencode($col) . "&sort_dir={$nextDir}"
            . "&page={$page}&search=" . rawurlencode($search) . "&limit={$limit}";
        return self::h("{$requestPath}?{$query}");
    }

    private static function sortIndicator(string $sortCol, string $col, string $sortDir): string
    {
        if ($sortCol !== $col) {
            return '';
        }
        $arrow = $sortDir === 'asc' ? '&#9650;' : '&#9660;';
        return " <span class=\"sort-indicator\">{$arrow}</span>";
    }

    /**
     * JSON config injected into the page's nonce'd <script>. The grid's whole
     * state, the display columns, their alignment and labels, and the editable
     * columns all travel here so the client can drive the AutoCrud list endpoint
     * and re-render the table over fetch(). The < > & characters are
     * unicode-escaped so the literal is safe inside the <script> element.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $editable
     * @param array<string, string> $types
     */
    private static function jsConfig(string $apiPath, string $pk, array $columns, array $editable, array $types, int $limit, string $search, string $sortCol, string $sortDir, int $page): string
    {
        $aligns = [];
        $labels = [];
        foreach ($columns as $col) {
            $aligns[$col] = self::columnAlignment($types, $col);
            $labels[$col] = self::prettyLabel($col);
        }

        $config = [
            'api' => $apiPath,
            'pk' => $pk,
            'columns' => array_values($columns),
            'editable' => array_values($editable),
            'aligns' => $aligns,
            'labels' => $labels,
            'limit' => $limit,
            'search' => $search,
            'sort' => $sortCol,
            'sort_dir' => $sortDir,
            'page' => $page,
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'], (string)$json);
    }

    /**
     * Render a crud/<name>.twig template, app override first (src/templates/)
     * then the framework default (Tina4/templates/) — the same resolution the
     * error pages use.
     *
     * @param array<string, mixed> $data
     */
    private static function render(string $name, array $data): string
    {
        $relative = "crud/{$name}.twig";

        if (is_file('src/templates/' . $relative)) {
            try {
                return Response::getFrond()->render($relative, $data);
            } catch (\Throwable $e) {
                // Fall through to the framework default.
            }
        }

        return Response::getFrameworkFrond()->render($relative, $data);
    }

    /**
     * Escape HTML special characters, matching Frond's own escaper.
     */
    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Pretty label from a column name: "user_name" => "User Name".
     */
    private static function prettyLabel(string $col): string
    {
        return implode(' ', array_map('ucfirst', explode('_', $col)));
    }
}
