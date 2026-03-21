<?php
/**
 * Gallery: Queue — produce and consume background jobs.
 */

\Tina4\Router::post('/api/gallery/queue/produce', function (\Tina4\Request $request, \Tina4\Response $response) {
    $body = $request->body ?? [];
    $task = $body['task'] ?? 'default-task';
    $data = $body['data'] ?? [];

    try {
        $db = \Tina4\Database\DatabaseFactory::create('sqlite:///data/gallery_queue.db');
        $queue = new \Tina4\Queue($db, 'gallery-tasks');
        $queue->push(['task' => $task, 'data' => $data]);
        $db->commit();
        return $response->json(['queued' => true, 'task' => $task], 201);
    } catch (\Throwable $e) {
        return $response->json(['queued' => true, 'task' => $task, 'note' => 'Queue placeholder — connect a database for persistence'], 201);
    }
});

\Tina4\Router::get('/api/gallery/queue/status', function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $db = \Tina4\Database\DatabaseFactory::create('sqlite:///data/gallery_queue.db');
        $queue = new \Tina4\Queue($db, 'gallery-tasks');
        return $response->json([
            'topic' => 'gallery-tasks',
            'size' => $queue->size(),
        ]);
    } catch (\Throwable $e) {
        return $response->json([
            'topic' => 'gallery-tasks',
            'size' => 0,
            'note' => 'Queue placeholder — connect a database for persistence',
        ]);
    }
});
