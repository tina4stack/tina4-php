<?php
const TINA4_DEBUG = true;
const TINA4_DEBUG_LEVEL = ["all"];
require_once "vendor/autoload.php";


echo SWAGGER_DESCRIPTION;

//throw new Exception("Test");

echo \Tina4\DebugRender::render();