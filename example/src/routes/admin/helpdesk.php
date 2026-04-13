<?php

\Tina4\Router::get("/admin/helpdesk", function ($request, $response) {
    return $response(storeRender("admin/helpdesk.twig", [], $request));
})->middleware(["adminAuth"]);
