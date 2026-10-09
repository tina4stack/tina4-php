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

/**
 * CrudView (ADR-0094) — the server-side HTML assembly for {@see Crud}.
 *
 * Every byte of the CRUD admin UI is built here from four app-overridable Frond
 * templates under `crud/` (page, table, form, modals), resolved app-first then
 * framework — the same resolution the error pages use. It owns no data access
 * and no routes: {@see Crud} passes it the already-fetched records and state,
 * and it returns rendered HTML. Keeping it separate from {@see Crud} and
 * {@see CrudData} keeps each concern (orchestration / data / view) small.
 *
 * @internal Part of the Crud frontend; not a public application API.
 */
class CrudView
{
    /**
     * Render an HTML table fragment from an array of record arrays via
     * crud/table.twig. Inline-editable (contenteditable cells + Save/Delete
     * buttons wired through the template's delegated listener).
     *
     * @param array<int, array<string, mixed>>|null $records
     */
    public static function table(?array $records, string $tableName = 'data', string $primaryKey = 'id', bool $editable = true): string
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
            10,
            "crud-{$tableName}",
            []
        ));
    }

    /**
     * Render an HTML form from a field-definition array via crud/form.twig.
     *
     * @param array<int, array<string, mixed>>|null $fields Each: name, type, label, value, required, options.
     */
    public static function form(?array $fields, string $action = '/', string $method = 'POST', string $tableName = 'data'): string
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
     * Build the page template data and render page + table + modals (each via
     * its own template, so every sub-template is independently app-overridable).
     *
     * @param array<int, string> $columns
     * @param array<string, string> $types
     * @param array<int, array<string, mixed>> $records
     */
    public static function renderPage(
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
     * table fragment) every column aligns left.
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
     * A field array for crud/form.twig built from a form() field def. The
     * value-dependent types (boolean, select) branch; every other type maps to
     * a fixed input descriptor, so the method stays simple.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function buildCustomField(array $field): array
    {
        $name = (string)($field['name'] ?? '');
        $value = $field['value'] ?? '';

        $base = [
            'id' => self::h($name),
            'name' => self::h($name),
            'label' => self::h((string)($field['label'] ?? ucfirst($name))),
            'value' => self::h((string)$value),
            'placeholder' => '',
            'required_attr' => !empty($field['required']) ? ' required' : '',
        ];

        $type = (string)($field['type'] ?? 'string');
        if ($type === 'boolean') {
            return $base + ['checkbox' => true, 'checked_attr' => $value ? ' checked' : ''];
        }
        if ($type === 'select') {
            return $base + ['select' => true, 'options_html' => self::buildOptions($field['options'] ?? [], $value)];
        }

        $inputTypes = [
            'text' => ['textarea' => true],
            'date' => ['input' => true, 'type' => 'date'],
            'integer' => ['input' => true, 'type' => 'number'],
            'number' => ['input' => true, 'type' => 'number'],
            'float' => ['input' => true, 'type' => 'number'],
            'decimal' => ['input' => true, 'type' => 'number'],
        ];
        return $base + ($inputTypes[$type] ?? ['input' => true, 'type' => 'text']);
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
