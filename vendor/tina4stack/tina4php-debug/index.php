<?php


const TINA4_DEBUG = true;


require_once "vendor/autoload.php";


\Tina4\Debug::$logLevel = ["all"];



$test / 10;

$me = 10;

$me / 0;

\Tina4\Debug::message("Testing", TINA4_LOG_DEBUG);
\Tina4\Debug::message("Testing", TINA4_LOG_WARNING);
\Tina4\Debug::message("Testing", TINA4_LOG_ERROR);
\Tina4\Debug::message("Testing", TINA4_LOG_INFO);
\Tina4\Debug::message("Testing", TINA4_LOG_CRITICAL);

throw new Exception("Hello", 1111);


