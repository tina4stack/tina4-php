<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

class NoSQLParser
{
    /**
     * @param $sql
     * @return array
     * @tests sql
     *   assert("select id,test from tableName") === ["collectionName" => "tableName",  "columns" => ["id","test"], "filter" => []],"Testing to see if we can get fields and collection name"
     *   assert("select id,test from tableName where id = 1") === ["collectionName" => "tableName", "columns" => ["id","test"], "filter" => ['id' => ['$eq' => '1']]],"Testing to see if we can get fields and collection name"
     *   assert("select id,test from tableName where id = 1 and id > 0") === ["collectionName" => "tableName",  "columns" => ["id","test"],  "filter" => ['id' => ['$eq' => '1'], 'and' => ['id' => ['$gt' => '0']]] ],"Testing to see if we can get fields and collection name"
     */
    public function parseSQLToNoSQL($sql): array
    {
        $sql = str_replace("\n", " ", $sql);
        $sql = str_replace("\r", " ", $sql);

        $comparisonOperators = ['=' => '$eq', 'is' => '$eq', '>' => '$gt', '>=' => '$gte', '<' => '$lt', '<=' => '$lte', '<>' => '$ne', 'not in' => '$nin', 'in' => '$in'];

        $logicalOperators = ['and' => '$and', 'or' => '$or', 'not' => '$not'];

        $insert = '/(insert into)(.*)\((.*)\)(.*)(values)(.*)/m';

        $update = '/(update)(.*)(set)(.*)(where)(.*)/m';

        $plain = '/(select)(.*)(from)(.*)/m';

        $normal = '/(select)(.*)(from)(.*)(where)(.*)/m';

        $withOrderBy = '/(select)(.*)(from)(.*)(where)(.*)(order\ by)(.*)/m';

        if (stripos($sql, "insert") !== false) {
            preg_match_all($insert, $sql, $matches, PREG_SET_ORDER, 0);
        } else
            if (stripos($sql, "update") !== false) {
                preg_match_all($update, $sql, $matches, PREG_SET_ORDER, 0);
            }
            else
                if (stripos($sql, "where") === false) {
                    preg_match_all($plain, $sql, $matches, PREG_SET_ORDER, 0);
                }
                else
                    if (stripos($sql, "order by") !== false) {
                        preg_match_all($withOrderBy, $sql, $matches, PREG_SET_ORDER, 0);
                    } else {
                        preg_match_all($normal, $sql, $matches, PREG_SET_ORDER, 0);
                    }

        if (stripos($sql, "insert") !== false) {
            $columns = [];

            $tempColumns = explode(",", trim($matches[0][3]));

            foreach ($tempColumns as $id => $column) {
                array_push($columns, trim($column));
            }

            $filter = "";

            $collectionName = trim($matches[0][2]);
        }
        else
            if (stripos($sql, "update") === false) {
                $columns = [];

                $tempColumns = explode(",", trim($matches[0][2]));

                foreach ($tempColumns as $id => $column) {
                    $part = explode("as", $column);

                    array_push($columns, trim($part[0]));
                }

                $collectionName = trim($matches[0][4]);

                $filter = "";

                if (count($matches[0]) > 6) {
                    $filter = $matches[0][6];
                }
            } else {
                $columns = [];

                $tempColumns = explode(",", trim($matches[0][4]));

                foreach ($tempColumns as $id => $column) {
                    $part = explode("=", $column);

                    array_push($columns, trim($part[0]));
                }

                $collectionName = trim($matches[0][2]);

                if (count($matches[0]) > 6) {
                    $filter = $matches[0][6];
                }
            }

        $filters = [];
        //extract each of the operators

        $expressions = explode (" ", trim($filter));

        if (!empty($expressions) && count($expressions) > 0) {
            $tempArray = [];

            $lastOperator = "";

            foreach ($expressions as $id => $expression) {
                $tempArray[] = $expression;
                if (array_key_exists($expression, $logicalOperators)) {
                    if (!empty($lastOperator)) {
                        $tempArray[2] = str_replace("'", "", $tempArray[2]);
                        $filters[$lastOperator][$tempArray[0]][$comparisonOperators[$tempArray[1]]] = is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                    } else {
                        $tempArray[2] = str_replace("'", "", $tempArray[2]);
                        $filters[$tempArray[0]][$comparisonOperators[$tempArray[1]]] =  is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                    }

                    $lastOperator = $expression;

                    $tempArray = [];
                }
            }

            if (!empty($tempArray) && count($tempArray) > 1) {
                if (!empty($lastOperator)) {
                    $tempArray[2] = str_replace("'", "", $tempArray[2]);
                    $filters[$lastOperator][$tempArray[0]][$comparisonOperators[$tempArray[1]]] = is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                } else {
                    $tempArray[2] = str_replace("'", "", $tempArray[2]);
                    $filters[$tempArray[0]][$comparisonOperators[$tempArray[1]]] =  is_numeric(trim($tempArray[2]) * 1.00) ?  trim($tempArray[2]) * 1.00 : trim($tempArray[2]);
                }
            }

        }

        return ["collectionName" => $collectionName, "columns" => $columns, "filter" => $filters];
    }
}