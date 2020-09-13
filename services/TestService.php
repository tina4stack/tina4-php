<?php


class TestService extends \Tina4\Process {
    function run () {
        echo "OK again!";
        throw new Exception("Yeah!");
    }
}