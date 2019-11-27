<?php
//Get already defined database connections
global $DBA;

$sql = "select * from example";

//fetch() accepts 3 parameters: SQLQuery, noOfRecords and offSet
//records() works with fetch to convert the array of data results into an array of objects
$data = $DBA->fetch($sql, 1000)->records();