<?php

/**
 * @description TESTING
 */
\Tina4\Get::add("/service/create/{test}", function ( $test, \Tina4\Response $response) {

    $tina4Process = new TestService($test);

    (new \Tina4\Service())->addProcess($tina4Process);
});