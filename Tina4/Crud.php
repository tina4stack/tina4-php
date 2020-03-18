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
}