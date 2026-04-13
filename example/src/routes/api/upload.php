<?php

define('UPLOAD_DIR', __DIR__ . '/../../public/uploads');
define('ALLOWED_UPLOAD_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml']);

\Tina4\Router::post("/api/upload/product-image", function ($request, $response) {
    $file = $request->files['image'] ?? null;
    if (!$file) {
        return $response(["error" => "No file provided"], 400);
    }

    if (!in_array($file['type'] ?? '', ALLOWED_UPLOAD_TYPES)) {
        return $response(["error" => "Invalid file type: " . ($file['type'] ?? 'unknown')], 400);
    }

    $original = $file['filename'] ?? 'image.jpg';
    $ext = pathinfo($original, PATHINFO_EXTENSION) ?: 'jpg';
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    file_put_contents(UPLOAD_DIR . '/' . $filename, $file['content']);

    return $response(["url" => "/uploads/{$filename}", "filename" => $filename]);
})->noAuth()->middleware(["adminAuth"]);
