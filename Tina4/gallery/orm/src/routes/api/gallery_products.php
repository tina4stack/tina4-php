<?php
/**
 * Gallery: ORM — Product CRUD endpoints.
 */

\Tina4\Router::get('/api/gallery/products', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'products' => [
            ['id' => 1, 'name' => 'Widget', 'price' => 9.99],
            ['id' => 2, 'name' => 'Gadget', 'price' => 24.99],
        ],
        'note' => 'Connect a database and deploy the ORM model for live data',
    ]);
});

\Tina4\Router::post('/api/gallery/products', function (\Tina4\Request $request, \Tina4\Response $response) {
    $data = $request->body ?? [];
    return $response->json(['created' => $data, 'id' => 3], 201);
});
