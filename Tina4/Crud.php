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
        if (!empty($request["search"])) {
            $search = $request["search"];

            foreach ($columns as $id => $column) {
                $columnName = $ORM->getFieldName($column["data"]);

                if (($column["searchable"] == "true") && !empty($search["value"])) {
                    $filter[] = "upper(" . $columnName . ") like '%" . strtoupper($search["value"]) . "%'";
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
        if (is_array($filter) && count($filter) > 0) {
            $where = "(" . join(" or ", $filter) . ")";
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
     * @param $secures
     */
    public static function route ($path, \Tina4\ORM $object, $function, $secure=false) {
        //What if the path has ids in it ? /store/{id}/{hash}

        //CREATE
        \Tina4\Route::get($path."/form",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($function) {
                $htmlResult = $function ("form", null, null, $request);
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
                $object->save();
                $jsonResult = $function ("afterCreate", $object, null, $request);
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
                $jsonResult = $function ("fetch", (new $object())->load("id = {$id}"), null, $request);
                if (empty($jsonResult)) {
                    $jsonResult = (new $object())->load("id = {$id}");
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
                $object->load ("id = {$id}");
                $jsonResult = $function ("update", $object, null, $request);
                $object->save();
                $jsonResult = $function ("afterUpdate", $object, null, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        //DELETE
        \Tina4\Route::delete ( $path."/{id}",
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $id = $request->inlineParams[count($request->inlineParams)-1]; //get the id on the last param
                $object->create($request->params);
                $object->load ("id = {$id}");
                $jsonResult = $function ("delete", $object, null, $request);
                $object->delete();
                $jsonResult = $function ("afterDelete", $object, null, $request);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

    }

}