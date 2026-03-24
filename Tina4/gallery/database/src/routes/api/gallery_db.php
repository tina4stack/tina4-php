<?php
/**
 * Gallery: Database — raw SQL query demo.
 */

\Tina4\Router::get('/api/gallery/db/tables', function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $db = \Tina4\Database\Database::create('sqlite:///data/gallery.db');
        $db->exec("CREATE TABLE IF NOT EXISTS gallery_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->commit();
        $tables = $db->getTables();
        return $response->json(['tables' => $tables, 'engine' => 'sqlite']);
    } catch (\Throwable $e) {
        return $response->json(['error' => $e->getMessage()], 500);
    }
});

\Tina4\Router::post('/api/gallery/db/notes', function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $body = $request->body ?? [];
        $db = \Tina4\Database\Database::create('sqlite:///data/gallery.db');
        $title = $body['title'] ?? 'Untitled';
        $bodyText = $body['body'] ?? '';
        $db->exec("INSERT INTO gallery_notes (title, body) VALUES (?, ?)", [$title, $bodyText]);
        $db->commit();
        return $response->json(['created' => true], 201);
    } catch (\Throwable $e) {
        return $response->json(['error' => $e->getMessage()], 500);
    }
});

\Tina4\Router::get('/api/gallery/db/notes', function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $db = \Tina4\Database\Database::create('sqlite:///data/gallery.db');
        $result = $db->fetch("SELECT * FROM gallery_notes ORDER BY id DESC", [], 50);
        return $response->json($result);
    } catch (\Throwable $e) {
        return $response->json(['error' => $e->getMessage()], 500);
    }
});
