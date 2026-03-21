<?php
/**
 * Gallery: Templates — render an HTML page with dynamic data via Twig.
 */

\Tina4\Router::get('/gallery/page', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->template('gallery_page.twig', [
        'title' => 'Gallery Demo Page',
        'items' => [
            ['name' => 'Tina4 PHP', 'description' => 'Zero-dep web framework', 'badge' => 'v3.0.0'],
            ['name' => 'Twig Engine', 'description' => 'Built-in template rendering', 'badge' => 'included'],
            ['name' => 'Auto-Reload', 'description' => 'Templates refresh on save', 'badge' => 'dev mode'],
        ],
    ]);
});
