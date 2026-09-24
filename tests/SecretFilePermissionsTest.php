<?php
/* Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
require_once __DIR__ . '/../Tina4/DevAdmin.php';
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tina4\{Auth, DevAdmin, Router, Middleware, Request, Response};

class SecretFilePermissionsTest extends TestCase
{
    public static function cases(): array
    {
        $cases = [];
        foreach (['auth', 'grounding', 'connection'] as $writer) {
            foreach (['new', 'existing', 'symlink', 'hardlink'] as $kind) {
                $cases["$writer $kind"] = [$writer, $kind];
            }
        }
        return $cases;
    }

    #[DataProvider('cases')]
    public function testCredentialWriter(string $writer, string $kind): void
    {
        if (PHP_OS_FAMILY === 'Windows') $this->markTestSkipped('POSIX file permissions');
        $saved = getenv(); $savedEnv = $_ENV; $savedServer = $_SERVER;
        $cwd = getcwd();
        $dir = sys_get_temp_dir() . '/credential-files-' . bin2hex(random_bytes(8));
        mkdir($dir); chdir($dir);
        try {
            foreach (['TINA4_SECRET', 'TINA4_API_KEY', 'CI'] as $key) {
                putenv($key); unset($_ENV[$key], $_SERVER[$key]);
            }
            putenv('TINA4_DEBUG=true'); $_ENV['TINA4_DEBUG'] = 'true';
            putenv('TINA4_ENV=development'); $_ENV['TINA4_ENV'] = 'development';
            Router::clear(); Middleware::reset(); Tina4\Log::reset();
            putenv('TINA4_LOG_LEVEL=NONE'); $_ENV['TINA4_LOG_LEVEL'] = 'NONE';
            $path = $writer === 'auth' ? '.env.local' : '.env';
            file_put_contents('unrelated', "KEEP=original\n"); chmod('unrelated', 0644);
            if ($kind === 'existing') { file_put_contents($path, "KEEP=original\n"); chmod($path, 0644); }
            if ($kind === 'symlink') symlink('unrelated', $path);
            if ($kind === 'hardlink') link('unrelated', $path);
            if ($writer === 'auth') {
                $result = Auth::ensureDevSecret();
            } else {
                DevAdmin::register();
                $route = $writer === 'grounding' ? 'grounding/token' : 'connections/save';
                $data = $writer === 'grounding' ? ['token' => 'private-token'] :
                    ['url' => 'sqlite3:app.db', 'username' => 'user', 'password' => 'private-password'];
                $request = Request::create(method: 'POST', path: '/__dev/api/' . $route,
                    body: json_encode($data), headers: ['content-type' => 'application/json', 'sec-fetch-site' => 'same-origin'], remoteIp: '127.0.0.1');
                $response = Router::dispatch($request, new Response(true));
                $result = json_decode($response->getBody(), true);
            }
            clearstatcache();
            if (in_array($kind, ['symlink', 'hardlink'], true)) {
                $this->assertSame("KEEP=original\n", file_get_contents('unrelated'));
                $this->assertSame(0644, fileperms('unrelated') & 0777);
                if ($writer === 'auth') {
                    $this->assertSame(getenv('TINA4_SECRET'), $result);
                    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
                } else {
                    $this->assertFalse(($result[$writer === 'grounding' ? 'ok' : 'success'] ?? false) === true);
                }
            } else {
                $this->assertSame(0600, fileperms($path) & 0777);
                $content = file_get_contents($path);
                if ($kind === 'existing') $this->assertStringContainsString('KEEP=original', $content);
                $expected = $writer === 'auth' ? "TINA4_SECRET={$result}" : ($writer === 'grounding' ? 'TINA4_MCP_TOKEN=private-token' : 'TINA4_DATABASE_PASSWORD=private-password');
                $this->assertStringContainsString($expected, $content);
            }
        } finally {
            chdir($cwd); Router::clear(); Middleware::reset(); Tina4\ErrorTracker::reset(); Tina4\Log::reset();
            foreach (getenv() as $key => $_) if (!array_key_exists($key, $saved)) putenv($key);
            foreach ($saved as $key => $value) putenv("$key=$value");
            $_ENV = $savedEnv; $_SERVER = $savedServer;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                if ($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }
}
