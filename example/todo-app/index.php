<?php
require_once "./vendor/autoload.php";

global $DBA;
$DBA = new \Tina4\DataSQLite3("todos.db");

echo new \Tina4\Tina4Php();
