<?php

\Tina4\Get::add("/service/create", function (\Tina4\Response $response) {

    $tina4Process = new TestService("test");

    (new \Tina4\Service())->addProcess($tina4Process);
});