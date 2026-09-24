<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * This is for helping doing heavy lifting and helping with mundane tasks
 * @package Tina4
 */
class Crud
{
    use Utility;

    //Common inputs, firstName,lastName,email,mobileNo,address1,address2,cityTown,postalCode,Country

    /*
<input type="button">
<input type="checkbox">
<input type="color">
<input type="date">
<input type="datetime-local">
<input type="email">
<input type="file">
<input type="hidden">
<input type="image">
<input type="month">
<input type="number">
<input type="password">
<input type="radio">
<input type="range">
<input type="reset">
<input type="search">
<input type="submit">
<input type="tel">
<input type="text">
<input type="time">
<input type="url">
<input type="week">
     */

    /**
     *
     * @param $name
     * @param string $value
     * @param string $placeHolder
     * @param string $type
     * @param false $required
     * @param string $javascript
     * @param array $lookupData
     * @param string $label
     * @return object
     */
    public static function addFormInput(
        $name,
        $value = "",
        $placeHolder = "",
        $type = "text",
        $required = false,
        $javascript = "",
        $lookupData = [],
        $label = ""
    ) {
        if (empty($label) && !empty($placeHolder)) {
            $label = $placeHolder;
        }

        return (object)[
            "name" => $name,
            "placeHolder" => $placeHolder,
            "label" => $label,
            "value" => $value,
            "type" => $type,
            "required" => $required,
            "javascript" => $javascript,
            "options" => $lookupData
        ];
    }


    /**
     * Generates a form
     * @param $formInputs
     * @param int $noOfColumns
     * @param string $formName
     * @param string $formMethod
     * @param null $formAction
     * @param string $groupClass
     * @param string $inputClass
     * @param string $columnClass
     * @param string $imageClass
     * @return string
     */
    public static function generateForm(
        $formInputs,
        $noOfColumns = 1,
        $formName = "data",
        $formMethod = "post",
        $formAction = null,
        $groupClass = "form-group",
        $inputClass = "form-control",
        $columnClass = "col-md-",
        $imageClass = "img-thumbnail rounded mx-auto "
    ) {
        $fields = [];
        $colSpan = 12 / $noOfColumns;
        foreach ($formInputs as $id => $formInput) {
            if (in_array(
                $formInput->type,
                [
                    "text",
                    "password",
                    "hidden",
                    "color",
                    "file",
                    "tel",
                    "date",
                    "datetime-local",
                    "email",
                    "month",
                    "number",
                    "search",
                    "time",
                    "url",
                    "week"
                ]
            )) {
                $fields[] = _div(
                    ["class" => $columnClass . $colSpan],
                    _div(
                        ["class" => $groupClass],
                        _label(["for" => $formInput->name], $formInput->label),
                        _input([
                            "class" => $inputClass,
                            "type" => $formInput->type,
                            "name" => $formInput->name,
                            "id" => $formInput->name,
                            "placeholder" => $formInput->placeHolder,
                            "value" => $formInput->value,
                            "required" => $formInput->required,
                            "_" => $formInput->javascript
                        ])
                    )
                );
            } elseif ($formInput->type == "select") {
                $options = [];
                $selected = null;
                foreach ($formInput->options as $key => $value) {
                    if ($key == $formInput->value) {
                        $selected = ["selected" => true];
                    } else {
                        $selected = null;
                    }
                    $options[] = _option(["value" => $key, $selected], $value);
                }

                $fields[] = _div(["class" => $columnClass . $colSpan],
                    _div(
                        ["class" => $groupClass],
                        _label(["for" => $formInput->name], $formInput->label),
                        _select(
                            [
                                "class" => $inputClass,
                                "name" => $formInput->name,
                                "id" => $formInput->name,
                                "required" => $formInput->required,
                                "_" => $formInput->javascript
                            ],
                            $options
                        )
                    ));
            } elseif ($formInput->type == "image") {
                $fields[] = _div(["class" => $columnClass . $colSpan],
                    _div(
                        ["class" => $groupClass],
                        _label(["for" => $formInput->name], $formInput->label),
                        _br(),
                        _img(["src" => "data:image/png;base64," . $formInput->value, "class" => $imageClass]),
                        _br(),
                        _input([
                            "type" => "file",
                            "name" => $formInput->name,
                            "id" => $formInput->name,
                            "placeholder" => $formInput->placeHolder,
                            "value" => $formInput->value,
                            "required" => $formInput->required,
                            "_" => $formInput->javascript
                        ])
                    ));
            }
        }

        return _form(
            ["name" => $formName, "method" => $formMethod, "action" => $formAction],
            _div(["class" => "row"], $fields)
        );
    }

