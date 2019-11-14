<?php
define("YOUR_DATABASE", "example-mysql:exampleschema");
define("DATABASE_USER", "User1");
define("DATABASE_PASS", "Pass1234");

//database connection
global $DBA;

$DBA = new \Tina4\DataMySQL(YOUR_DATABASE, DATABASE_USER, DATABASE_PASS);