<?php

namespace Tina4;

/**
 * Class Crud
 * This is for helping doing heavy lifting and helping with mundane tasks
 * @package Tina4
 */
class Crud
{
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
    public static function addFormInput($name, $value = "", $placeHolder = "", $type = "text", $required = false, $javascript = "", $lookupData = [], $label = "")
    {
        if (empty($label) && !empty($placeHolder)) $label = $placeHolder;
        return (object)["name" => $name, "placeHolder" => $placeHolder, "label" => $label, "value" => $value, "type" => $type, "required" => $required, "javascript" => $javascript, "options" => $lookupData];
    }


    /**
     * Generates a form
     * @param $formInputs
     * @param int $noOfColumns
     * @param string $formName
     * @param string $groupClass
     * @param string $inputClass
     * @return string
     */
    public static function generateForm($formInputs, $noOfColumns = 1, $formName = "data", $formMethod = "post", $formAction = null, $groupClass = "form-group", $inputClass = "form-control", $columnClass = "col-md-", $imageClass = "img-thumbnail rounded mx-auto ")
    {
        $fields = [];
        $colSpan = 12 / $noOfColumns;
        foreach ($formInputs as $id => $formInput) {
            if (in_array($formInput->type, ["text", "password", "hidden", "color", "file", "tel", "date", "datetime-local", "email", "month", "number", "search", "time", "url", "week"])) {
                $fields[] = _div(["class" => $columnClass . $colSpan],
                    _div(["class" => $groupClass],
                        _label(["for" => $formInput->name], $formInput->label),
                        _input(["class" => $inputClass,
                                "type" => $formInput->type,
                                "name" => $formInput->name,
                                "id" => $formInput->name,
                                "placeholder" => $formInput->placeHolder,
                                "value" => $formInput->value,
                                "required" => $formInput->required,
                                "_" => $formInput->javascript]
                        )
                    ));
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

                $fields[] = _div(["class" => $columnClass . $colSpan], _div(["class" => $groupClass],
                    _label(["for" => $formInput->name], $formInput->label),
                    _select(["class" => $inputClass,
                        "name" => $formInput->name,
                        "id" => $formInput->name,
                        "required" => $formInput->required,
                        "_" => $formInput->javascript],
                        $options
                    )
                ));
            } elseif ($formInput->type == "image") {
                $fields[] = _div(["class" => $columnClass . $colSpan], _div(["class" => $groupClass],
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
                            "_" => $formInput->javascript]
                    )
                ));
            }
        }

        return _form(["name" => $formName, "method" => $formMethod, "action" => $formAction],
            _div(["class" => "row"], $fields)
        );
    }

    /**
     * CRUD ROUTING FOR DATATABLES
     * @param $path
     * @param ORM $object
     * @param $function
     * @params $secure
     * @param $secures
     */
    public static function route($path, ORM $object, $function, $secure = false)
    {
        //What if the path has ids in it ? /store/{id}/{hash}

        //CREATE
        Route::get($path . "/form",
            function (Response $response, Request $request) use ($object, $function) {
                $htmlResult = $function ("form", $object, null, $request);
                return $response ($htmlResult, HTTP_OK);
            }
        );

        Route::post($path,
            function (Response $response, Request $request) use ($object, $function) {
                if (!empty($request->data)) {
                    $object->create($request->data);
                } else {
                    $object->create($request->params);
                }
                $function ("create", $object, null, $request);
                $object->save();
                $jsonResult = $function ("afterCreate", $object, null, $request);
                return $response ($jsonResult, HTTP_OK);
            }
        );

        //READ
        Route::get($path,
            function (Response $response, Request $request) use ($object, $function) {
                $filter = Crud::getDataTablesFilter();
                $jsonResult = $function ("read", new $object(), $filter, $request);
                return $response ($jsonResult, HTTP_OK);
            }
        );


        //UPDATE
        Route::get($path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param

                $jsonResult = $function ("fetch", (new $object())->load("{$object->getFieldName($object->primaryKey)} = '{$id}'"), null, $request);
                if (empty($jsonResult)) {
                    $jsonResult = (new $object())->load("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                }

                return $response ($jsonResult, HTTP_OK);
            }
        );

        Route::post($path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param
                if (!empty($request->data)) {
                    $object->create($request->data);
                } else {
                    $object->create($request->params);
                }
                $object->load("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                $function ("update", $object, null, $request);
                $object->save();
                $jsonResult = $function ("afterUpdate", $object, null, $request);
                return $response ($jsonResult, HTTP_OK);
            }
        );

        //DELETE
        Route::delete($path . "/{id}",
            function (Response $response, Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams) - 1]; //get the id on the last param
                $object->create($request->params);
                $object->load("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                $function ("delete", $object, null, $request);
                if (!$object->softDelete) {
                    $object->delete();
                } else {
                    $object->save();
                }
                $jsonResult = $function ("afterDelete", $object, null, $request);
                return $response ($jsonResult, HTTP_OK);
            }
        );

    }

    /**
     * Returns an array of dataTables style filters for use in your queries
     * @return array
     * @throws \Exception
     */
    public static function getDataTablesFilter()
    {
        $ORM = new ORM();
        $request = $_REQUEST;

        if (!empty($request["columns"])) {
            $columns = $request["columns"];
        }

        if (!empty($request["order"])) {
            $orderBy = $request["order"];
        }

        $filter = null;
        $listOfColumnNames = null;
        if (!empty($request["search"])) {
            $search = $request["search"];

            foreach ($columns as $id => $column) {
                $columnName = $ORM->getFieldName($column["data"]);

                if (($column["searchable"] == "true") && !empty($search["value"])) {
                    //Add each searchable column to array
                    $listOfColumnNames[] = $columnName;
                    //Split search phrase into individual searchable words
                    $splitValue = explode(" ", $search["value"]);

                    //Iterate searchable words
                    foreach ($splitValue as $singleValue) {
                        //Check that the values aren't whitespaces
                        if (!empty($singleValue)) {
                            $filterValue = " like '%" . strtoupper($singleValue) . "%'";
                            //Check if $filer is already an array
                            if (!is_array($filter)) {
                                $filter[] = $filterValue;
                                //Check if filter value is already in $filer array
                            } else if (!in_array($filterValue, $filter)) {
                                $filter[] = $filterValue;
                            }
                        }
                    }
                }
            }
        }

        $ordering = null;
        if (!empty($orderBy)) {
            foreach ($orderBy as $id => $orderEntry) {
                $columnName = $ORM->getFieldName($columns[$orderEntry["column"]]["data"]);
                $ordering[] = $columnName . " " . $orderEntry["dir"];
            }
        }

        $order = "";
        if (is_array($ordering) && count($ordering) > 0) {
            $order = join(",", $ordering);
        }

        $where = "";
        //Check that filter isn't empty
        if (is_array($filter) && count($filter) > 0) {
            $whereArray = null;

            //Concatenate row columns into a single searchable string

            //Check for type of database
            if (!empty($ORM->DBA) && get_class($ORM->DBA) === "Tina4\DataMySQL") {
                //Mysql
                $columnsToSearch = "concat(" . join(",' ',", $listOfColumnNames) . ")";
            } else {
                //Non-mysql
                $columnsToSearch = join(" || ' ' || ", $listOfColumnNames);
            }

            //Create check statement per searched word
            foreach ($filter as $searchFor) {
                $whereArray[] = $columnsToSearch . $searchFor;
            }

            //Glue each searchable phrase with "and" to ensure that it contains all searched words
            $where = join(" and ", $whereArray);
        }

        if (!empty($request["start"])) {
            $start = $request["start"];
        } else {
            $start = 0;
        }

        if (!empty($request["length"])) {
            $length = $request["length"];
        } else {
            $length = 10;
        }

        return ["length" => $length, "start" => $start, "orderBy" => $order, "where" => $where];
    }

}