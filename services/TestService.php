<?php

/***
 * Class TestService
 * Example of a test service, see serviceExample how to register this class with the running service
 */
class TestService extends \Tina4\Process {

    public function run (): void
    {

        echo memory_get_usage()."\n";
        //fetch queue ?

        echo "OK! AA ADD STUFF\n";
        echo "No memory leaks!";
       // echo "Well except for when the program changes!";
       //
    }

    public function canRun(): bool
    {
       //if it is this time - return true
        return true;
    }
}