<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Scaffolding-first acceptance matrix — REAL boot-gate, NO mocks.
 *
 * Each generator is exercised through the REAL CLI (shelled out) then the
 * generated files are required + run against the REAL framework: real Router,
 * real Auth/JWT gate, real in-memory SQLite, real ServiceRunner / Queue / Events
 * bus. Route/CRUD writes are proven secure-by-default by BEHAVIOUR
 * (anon write -> 401, authed write -> 201, anon read -> 200); the six logic
 * generators are proven to load cleanly, throw NotImplemented when their handler
 * is CALLED, carry the AI-FILL banner, and register on the real subsystem.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Database\SQLite3Adapter;
use Tina4\Events;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\ServiceRunner;

class CLIScaffoldingSecureTest extends TestCase
{
    private const SECRET = 'scaffold-test-secret';
    private static string $bin;
    private string $origCwd;
    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
    }

    protected function setUp(): void
    {
        $this->origCwd = getcwd();
        $this->tempDir = sys_get_temp_dir() . '/tina4-secure-' . getmypid() . '-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);

        Router::clear();
        Events::clear();
        ServiceRunner::clear();
        putenv('TINA4_SECRET=' . self::SECRET);
        $_ENV['TINA4_SECRET'] = self::SECRET;
        putenv('SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Events::clear();
        ServiceRunner::clear();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        putenv('SECRET');
        chdir($this->origCwd);
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function gen(string $args): string
    {
        return (string)shell_exec('php ' . escapeshellarg(self::$bin) . ' generate ' . $args . ' 2>&1');
    }

    private function file(string $rel): string
    {
        return $this->tempDir . '/' . $rel;
    }

    private function src(string $rel): string
    {
        return file_get_contents($this->file($rel));
    }

    private function lintOk(string $rel): bool
    {
        $out = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($this->file($rel)) . ' 2>&1', $out, $code);
        return $code === 0;
    }

    private function bindDb(): SQLite3Adapter
    {
        $db = new SQLite3Adapter(':memory:');
        ORM::bindDatabase($db);
        return $db;
    }

    private function dispatch(string $method, string $path, array $body = [], ?string $token = null): int
    {
        $headers = ['content-type' => 'application/json'];
        if ($token !== null) {
            $headers['authorization'] = "Bearer {$token}";
        }
        $req = Request::create(method: $method, path: $path, body: $body, headers: $headers);
        return Router::dispatch($req, new Response(true))->getStatusCode();
    }

    private function token(): string
    {
        return Auth::getToken(['user_id' => 1], self::SECRET);
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Route: secure-by-default BEHAVIOUR (R1–R4) ─────────────────

    public function testModelRouteSecureByDefaultBootGate(): void
    {
        $this->gen('model Sprocket --fields "name:string,qty:int" --no-migration');
        $this->gen('route sprockets --model Sprocket');
        require $this->file('src/orm/Sprocket.php');
        $this->bindDb();
        (new \Sprocket())->createTable();
        require $this->file('src/routes/sprockets.php');

        // R1 — all five routes registered.
        $paths = array_map(fn($r) => $r['method'] . ' ' . $r['path'], Router::getRoutes());
        $this->assertContains('GET /sprockets', $paths);
        $this->assertContains('GET /sprockets/{id}', $paths);
        $this->assertContains('POST /sprockets', $paths);
        $this->assertContains('PUT /sprockets/{id}', $paths);
        $this->assertContains('DELETE /sprockets/{id}', $paths);

        // R2 — secure by default, proven by behaviour.
        $this->assertSame(200, $this->dispatch('GET', '/sprockets'), 'read is public');
        $this->assertSame(401, $this->dispatch('POST', '/sprockets', ['name' => 'a']), 'write gated');
        $this->assertSame(201, $this->dispatch('POST', '/sprockets', ['name' => 'a'], $this->token()), 'token -> 201');
    }

    public function testModelRouteSourceHasNoNoAuthByDefault(): void
    {
        // R4 — no ->noAuth() anywhere; secure by default.
        $this->gen('model Cog --fields "name:string" --no-migration');
        $this->gen('route cogs --model Cog');
        $src = $this->src('src/routes/cogs.php');
        $this->assertStringNotContainsString('->noAuth()', $src);
        $this->assertStringContainsString('\\Tina4\\Router::', $src, 'uses the real fully-qualified Router');
    }

    public function testPublicRouteOpensWrites(): void
    {
        $this->gen('model Widget --fields "name:string" --no-migration');
        $this->gen('route widgets --model Widget --public');
        require $this->file('src/orm/Widget.php');
        $this->bindDb();
        (new \Widget())->createTable();
        require $this->file('src/routes/widgets.php');

        // R3 — --public opened the writes: anonymous POST creates.
        $this->assertSame(201, $this->dispatch('POST', '/widgets', ['name' => 'a']));
        // DELETE is open too (gate passed -> handler runs -> 404 for the missing row).
        $this->assertContains($this->dispatch('DELETE', '/widgets/999'), [204, 404]);
    }

    public function testPublicRouteSourceHasNoAuthOnWritesOnly(): void
    {
        // R4 (--public) — ->noAuth() on the 3 writes, never on the 2 GETs.
        $this->gen('model Gadget --fields "name:string" --no-migration');
        $this->gen('route gadgets --model Gadget --public');
        $src = $this->src('src/routes/gadgets.php');
        $this->assertSame(3, substr_count($src, '->noAuth()'), 'create + update + delete');
        // ->noAuth() closes each WRITE registration, and neither READ.
        $this->assertStringContainsString('\\Tina4\\Router::post("/gadgets", ', $src);
        $this->assertMatchesRegularExpression('/Router::post\("\/gadgets".*?\}\)->noAuth\(\);/s', $src);
        $this->assertMatchesRegularExpression('/Router::put\("\/gadgets\/\{id\}".*?\}\)->noAuth\(\);/s', $src);
        $this->assertMatchesRegularExpression('/Router::delete\("\/gadgets\/\{id\}".*?\}\)->noAuth\(\);/s', $src);
        // GET registrations end plainly, never ->noAuth().
        $this->assertMatchesRegularExpression('/Router::get\("\/gadgets".*?\}\);/s', $src);
        $this->assertDoesNotMatchRegularExpression('/Router::get\([^;]*?\}\)->noAuth\(\);/s', $src);
    }

    public function testNoModelRouteIsSecureLiveStub(): void
    {
        // No --model -> a custom route: AI-FILL stubs, secure by default.
        $this->gen('route notes');
        $path = $this->file('src/routes/notes.php');
        $this->assertTrue($this->lintOk('src/routes/notes.php'), 'B2 lint');
        require $path;

        // R1 — five routes registered.
        $noteRoutes = array_filter(Router::getRoutes(), fn($r) => str_contains($r['path'], '/notes'));
        $this->assertCount(5, $noteRoutes);

        // S2 — AI-FILL banner + Ground present; no ->noAuth() (secure default).
        $src = $this->src('src/routes/notes.php');
        $this->assertStringContainsString('AI-FILL', $src);
        $this->assertStringContainsString('// Ground:', $src);
        $this->assertStringNotContainsString('->noAuth()', $src);

        // Gate holds even though the handler is a stub (gate runs before it).
        $this->assertSame(401, $this->dispatch('POST', '/notes', []));

        // S1 — invoking the GET handler raises RuntimeException (placeholder live).
        $listRoute = null;
        foreach (Router::getRoutes() as $r) {
            if ($r['method'] === 'GET' && $r['path'] === '/notes') {
                $listRoute = $r;
            }
        }
        $this->assertNotNull($listRoute);
        $callback = $listRoute['callback'];
        $this->expectException(\RuntimeException::class);
        $callback(Request::create(method: 'GET', path: '/notes', headers: []), new Response(true));
    }

    // ── CRUD: secure default + generated test itself PASSES (R5) ───

    public function testCrudDefaultRoutesSecure(): void
    {
        $this->gen('crud Doohickey --fields "name:string"');
        $this->assertStringNotContainsString('->noAuth()', $this->src('src/routes/doohickeys.php'));
    }

    public function testCrudPublicRoutesOpenWrites(): void
    {
        $this->gen('crud Contraption --fields "name:string" --public');
        $this->assertSame(3, substr_count($this->src('src/routes/contraptions.php'), '->noAuth()'));
    }

    public function testGeneratedCrudTestRunsGreen(): void
    {
        // R5 — the emitted CRUD test file executes green in a REAL phpunit
        // subprocess (Tina4 autoloaded via the framework bootstrap), booting the
        // generated model + route and proving the gate (401/201/200) end-to-end.
        $this->gen('crud Trinket --fields "name:string,qty:int"');
        $testFile = $this->file('tests/TrinketsTest.php');
        $this->assertFileExists($testFile);

        $phpunit = realpath(__DIR__ . '/../vendor/bin/phpunit');
        $bootstrap = realpath(__DIR__ . '/bootstrap.php');
        $cmd = escapeshellarg($phpunit)
            . ' --no-configuration --bootstrap ' . escapeshellarg($bootstrap)
            . ' ' . escapeshellarg($testFile) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    // ── The six logic generators (B1–B4 + S1–S3) ──────────────────

    public function testServiceGenerator(): void
    {
        $this->gen('service Reaper --every 5m');
        $rel = 'src/services/reaper.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $desc = require $this->file($rel);                   // B3 — loads clean

        // B4 — second run refuses to overwrite.
        $this->assertStringContainsString('already exists', $this->gen('service Reaper --every 5m'));

        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);

        $this->assertSame('reaper', $desc['name']);
        $this->assertSame('*/5 * * * *', $desc['timing']);   // everyToCron
        // S1 — the task body throws until filled.
        $threw = false;
        try {
            ($desc['handler'])(null);
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'service handler must throw NotImplemented');

        // S3 — discoverable / registrable on the REAL ServiceRunner.
        $discovered = ServiceRunner::discover($this->file('src/services'));
        $this->assertContains('reaper', $discovered);
        $this->assertArrayHasKey('reaper', ServiceRunner::list());
    }

    public function testCronServiceAndEveryMappings(): void
    {
        $this->gen('service Nightly --cron "0 3 * * *"');
        $this->assertSame('0 3 * * *', (require $this->file('src/services/nightly.php'))['timing']);

        // everyToCron: cron-only in PHP (no seconds interval) — 2h/1d/30s.
        $this->gen('service Hourly --every 2h');
        $this->assertSame('0 */2 * * *', (require $this->file('src/services/hourly.php'))['timing']);
        $this->gen('service Daily --every 1d');
        $this->assertSame('0 0 * * *', (require $this->file('src/services/daily.php'))['timing']);
        $this->gen('service Ticker --every 30s');
        $this->assertSame('* * * * *', (require $this->file('src/services/ticker.php'))['timing']);
    }

    public function testQueueGenerator(): void
    {
        $this->gen('queue order-emails');
        $rel = 'src/services/order_emails_consumer.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $desc = require $this->file($rel);                   // B3

        $this->assertStringContainsString('already exists', $this->gen('queue order-emails')); // B4
        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);

        // S3 — consumer wired as a daemon service; producer real.
        $this->assertTrue($desc['daemon']);
        $this->assertSame('order-emails-consumer', $desc['name']);
        $jobId = ($desc['publish'])(['to' => 'a@b.c']);      // real file-backend push
        $this->assertNotEmpty($jobId);

        // S1 — the per-job handler throws until filled.
        $threw = false;
        try {
            ($desc['handle'])([]);
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw);

        $this->assertContains('order-emails-consumer', ServiceRunner::discover($this->file('src/services')));
    }

    public function testValidatorGenerator(): void
    {
        $this->gen('validator CreateUser');
        $rel = 'src/validators/create_user.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $validate = require $this->file($rel);               // B3 — loads clean

        $this->assertStringContainsString('already exists', $this->gen('validator CreateUser')); // B4
        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);
        $this->assertStringContainsString('new \\Tina4\\Validator', $src); // S3 wiring

        $this->expectException(\RuntimeException::class);     // S1
        $validate([]);
    }

    public function testSeederGenerator(): void
    {
        $this->gen('model Bolt --fields "name:string" --no-migration');
        $this->gen('seeder Bolt');
        $rel = 'src/seeds/bolt_seeder.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $seed = require $this->file($rel);                   // B3 — loads clean (resolves Bolt)

        $this->assertStringContainsString('already exists', $this->gen('seeder Bolt')); // B4
        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);
        $this->assertStringContainsString('new \\Tina4\\FakeData', $src);    // S3 wiring
        $this->assertStringContainsString('\\Tina4\\SeedSummary', $src);

        $this->expectException(\RuntimeException::class);     // S1 — fails loud when run
        $seed();
    }

    public function testWebsocketGenerator(): void
    {
        $this->gen('websocket chat');
        $rel = 'src/routes/ws_chat.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $handler = require $this->file($rel);                // B3

        $this->assertStringContainsString('already exists', $this->gen('websocket chat')); // B4
        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);

        // S3 — the ws handler registered on the real Router.
        $wsPaths = array_map(fn($r) => $r['path'], Router::getWebSocketRoutes());
        $this->assertContains('/ws/chat', $wsPaths);

        // S1 — the "message" body throws until filled ($connection, $data, $event).
        $this->expectException(\RuntimeException::class);
        $handler(null, 'hi', 'message');
    }

    public function testListenerGenerator(): void
    {
        $this->gen('listener user.created');
        $rel = 'src/listeners/user_created.php';
        $this->assertFileExists($this->file($rel));          // B1
        $this->assertTrue($this->lintOk($rel));              // B2
        $listener = require $this->file($rel);               // B3

        $this->assertStringContainsString('already exists', $this->gen('listener user.created')); // B4
        $src = $this->src($rel);
        $this->assertStringContainsString('AI-FILL', $src);  // S2
        $this->assertStringContainsString('// Ground:', $src);

        // S3 — the listener bound on the real event bus.
        $this->assertGreaterThanOrEqual(1, count(Events::listeners('user.created')));

        // S1 — the reaction body throws until filled.
        $this->expectException(\RuntimeException::class);
        $listener(['id' => 1]);
    }

    // ── Regression guard (A1): auth generator NOT over-swept ───────

    public function testAuthRoutesStillPublicAndBoot(): void
    {
        $this->gen('auth');
        $src = $this->src('src/routes/auth.php');
        // A1 — the secure-by-default fix must NOT strip public access from the
        // genuinely-public login/register routes.
        $this->assertGreaterThanOrEqual(2, substr_count($src, '->noAuth()'));
        $this->assertStringContainsString('\\Tina4\\Router::post("/api/auth/register"', $src);

        // Boots for real: register is a PUBLIC write (noAuth flag true), not gated.
        require $this->file('src/orm/User.php');
        $this->bindDb();
        (new \User())->createTable();
        require $this->file('src/routes/auth.php');
        $match = Router::match('POST', '/api/auth/register');
        $this->assertNotNull($match);
        $this->assertTrue($match['route']['noAuth'], 'register must be public');
    }

    // ── Robustness (N1) + every generated file lints ───────────────

    public function testInvalidFieldsNoStacktrace(): void
    {
        $out = $this->gen('model Junk --fields ":::,,,bad" --no-migration');
        $this->assertFileExists($this->file('src/orm/Junk.php'));
        $this->assertStringNotContainsString('Stack trace', $out);
    }

    public function testAllGeneratedFilesLintClean(): void
    {
        $this->gen('model Gizmo --fields "name:string,price:float" --no-migration');
        $this->gen('route gizmos --model Gizmo');
        $this->gen('route freeform');
        $this->gen('service Sweeper --every 10m');
        $this->gen('queue invoices');
        $this->gen('validator UpdateOrder');
        $this->gen('seeder Gizmo');
        $this->gen('websocket rooms');
        $this->gen('listener order.shipped');

        $failed = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempDir));
        foreach ($rii as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $rel = substr($f->getPathname(), strlen($this->tempDir) + 1);
                if (!$this->lintOk($rel)) {
                    $failed[] = $rel;
                }
            }
        }
        $this->assertSame([], $failed, 'every generated PHP file must pass php -l');
    }
}
