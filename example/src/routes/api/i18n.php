<?php

\Tina4\Router::get("/api/locale/{lang}", function ($request, $response) {
    $lang = $request->params['lang'] ?? 'en';
    $request->session->set("locale", $lang);
    $referer = $request->headers['referer'] ?? '/';
    return $response->redirect($referer);
})->noAuth();
