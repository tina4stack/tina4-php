<?php
//Get already defined database connections
global $DBA;

$sql = "select * from example";

//fetch() accepts 3 parameters: SQLQuery, noOfRecords and offSet
$data = $DBA->fetch($sql, 1000);