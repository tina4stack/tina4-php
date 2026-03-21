<?php
/**
 * Gallery: Error Overlay — deliberately crash to demo the debug overlay.
 *
 * In debug mode (TINA4_DEBUG=true), you will see:
 * - Exception type and message
 * - Stack trace with syntax-highlighted source code
 * - The exact line that caused the error (highlighted)
 * - Request details (method, path, headers)
 * - Environment info (framework version, PHP version)
 */

\Tina4\Router::get('/api/gallery/crash', function (\Tina4\Request $request, \Tina4\Response $response) {
    // Simulate a realistic error — accessing a missing key
    $user = ['name' => 'Alice', 'email' => 'alice@example.com'];
    $role = $user['role']; // Undefined array key "role" — this line will be highlighted in the overlay
    return $response->json(['role' => $role]);
});