    /**
     * CRUD ROUTING FOR DATATABLES
     * @param $path
     * @param ORM $object
     * @param $function
     * @param bool $secure
     * @param bool $cached
     * @param array $middleware
     * @param ORM|null $filterObject ORM the "read" handler actually queries, when it differs
     *                               from $object (typically a view). getDataTablesFilter()
     *                               only accepts columns of the ORM it is given, so without
     *                               this the view's own columns are silently ignored.
     */
    public static function route(
        $path,
        ORM $object,
        $function,
        bool $secure = false,
        bool $cached = false,
        array $middleware = [],
        ?ORM $filterObject = null
    ): void {
        list(, $caller) = debug_backtrace(false);

        //What if the path has ids in it ? /store/{id}/{hash}
        /**
         * @description  {description} for {path}
         * @summary Get all for {path}
         * @tags {tags}
         */
        Route::get(
            $path . "/form",
            function (Response $response, Request $request) use ($object, $function) {
                $htmlResult = $function("form", $object, null, $request);

                return $response($htmlResult, HTTP_OK);
            }
        )
            ->secure($secure)
            ->cache($cached)
            ->middleware($middleware);

        /**
         * @description  {description} for {path}
         * @summary Post for {path}
         * @tags {tags}
         * @example {example}
         */
        Route::post(
            $path,
            function (Response $response, Request $request) use ($object, $function) {
                $postData = self::getObjects($request);
                $jsonResult = [];
                foreach ($postData as $inputObject) {
                    $object->create($inputObject);
                    $function("create", $object, null, $request);
                    $object->save();
                    $jsonResult[] = $function("afterCreate", $object, null, $request);
                }

                if (count($jsonResult) === 1) {
                    $jsonResult = $jsonResult[0];
                }

                return $response($jsonResult, HTTP_OK);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);

        /**
         * @description  {description} for {path}
         * @summary Get for {path}
         * @tags {tags}
         */
        Route::get(
            $path,
            function (Response $response, Request $request) use ($object, $function, $filterObject) {
                $filter = Crud::getDataTablesFilter("t.", $filterObject ?? new $object());
                $jsonResult = $function("read", new $object(), $filter, $request);

                return $response($jsonResult, HTTP_OK);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);


        /**
         * @description  {description} for {path}
         * @summary Get by Id for {path}
         * @tags {tags}
         * @example {example}
         */
        Route::get(
            $path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param

                if (!$object->load("{$object->getFieldName($object->primaryKey)} = ?", [$id])) {
                    $object = new $object();
                }

                $jsonResult = $function("fetch", $object, null, $request);

                return $response($jsonResult, HTTP_OK);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);

        /**
         * @description  {description} for {path}
         * @summary Post for Id for {path}
         * @tags {tags}
         * @example {example}
         */
        Route::post(
            $path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                return self::updateById($response, $request, $object, $function);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);

        /**
         * @description  {description} for {path}
         * @summary Put by Id for {path}
         * @tags {tags}
         * @example {example}
         */
        Route::put(
            $path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                return self::updateById($response, $request, $object, $function);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);

        /**
         * @description  {description} for {path}
         * @summary Delete by Id for {path}
         * @tags {tags}
         */
        Route::delete(
            $path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param
                $object->create($request->params);
                $object->load("{$object->getFieldName($object->primaryKey)} = ?", [$id]);
                $function("delete", $object, null, $request);
                if (!$object->softDelete) {
                    $object->delete();
                } else {
                    $object->save();
                }
                $jsonResult = $function("afterDelete", $object, null, $request);

                return $response($jsonResult, HTTP_OK);
            }
        )->secure($secure)
            ->cache($cached)
            ->middleware($middleware);
    }

    /**
     * Get data objects from the request to the ORM object
     * @param Request $request
     * @return array
     */
    public static function getObjects(Request $request)
    {
        $objects = [];
        if (!empty($request->data)) {
            if (is_array($request->data) && isset($request->data[0]) && is_object($request->data[0])) {
                foreach ($request->data as $key => $object) {
                    $objects[] = $object;
                }
            } else {
                $objects[] = $request->data;
            }
        } else {
            $objects[] = $request->params;
        }

        return $objects;
    }

    /**
     * Updates the record named by the last inline param and returns the
     * "afterUpdate" result. Shared by the POST /{id} and PUT /{id} routes, which
     * keep separate closures only so each carries its own swagger docblock.
     *
     * @param Response $response
     * @param Request $request
     * @param ORM $object
     * @param callable $function The crud callback registered for the route
     * @return mixed
     */
    private static function updateById(Response $response, Request $request, ORM $object, callable $function)
    {
        $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param
        if (!empty($request->data)) {
            $object->create($request->data);
        } else {
            $object->create($request->params);
        }
        $object->load("{$object->getFieldName($object->primaryKey)} = ?", [$id]);
        $function("update", $object, null, $request);
        $object->save();
        $jsonResult = $function("afterUpdate", $object, null, $request);

        return $response($jsonResult, HTTP_OK);
    }

    /**
     * Returns an array of dataTables style filters for use in your queries
     * @param string $tablePrefix
     * @param ORM|null $ORM
     * @return array
     */
    public static function getDataTablesFilter(string $tablePrefix = "", ?ORM $ORM = null): array
    {
        $request = $_REQUEST;

        if (empty($ORM)) {
            $ORM = new ORM();
        }

        if (!empty($request["columns"])) {
            $columns = $request["columns"];
        }

        if (!empty($request["order"])) {
            $orderBy = $request["order"];
        }

        //Column names the request is allowed to reference, taken from the ORM itself
        $allowedColumns = self::getAllowedColumnNames($ORM);

        $filter = null;
        $listOfColumnNames = null;
        if (!empty($request["search"])) {
            $search = $request["search"];

            foreach ($columns as $id => $column) {
                if (!is_array($column) || !isset($column["data"])) {
                    continue;
                }

                $columnName = self::getSafeColumnName($ORM, $column["data"], $allowedColumns);

                //Anything that does not resolve to a known column is ignored
                if ($columnName === null) {
                    continue;
                }

                if (($column["searchable"] == "true")) {
                    // Prioritises general search over column search
                    if (!empty($search["value"]) && is_scalar($search["value"])) {
                        // Searches all searchable fields for the search box value
                        //Add each searchable column to array
                        $listOfColumnNames[] = $tablePrefix . $columnName;
                        //Split search phrase into individual searchable words
                        $splitValue = explode(" ", (string)$search["value"]);

                        //Iterate searchable words
                        foreach ($splitValue as $singleValue) {
                            //Check that the values aren't whitespaces
                            if (!empty($singleValue)) {
                                //The search box holds data, never SQL, so it is quoted as a literal
                                $filterValue = " like " . self::escapeLiteral($ORM, "%" . strtoupper($singleValue) . "%");
                                //Check if $filer is already an array
                                if (!is_array($filter)) {
                                    $filter[] = $filterValue;
                                    //Check if filter value is already in $filer array
                                } elseif (!in_array($filterValue, $filter)) {
                                    $filter[] = $filterValue;
                                }
                            }
                        }
                    } elseif (!empty($column["search"]["value"])) {
                        $listOfColumnNames[] = $tablePrefix . $columnName;
                        // Allows column value searches to be set from the outset
                        $searchValue = $column['search']['value'];
                        $isRegex = $column['search']['regex'] ?? false;

                        if ($isRegex == "true") {
                            // Use REGEXP for regex search. The pattern is data, so it is quoted.
                            $filter[] = " REGEXP " . self::escapeLiteral($ORM, (string)$searchValue);
                        } else {
                            // a standard search filter any sql can be used.
                            // fieldName "=5" or " like '%test%'"
                            //
                            // NOTE: this branch is a deliberate SQL passthrough and the value
                            // arrives from the request, so an application exposing it to
                            // untrusted callers must validate it before it gets here.
                            $filter[] = $searchValue;
                        }
                    }
                }
            }
        }

        $ordering = null;
        if (!empty($orderBy)) {
            foreach ($orderBy as $id => $orderEntry) {
                if (!is_array($orderEntry) || !isset($orderEntry["column"], $columns[$orderEntry["column"]]["data"])) {
                    continue;
                }

                $columnName = self::getSafeColumnName($ORM, $columns[$orderEntry["column"]]["data"], $allowedColumns);

                if ($columnName === null) {
                    continue;
                }

                //Only asc/desc may reach the statement
                $direction = strtolower(trim((string)($orderEntry["dir"] ?? ""))) === "desc" ? "desc" : "asc";
                $ordering[] = $tablePrefix . $columnName . " " . $direction;
            }
        }

        $order = "";
        if (is_array($ordering) && count($ordering) > 0) {
            $order = join(",", $ordering);
        }

        $where = "";
        //Check that filter isn't empty
        if (is_array($filter) && count($filter) > 0 && !empty($listOfColumnNames)) {
            $whereArray = null;
            $columnsToSearch = "";

            //Concatenate row columns into a single searchable string

            //Check for type of database
            if (!empty($ORM->DBA)) {
                //Mysql
                //upper() on the column as well as the value: the search term is
                //uppercased above, so without this the comparison only matches
                //data that is already uppercase on any engine whose LIKE is
                //case-sensitive (PostgreSQL, and MySQL under a binary collation).
                foreach ($listOfColumnNames as $id => $listColumn) {
                    $listOfColumnNames[$id] = "upper(coalesce($listColumn, ''))";
                }

                if (in_array(get_class($ORM->DBA), ["Tina4\DataMySQL", "Tina4\DataMSSQL"])) {
                    $columnsToSearch = "concat(" . join(",'',", $listOfColumnNames) . ")";
                } else {
                    $columnsToSearch = join(" || '' || ", $listOfColumnNames);
                }
            }

            //Without a connection there is nothing to concatenate the columns with,
            //so the filter is dropped rather than emitted against no column at all.
            if ($columnsToSearch !== "") {
                //Create check statement per searched word
                foreach ($filter as $searchFor) {
                    $whereArray[] = $columnsToSearch . $searchFor;
                }

                //Glue each searchable phrase with "and" to ensure that it contains all searched words
                $where = join(" and ", $whereArray);
            }
        }

        //Both are concatenated into a limit/offset by the caller, so they are cast
        $start = !empty($request["start"]) ? (int)$request["start"] : 0;
        $length = !empty($request["length"]) ? (int)$request["length"] : 10;

        return ["length" => $length, "start" => $start, "orderBy" => $order, "where" => $where];
    }

    /**
     * The column names a request may reference, taken from the ORM's own fields
     * and its field mapping.
     *
     * An empty set means the ORM exposes no fields — a bare Tina4\ORM, for
     * instance — in which case only the identifier shape is enforced and the
     * previous behaviour is preserved.
     *
     * @param ORM $ORM
     * @return array
     */
    private static function getAllowedColumnNames(ORM $ORM): array
    {
        $allowed = [];

        foreach ($ORM->getFieldNames() as $fieldName) {
            $allowed[strtolower($fieldName)] = true;
        }

        if (!empty($ORM->fieldMapping) && is_array($ORM->fieldMapping)) {
            foreach ($ORM->fieldMapping as $mappedName) {
                if (is_string($mappedName)) {
                    $allowed[strtolower($mappedName)] = true;
                }
            }
        }

        return $allowed;
    }

    /**
     * Resolves a DataTables column to a database column name.
     *
     * Column names cannot be bound as parameters, so they are concatenated into
     * the statement. Returns null unless the result is a bare identifier and a
     * field the ORM actually knows about.
     *
     * @param ORM $ORM
     * @param mixed $requestedColumn
     * @param array $allowedColumns
     * @return string|null
     */
    private static function getSafeColumnName(ORM $ORM, $requestedColumn, array $allowedColumns): ?string
    {
        if (!is_string($requestedColumn) || $requestedColumn === "") {
            return null;
        }

        $columnName = (string)$ORM->getFieldName($requestedColumn, $ORM->fieldMapping);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $columnName)) {
            return null;
        }

