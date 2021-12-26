<?php
require_once "vendor/autoload.php";

$events = [];
$events["connect"] = function() {
    echo "connected";
};

$events["receive"] = function($server, $fd, $reactor_id, $data) {
    echo "received {$data}";
    $server->close($fd);
};

$events["close"] = function() {
    echo "closed";
};

$swoole = new \Tina4\Swoole(40000, $events, "tcp");
$swoole->addListener(40001, $events);
$swoole->start();
