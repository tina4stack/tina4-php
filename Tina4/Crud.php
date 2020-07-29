<?php
namespace Tina4;


class Crud
{
    /**
     * Generates a form for use in CRUD
     * @param int $columns
     * @param null $ignoreFields
     * @param string $namePrefix
     * @param string $groupClass
     * @param string $inputClass
     * @return string
     */
    public function generateForm($columns=1, $ignoreFields=null, $namePrefix="", $groupClass="form-group", $inputClass="form-control") {
        $html = "coming";

        return $html;
    }

    /**
     * Returns an array of dataTables style filters for use in your queries
     * @return array
     * @throws \Exception
     */
    public static function getDataTablesFilter() {
        $ORM = new \Tina4\ORM();
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
                            $filter[] = " like '%" . strtoupper($singleValue) . "%'";
                        }
                    }
                }
            }
        }

        $ordering = null;
        if (!empty($orderBy)) {
            foreach ($orderBy as $id => $orderEntry) {
                $columnName = $ORM->getFieldName($columns[$orderEntry["column"]]["data"]);
                $ordering[] = $columnName. " " . $orderEntry["dir"];
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

    /**
     * CRUD ROUTING FOR DATATABLES
     * @param $path
     * @param ORM $object
     * @param $function
     * @params $secure
     * @param $secures
     */
    public static function route ($path, \Tina4\ORM $object, $function, $secure=false) {
        //What if the path has ids in it ? /store/{id}/{hash}

        //CREATE
        \Tina4\Route::get($path."/form",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $htmlResult = $function ("form", $object, null, $request);
                return $response ($htmlResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        \Tina4\Route::post( $path,
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                if (!empty($request->data)) {
                    $object->create($request->data);
                } else {
                    $object->create($request->params);
                }
                $jsonResult = $function ("create", $object, null, $request);
                $function ("beforeCreate", $object, null, $request);
                $object->save();
                $function ("afterCreate", $object, null, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        //READ
        \Tina4\Route::get($path,
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $filter = \Tina4\Crud::getDataTablesFilter();
                $jsonResult = $function ("read", new $object(), $filter, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );


        //UPDATE
        \Tina4\Route::get($path."/{id}",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams)-1]; //get the id on the last param

                $jsonResult = $function ("fetch", (new $object())->load("{$object->getFieldName($object->primaryKey)} = '{$id}'"), null, $request);
                if (empty($jsonResult)) {
                    $jsonResult = (new $object())->load("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                }

                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        \Tina4\Route::post( $path."/{id}",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams)-1]; //get the id on the last param
                if (!empty($request->data)) {
                    $object->create($request->data);
                } else {
                    $object->create($request->params);
                }
                $object->load ("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                $jsonResult = $function ("update", $object, null, $request);
                $function ("beforeUpdate", $object, null, $request);
                $object->save();
                $function ("afterUpdate", $object, null, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        //DELETE
        \Tina4\Route::delete ( $path."/{id}",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams)-1]; //get the id on the last param
                $object->create($request->params);
                $object->load ("{$object->getFieldName($object->primaryKey)} = '{$id}'");
                $jsonResult = $function ("delete", $object, null, $request);
                $function ("beforeDelete", $object, null, $request);
                if (!$object->softDelete) {
                    $object->delete();
                }
                $function ("afterDelete", $object, null, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

    }

}