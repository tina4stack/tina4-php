<?php

require_once "./vendor/autoload.php";

$deploy = new \Tina4\GitDeploy();
$deploy->doDeploy();

