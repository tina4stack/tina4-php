<?php

/***
 * Class TestService
 * Example of a test service, see serviceExample how to register this class with the running service
 */
class TestService extends \Tina4\Process {

    function run () {

        echo memory_get_usage()."\n";
        //fetch queue ?

        echo "OK!\n";
        echo "No memory leaks!";
       // echo "Well except for when the program changes!";
       //
    }

    function canRun()
    {
       //if it is this time - return true
        return true;
    }
}