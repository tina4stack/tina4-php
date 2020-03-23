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
     */
    public static function route ($path, \Tina4\ORM $object, $function) {
        //CREATE
        \Tina4\Get::add($path."/form",
            function (\Tina4\Response $response) use ($function) {
                $htmlResult = $function ("form", null, null);
                return $response ($htmlResult, HTTP_OK, TEXT_HTML);
            }
        );

        \Tina4\Post::add( $path,
            function (\Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $object->create($request->params);
                $htmlResult = $function ("create", $object, null);
                $object->save();
                return $response ($htmlResult, HTTP_OK, TEXT_HTML);
            }
        );

        //READ
        \Tina4\Get::add($path."/data",
            function (\Tina4\Response $response) use ($object, $function) {
                $filter = \Tina4\Crud::getDataTablesFilter();
                $jsonResult = $function ("read", new $object(), $filter);
                return $response ($jsonResult, HTTP_OK, APPLICATION_JSON);
            }
        );

        //UPDATE
        \Tina4\Get::add($path."/{id}",
            function ($id, \Tina4\Response $response) use ($object, $function) {
                $htmlResult = $function ("form", (new $object())->load("id = {$id}"), null);
                return $response ($htmlResult, HTTP_OK, TEXT_HTML);
            }
        );

        \Tina4\Post::add( $path."/{id}",
            function ($id, \Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $object->create($request->params);
                $object->load ("id = {$id}");
                $htmlResult = $function ("update", $object, null);
                $object->save();
                return $response ($htmlResult, HTTP_OK, TEXT_HTML);
            }
        );

        //DELETE
        \Tina4\Delete::add ( $path."/{id}",
            function ( $id, \Tina4\Response $response, \Tina4\Request $request) use ($object, $function) {
                $object->create($request->params);
                $object->load ("id = {$id}");
                $htmlResult = $function ("delete", $object, null);
                $object->delete();
                return $response ($htmlResult);
            }
        );

    }

}