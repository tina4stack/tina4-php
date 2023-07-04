<?php

\Tina4\Get::add("/test", function(\Tina4\Response $response){

    \Tina4\Event::trigger("me", ["Again", 1, "Moo!"]);

    return $response("OK!");
});

\Tina4\Get::add("/test/slow", function(\Tina4\Response $response){

    \Tina4\Event::trigger("me", ["Hello", 3]);

    return $response("OK!");
});