<?php
/**
 * @secure
 */

\Tina4\Route::get("/test/sub", function (\Tina4\Response $response) {
    return $response("Working!");
});