        if (!empty($allowedColumns) && !isset($allowedColumns[strtolower($columnName)])) {
            return null;
        }

        return $columnName;
    }

    /**
     * Quotes a value as a SQL string literal, using the connection's own escaping
     * where the driver offers it.
     *
     * Public so that callers assembling their own where clauses alongside this
     * filter can quote their values the same way. Prefer bound `?` parameters
     * wherever the query allows them; reach for this only when extending the
     * string filter getDataTablesFilter() returns.
     *
     * @param ORM|null $ORM
     * @param string $value
     * @return string
     */
    public static function escapeLiteral(?ORM $ORM, string $value): string
    {
        $dba = $ORM->DBA ?? null;

        if (!empty($dba)) {
            $driver = get_class($dba);

            if ($driver === "Tina4\\DataPostgresql" && !empty($dba->dbh) && function_exists("pg_escape_literal")) {
                $escaped = @pg_escape_literal($dba->dbh, $value);

                if ($escaped !== false) {
                    return $escaped;
                }
            }

            if ($driver === "Tina4\\DataMySQL") {
                //MySQL treats a backslash as an escape character unless
                //NO_BACKSLASH_ESCAPES is set, so it has to be neutralised too.
                return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
            }
        }

        //Standard SQL, and what MSSQL and SQLite3 expect: double the quote.
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
