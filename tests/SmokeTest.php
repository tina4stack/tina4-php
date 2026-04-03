<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Comprehensive smoke test — validates all key features work end-to-end.
 * Uses in-memory/temp resources only.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\AutoCrud;
use Tina4\Container;
use Tina4\Database\SQLite3Adapter;
use Tina4\DevMailbox;
use Tina4\DotEnv;
use Tina4\FakeData;
use Tina4\Frond;
use Tina4\GraphQL;
use Tina4\I18n;
use Tina4\Migration;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Middleware\RateLimiter;
use Tina4\Middleware\ResponseCache;
use Tina4\ORM;
use Tina4\Queue;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\Session;
use Tina4\SqlTranslation;
use Tina4\StaticFiles;
use Tina4\Swagger;
use Tina4\WebSocket;
use Tina4\WSDL;
use Tina4\WSDLOperation;
use Tina4\Events;
use Tina4\HtmlElement;

/**
 * ORM model used by multiple smoke test sections.
 */
class SmokeItem extends ORM
{
    public string $tableName = 'smoke_items';
    public string $primaryKey = 'id';
}

/**
 * WSDL service stub used in the WSDL smoke test.
 */
class SmokeCalculator extends WSDL
{
    protected string $serviceName = 'SmokeCalculator';

    #[WSDLOperation(['Result' => 'int'])]
    public function Add(int $a, int $b): array
    {
        return ['Result' => $a + $b];
    }
}

class SmokeTest extends TestCase
{
    // ═════════════════════════════════════════════════════════════════
    // 1. Router
    // ═════════════════════════════════════════════════════════════════

    public function testRouterRegisterAndMatch(): void
    {
        Router::clear();

        Router::get('/smoke/hello', fn() => 'Hello');
        Router::post('/smoke/data', fn() => 'created');

        $getResult = Router::match('GET', '/smoke/hello');
        $this->assertNotNull($getResult, 'GET route should match');
        $this->assertSame('/smoke/hello', $getResult['route']['pattern']);

        $postResult = Router::match('POST', '/smoke/data');
        $this->assertNotNull($postResult, 'POST route should match');

        $this->assertNull(Router::match('GET', '/smoke/data'), 'GET should not match POST route');

        Router::clear();
    }

    public function testRouterExtractParams(): void
    {
        Router::clear();

        Router::get('/smoke/users/{id}', fn() => null);
        $result = Router::match('GET', '/smoke/users/42');

        $this->assertNotNull($result);
        $this->assertSame('42', $result['params']['id']);

        Router::clear();
    }

    public function testRouterDispatch(): void
    {
        Router::clear();

        Router::get('/smoke/greet/{name}', function (Request $req, Response $res) {
            return $res->json(['greeting' => 'Hello, ' . $req->params['name']]);
        });

        $request = Request::create(method: 'GET', path: '/smoke/greet/World');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('Hello, World', $result->getJsonBody()['greeting']);

        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 2. ORM
    // ═════════════════════════════════════════════════════════════════

    public function testOrmSaveLoadDeleteToArray(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE smoke_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT)");

        // Save
        $item = new SmokeItem($db);
        $item->name = 'Widget';
        $item->category = 'Tools';
        $this->assertTrue($item->save());
        $this->assertTrue($item->exists());
        $id = $item->getPrimaryKeyValue();
        $this->assertNotNull($id);

        // Load
        $loaded = new SmokeItem($db);
        $loaded->load($id);
        $this->assertTrue($loaded->exists());
        $this->assertSame('Widget', $loaded->name);

        // toArray
        $arr = $loaded->toArray();
        $this->assertSame('Widget', $arr['name']);
        $this->assertSame('Tools', $arr['category']);

        // Delete
        $this->assertTrue($loaded->delete());

        $check = new SmokeItem($db);
        $check->load($id);
        $this->assertFalse($check->exists());

        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // 3. Database (SQLite in-memory)
    // ═════════════════════════════════════════════════════════════════

    public function testDatabaseCrud(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)");
        $this->assertTrue($db->tableExists('items'));

        // Insert
        $db->exec("INSERT INTO items (name, price) VALUES (:name, :price)", [':name' => 'Bolt', ':price' => 1.50]);
        $db->exec("INSERT INTO items (name, price) VALUES (:name, :price)", [':name' => 'Nut', ':price' => 0.75]);

        // Query
        $rows = $db->query("SELECT * FROM items ORDER BY name");
        $this->assertCount(2, $rows);
        $this->assertSame('Bolt', $rows[0]['name']);

        // Fetch with pagination
        $result = $db->fetch("SELECT * FROM items ORDER BY id", 1, 0);
        $this->assertCount(1, $result['data']);
        $this->assertSame(2, $result['total']);

        // FetchOne equivalent via query
        $one = $db->query("SELECT * FROM items WHERE name = :n", [':n' => 'Bolt']);
        $this->assertCount(1, $one);
        $this->assertSame(1.50, $one[0]['price']);

        // Update
        $db->exec("UPDATE items SET price = 2.00 WHERE name = 'Bolt'");
        $updated = $db->query("SELECT price FROM items WHERE name = 'Bolt'");
        $this->assertSame(2.00, $updated[0]['price']);

        // Delete
        $db->exec("DELETE FROM items WHERE name = 'Nut'");
        $remaining = $db->query("SELECT * FROM items");
        $this->assertCount(1, $remaining);

        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // 4. Frond templates
    // ═════════════════════════════════════════════════════════════════

    public function testFrondRenderWithVariables(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tina4_smoke_frond_' . uniqid();
        mkdir($tmpDir, 0777, true);

        $engine = new Frond($tmpDir);

        // Simple variable rendering
        $this->assertSame('Hello World', $engine->renderString('Hello {{ name }}', ['name' => 'World']));

        // Filter
        $this->assertSame('HELLO', $engine->renderString('{{ name | upper }}', ['name' => 'hello']));

        // Inheritance
        file_put_contents($tmpDir . '/base.html', '<h1>{% block title %}Default{% endblock %}</h1>');
        file_put_contents($tmpDir . '/child.html', '{% extends "base.html" %}{% block title %}Smoke{% endblock %}');
        $this->assertSame('<h1>Smoke</h1>', $engine->render('child.html', []));

        // Cleanup
        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
    }

    // ═════════════════════════════════════════════════════════════════
    // 5. Error templates
    // ═════════════════════════════════════════════════════════════════

    public function testErrorTemplatesExistAndRender(): void
    {
        $templateDir = dirname(__DIR__) . '/Tina4/templates/errors';

        $codes = [302, 401, 403, 404, 500, 502, 503];
        foreach ($codes as $code) {
            $file = $templateDir . '/' . $code . '.twig';
            $this->assertFileExists($file, "Error template {$code}.twig should exist");
            $content = file_get_contents($file);
            $this->assertNotEmpty($content, "Error template {$code}.twig should not be empty");
        }

        // Base template
        $this->assertFileExists($templateDir . '/base.twig');
    }

    // ═════════════════════════════════════════════════════════════════
    // 6. Sessions
    // ═════════════════════════════════════════════════════════════════

    public function testSessionCreateReadDestroy(): void
    {
        $sessPath = sys_get_temp_dir() . '/tina4_smoke_sessions_' . bin2hex(random_bytes(4));
        mkdir($sessPath, 0755, true);

        $session = new Session('file', ['path' => $sessPath, 'ttl' => 3600]);
        $id = $session->start();
        $this->assertNotEmpty($id);
        $this->assertTrue($session->isStarted());

        // Set and get
        $session->set('user', 'Alice');
        $this->assertSame('Alice', $session->get('user'));
        $this->assertTrue($session->has('user'));

        // Destroy
        $session->destroy();
        $this->assertFalse($session->isStarted());
        $this->assertNull($session->get('user'));

        // Cleanup
        $files = glob($sessPath . '/*.json');
        foreach ($files as $f) {
            unlink($f);
        }
        if (is_dir($sessPath)) {
            rmdir($sessPath);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // 7. Auth / JWT
    // ═════════════════════════════════════════════════════════════════

    public function testAuthJwtAndPasswordHash(): void
    {
        $secret = 'smoke-test-secret-key';

        // Create token
        $token = Auth::getToken(['sub' => 'user-1', 'role' => 'admin'], $secret, 3600);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Validate token
        $payload = Auth::validToken($token, $secret);
        $this->assertNotNull($payload);
        $this->assertSame('user-1', $payload['sub']);
        $this->assertSame('admin', $payload['role']);

        // Expired token rejected
        $expired = Auth::getToken(['sub' => 'x', 'exp' => time() - 10], $secret, 0);
        $this->assertNull(Auth::validToken($expired, $secret));

        // Wrong secret rejected
        $this->assertNull(Auth::validToken($token, 'wrong-secret'));

        // Password hashing
        $hash = Auth::hashPassword('my-password');
        $this->assertTrue(Auth::checkPassword('my-password', $hash));
        $this->assertFalse(Auth::checkPassword('wrong-password', $hash));
    }

    // ═════════════════════════════════════════════════════════════════
    // 8. Middleware — CORS and Rate Limiter
    // ═════════════════════════════════════════════════════════════════

    public function testCorsMiddleware(): void
    {
        $cors = new CorsMiddleware();

        $headers = $cors->getHeaders('http://example.com');
        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('GET', $headers['Access-Control-Allow-Methods']);

        $this->assertTrue($cors->isPreflight('OPTIONS'));
        $this->assertFalse($cors->isPreflight('GET'));
    }

    public function testRateLimiter(): void
    {
        $limiter = new RateLimiter(limit: 2, window: 60);

        $r1 = $limiter->check('127.0.0.1');
        $this->assertTrue($r1['allowed']);
        $this->assertSame('1', $r1['headers']['X-RateLimit-Remaining']);

        $limiter->check('127.0.0.1');

        $r3 = $limiter->check('127.0.0.1');
        $this->assertFalse($r3['allowed']);
        $this->assertSame(429, $r3['status']);
    }

    // ═════════════════════════════════════════════════════════════════
    // 9. Queue
    // ═════════════════════════════════════════════════════════════════

    public function testQueuePushPopPayload(): void
    {
        $queuePath = sys_get_temp_dir() . '/tina4_smoke_queue_' . bin2hex(random_bytes(4));
        mkdir($queuePath, 0755, true);

        $q = new Queue('file', ['path' => $queuePath]);

        // Push
        $id = $q->push('emails', ['to' => 'alice@test.com', 'subject' => 'Hello']);
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $this->assertSame(1, $q->size('emails'));

        // Pop
        $job = $q->pop('emails');
        $this->assertIsArray($job);
        $this->assertSame('alice@test.com', $job['payload']['to']);
        $this->assertSame('Hello', $job['payload']['subject']);
        $this->assertSame('pending', $job['status']);
        $this->assertSame(0, $q->size('emails'));

        // Pop on empty returns null
        $this->assertNull($q->pop('emails'));

        // Cleanup
        $this->removeDirRecursive($queuePath);
    }

    // ═════════════════════════════════════════════════════════════════
    // 10. GraphQL
    // ═════════════════════════════════════════════════════════════════

    public function testGraphQL(): void
    {
        $gql = new GraphQL();

        // addType
        $gql->addType('Product', [
            'id' => 'ID',
            'name' => 'String',
            'price' => 'Float',
        ]);
        $this->assertArrayHasKey('Product', $gql->getTypes());

        // addQuery
        $products = [
            ['id' => '1', 'name' => 'Widget', 'price' => 9.99],
            ['id' => '2', 'name' => 'Gadget', 'price' => 19.99],
        ];
        $gql->addQuery('product', ['id' => 'ID!'], 'Product', function ($r, $a, $c) use ($products) {
            foreach ($products as $p) {
                if ($p['id'] === $a['id']) {
                    return $p;
                }
            }
            return null;
        });

        // addMutation
        $gql->addMutation('createProduct', ['name' => 'String!', 'price' => 'Float!'], 'Product',
            function ($r, $a, $c) {
                return ['id' => '3', 'name' => $a['name'], 'price' => $a['price']];
            });

        // Execute query
        $result = $gql->execute('{ product(id: "1") { name price } }');
        $this->assertSame('Widget', $result['data']['product']['name']);

        // Execute mutation
        $mutResult = $gql->execute('mutation { createProduct(name: "Thingamajig", price: 5.99) { id name } }');
        $this->assertSame('3', $mutResult['data']['createProduct']['id']);
        $this->assertSame('Thingamajig', $mutResult['data']['createProduct']['name']);
    }

    public function testGraphQLFromOrm(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE smoke_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT)");
        $db->exec("INSERT INTO smoke_items (name, category) VALUES ('Alpha', 'Cat1')");

        $gql = new GraphQL();
        $model = new SmokeItem($db);
        $gql->fromOrm($model);

        // The fromOrm should have created queries — verify schema has the type
        $sdl = $gql->schema();
        $this->assertStringContainsString('type SmokeItem', $sdl);
        $this->assertStringContainsString('type Query', $sdl);

        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // 11. Swagger
    // ═════════════════════════════════════════════════════════════════

    public function testSwaggerGenerateSpec(): void
    {
        Router::clear();

        Router::get('/api/widgets', fn() => null);
        Router::post('/api/widgets', fn() => null);
        Router::get('/api/widgets/{id}', fn() => null)->secure();

        $spec = Swagger::generateSpec('Smoke API', '0.1.0');

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Smoke API', $spec['info']['title']);
        $this->assertSame('0.1.0', $spec['info']['version']);
        $this->assertArrayHasKey('paths', $spec);

        // Check that routes appear in paths
        $paths = (array) $spec['paths'];
        $this->assertNotEmpty($paths);

        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 12. i18n
    // ═════════════════════════════════════════════════════════════════

    public function testI18nTranslateAndSwitchLocale(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tina4_smoke_i18n_' . uniqid();
        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/en.json', json_encode(['greeting' => 'Hello']));
        file_put_contents($tmpDir . '/fr.json', json_encode(['greeting' => 'Bonjour']));

        $i18n = new I18n($tmpDir, 'en');
        $this->assertSame('Hello', $i18n->t('greeting'));

        // Switch locale
        $i18n->setLocale('fr');
        $this->assertSame('Bonjour', $i18n->t('greeting'));
        $this->assertSame('fr', $i18n->getLocale());

        // Fallback to key
        $this->assertSame('missing.key', $i18n->t('missing.key'));

        // Cleanup
        array_map('unlink', glob($tmpDir . '/*.json'));
        rmdir($tmpDir);

        putenv('TINA4_LOCALE');
        putenv('TINA4_LOCALE_DIR');
    }

    // ═════════════════════════════════════════════════════════════════
    // 13. FakeData
    // ═════════════════════════════════════════════════════════════════

    public function testFakeDataSeededDeterministic(): void
    {
        $fake1 = new FakeData(42);
        $name1 = $fake1->fullName();
        $email1 = $fake1->email();

        $fake2 = new FakeData(42);
        $name2 = $fake2->fullName();
        $email2 = $fake2->email();

        $this->assertSame($name1, $name2);
        $this->assertSame($email1, $email2);

        // Basic generation works
        $this->assertNotEmpty($name1);
        $this->assertStringContainsString('@', $email1);
    }

    // ═════════════════════════════════════════════════════════════════
    // 14. Migrations
    // ═════════════════════════════════════════════════════════════════

    public function testMigrationRunAndVerify(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $migDir = sys_get_temp_dir() . '/tina4_smoke_migrations_' . uniqid();
        mkdir($migDir, 0755, true);

        file_put_contents(
            $migDir . '/20240101000000_create_widgets.sql',
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL, weight REAL)'
        );

        $migration = new Migration($db, $migDir);
        $result = $migration->migrate();

        $this->assertCount(1, $result['applied']);
        $this->assertSame('20240101000000_create_widgets.sql', $result['applied'][0]);
        $this->assertTrue($db->tableExists('widgets'));

        // Run again — should skip
        $migration2 = new Migration($db, $migDir);
        $result2 = $migration2->migrate();
        $this->assertCount(0, $result2['applied']);

        // Cleanup
        array_map('unlink', glob($migDir . '/*.sql'));
        rmdir($migDir);
        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // 15. AutoCRUD
    // ═════════════════════════════════════════════════════════════════

    public function testAutoCrudRegistersRoutes(): void
    {
        Router::clear();

        $db = new SQLite3Adapter(':memory:');
        $db->exec("CREATE TABLE smoke_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT)");

        $crud = new AutoCrud($db);
        $crud->register(SmokeItem::class);
        $routes = $crud->generateRoutes();

        $this->assertCount(5, $routes);

        // Verify the generated routes are registered
        $this->assertNotNull(Router::match('GET', '/api/smoke_items'));
        $this->assertNotNull(Router::match('GET', '/api/smoke_items/1'));
        $this->assertNotNull(Router::match('POST', '/api/smoke_items'));
        $this->assertNotNull(Router::match('PUT', '/api/smoke_items/1'));
        $this->assertNotNull(Router::match('DELETE', '/api/smoke_items/1'));

        Router::clear();
        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // 16. Response Cache
    // ═════════════════════════════════════════════════════════════════

    public function testResponseCache(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->clearCache();

        // Store
        $cache->store('GET', '/api/smoke', '{"ok":true}', 'application/json', 200);

        // Lookup hit
        $hit = $cache->lookup('GET', '/api/smoke');
        $this->assertNotNull($hit);
        $this->assertSame('{"ok":true}', $hit['body']);
        $this->assertSame('application/json', $hit['contentType']);
        $this->assertSame(200, $hit['statusCode']);

        // Non-GET not cached
        $cache->store('POST', '/api/smoke', '{}', 'application/json', 200);
        $this->assertNull($cache->lookup('POST', '/api/smoke'));

        $cache->clearCache();
    }

    // ═════════════════════════════════════════════════════════════════
    // 17. DI Container
    // ═════════════════════════════════════════════════════════════════

    public function testContainer(): void
    {
        $container = new Container();

        // Register (transient)
        $container->register('counter', fn() => new \stdClass());
        $this->assertTrue($container->has('counter'));
        $a = $container->get('counter');
        $b = $container->get('counter');
        $this->assertNotSame($a, $b, 'Transient should return new instances');

        // Singleton
        $container->singleton('db', fn() => new \stdClass());
        $c = $container->get('db');
        $d = $container->get('db');
        $this->assertSame($c, $d, 'Singleton should return same instance');

        // has / not has
        $this->assertTrue($container->has('db'));
        $this->assertFalse($container->has('nonexistent'));

        // Reset
        $container->reset();
        $this->assertFalse($container->has('counter'));
        $this->assertFalse($container->has('db'));
    }

    // ═════════════════════════════════════════════════════════════════
    // 18. SQL Translation
    // ═════════════════════════════════════════════════════════════════

    public function testSqlTranslation(): void
    {
        SqlTranslation::cacheClear();
        SqlTranslation::clearFunctions();

        // limitToRows
        $this->assertSame(
            'SELECT * FROM t ROWS 6 TO 15',
            SqlTranslation::limitToRows('SELECT * FROM t LIMIT 10 OFFSET 5')
        );

        // limitToTop
        $this->assertSame(
            'SELECT TOP 10 * FROM t',
            SqlTranslation::limitToTop('SELECT * FROM t LIMIT 10')
        );

        // booleanToInt
        $this->assertSame(
            'SELECT * FROM t WHERE active = 1',
            SqlTranslation::booleanToInt('SELECT * FROM t WHERE active = TRUE')
        );

        SqlTranslation::cacheClear();
        SqlTranslation::clearFunctions();
    }

    // ═════════════════════════════════════════════════════════════════
    // 19. DevMailbox
    // ═════════════════════════════════════════════════════════════════

    public function testDevMailbox(): void
    {
        $mailDir = sys_get_temp_dir() . '/tina4_smoke_mailbox_' . bin2hex(random_bytes(4));

        $mailbox = new DevMailbox($mailDir);
        $result = $mailbox->capture(
            to: 'alice@test.com',
            subject: 'Smoke Test',
            body: '<p>Hello from smoke test</p>',
            html: true,
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['id']);

        // Read inbox
        $messages = $mailbox->inbox();
        $this->assertCount(1, $messages);
        $this->assertSame('Smoke Test', $messages[0]['subject']);

        // Cleanup
        $this->removeDirRecursive($mailDir);
    }

    // ═════════════════════════════════════════════════════════════════
    // 20. Static files — MIME type detection
    // ═════════════════════════════════════════════════════════════════

    public function testStaticFilesMimeDetection(): void
    {
        // Use reflection to test the private getMimeType method
        $method = new \ReflectionMethod(StaticFiles::class, 'getMimeType');

        $this->assertSame('text/html; charset=utf-8', $method->invoke(null, 'html'));
        $this->assertSame('application/javascript; charset=utf-8', $method->invoke(null, 'js'));
        $this->assertSame('image/png', $method->invoke(null, 'png'));
        $this->assertSame('application/json; charset=utf-8', $method->invoke(null, 'json'));
        $this->assertSame('application/pdf', $method->invoke(null, 'pdf'));
        $this->assertSame('application/octet-stream', $method->invoke(null, 'xyz'));
    }

    // ═════════════════════════════════════════════════════════════════
    // 21. DotEnv
    // ═════════════════════════════════════════════════════════════════

    public function testDotEnvParse(): void
    {
        DotEnv::resetEnv();

        $tmpDir = sys_get_temp_dir() . '/tina4_smoke_env_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $envFile = $tmpDir . '/.env';
        file_put_contents($envFile, "SMOKE_KEY=hello\nSMOKE_QUOTED=\"world\"\n# comment\nSMOKE_NUM=42\n");

        DotEnv::loadEnv($envFile);

        $this->assertSame('hello', DotEnv::getEnv('SMOKE_KEY'));
        $this->assertSame('world', DotEnv::getEnv('SMOKE_QUOTED'));
        $this->assertSame('42', DotEnv::getEnv('SMOKE_NUM'));
        $this->assertNull(DotEnv::getEnv('NONEXISTENT'));
        $this->assertSame('fallback', DotEnv::getEnv('NONEXISTENT', 'fallback'));
        $this->assertTrue(DotEnv::hasEnv('SMOKE_KEY'));

        // Cleanup
        DotEnv::resetEnv();
        putenv('SMOKE_KEY');
        putenv('SMOKE_QUOTED');
        putenv('SMOKE_NUM');
        unset($_ENV['SMOKE_KEY'], $_ENV['SMOKE_QUOTED'], $_ENV['SMOKE_NUM']);
        unset($_SERVER['SMOKE_KEY'], $_SERVER['SMOKE_QUOTED'], $_SERVER['SMOKE_NUM']);
        unlink($envFile);
        rmdir($tmpDir);
    }

    // ═════════════════════════════════════════════════════════════════
    // 22. WSDL
    // ═════════════════════════════════════════════════════════════════

    public function testWsdlServiceDefinition(): void
    {
        $request = Request::create(method: 'GET', path: '/calculator', query: ['wsdl' => '']);
        $calculator = new SmokeCalculator($request);
        $response = $calculator->handle();

        $body = $response->getBody();
        $this->assertStringContainsString('SmokeCalculator', $body);
        $this->assertStringContainsString('definitions', $body);
        $this->assertStringContainsString('Add', $body);
        $this->assertStringContainsString('wsdl', $body);
    }

    // ═════════════════════════════════════════════════════════════════
    // 23. WebSocket
    // ═════════════════════════════════════════════════════════════════

    public function testWebSocket(): void
    {
        // Accept key computation (RFC 6455)
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expected = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';
        $this->assertSame($expected, WebSocket::computeAcceptKey($key));

        // Frame encoding
        $frame = WebSocket::encodeFrame('Hello');
        $this->assertSame(0x81, ord($frame[0])); // FIN + TEXT
        $this->assertSame(5, ord($frame[1]));     // length
        $this->assertSame('Hello', substr($frame, 2));

        // Frame decoding round-trip
        $decoded = WebSocket::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertSame('Hello', $decoded['payload']);
        $this->assertTrue($decoded['fin']);
        $this->assertSame(WebSocket::OP_TEXT, $decoded['opcode']);

        // Handshake response
        $handshake = WebSocket::buildHandshakeResponse($key);
        $this->assertStringContainsString('101 Switching Protocols', $handshake);
        $this->assertStringContainsString('Upgrade: websocket', $handshake);
    }

    // ═════════════════════════════════════════════════════════════════
    // Helper
    // ═════════════════════════════════════════════════════════════════

    // ═════════════════════════════════════════════════════════════════
    // 24. Router — PUT, PATCH, DELETE, any()
    // ═════════════════════════════════════════════════════════════════

    public function testRouterPutMethod(): void
    {
        Router::clear();
        Router::put('/smoke/item/{id}', fn(Request $req, Response $res) =>
            $res->json(['updated' => $req->params['id']]))->noAuth();

        $request = Request::create(method: 'PUT', path: '/smoke/item/7');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('7', $result->getJsonBody()['updated']);
        Router::clear();
    }

    public function testRouterPatchMethod(): void
    {
        Router::clear();
        Router::patch('/smoke/item/{id}', fn(Request $req, Response $res) =>
            $res->json(['patched' => $req->params['id']]))->noAuth();

        $request = Request::create(method: 'PATCH', path: '/smoke/item/3');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('3', $result->getJsonBody()['patched']);
        Router::clear();
    }

    public function testRouterDeleteMethod(): void
    {
        Router::clear();
        Router::delete('/smoke/item/{id}', fn(Request $req, Response $res) =>
            $res->json(['deleted' => $req->params['id']]))->noAuth();

        $request = Request::create(method: 'DELETE', path: '/smoke/item/5');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('5', $result->getJsonBody()['deleted']);
        Router::clear();
    }

    public function testRouterAnyMethod(): void
    {
        Router::clear();
        Router::any('/smoke/universal', fn(Request $req, Response $res) =>
            $res->json(['method' => $req->method]));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $result = Router::match($method, '/smoke/universal');
            $this->assertNotNull($result, "any() should register {$method}");
        }
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 25. Router — groups
    // ═════════════════════════════════════════════════════════════════

    public function testRouterGroup(): void
    {
        Router::clear();
        Router::group('/api/v1', function () {
            Router::get('/users', fn() => 'users');
            Router::get('/items', fn() => 'items');
        });

        $this->assertNotNull(Router::match('GET', '/api/v1/users'));
        $this->assertNotNull(Router::match('GET', '/api/v1/items'));
        $this->assertNull(Router::match('GET', '/users'));
        Router::clear();
    }

    public function testRouterNestedGroups(): void
    {
        Router::clear();
        Router::group('/api', function () {
            Router::group('/v2', function () {
                Router::get('/widgets', fn() => 'widgets');
            });
        });

        $this->assertNotNull(Router::match('GET', '/api/v2/widgets'));
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 26. Router — middleware
    // ═════════════════════════════════════════════════════════════════

    public function testRouterMiddlewareBlocks(): void
    {
        Router::clear();
        Router::get('/smoke/protected', fn(Request $req, Response $res) =>
            $res->json(['ok' => true])
        )->middleware([fn($req, $res) => false]);

        $request = Request::create(method: 'GET', path: '/smoke/protected');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(403, $result->getStatusCode());
        Router::clear();
    }

    public function testRouterMiddlewareAllows(): void
    {
        Router::clear();
        Router::get('/smoke/open', fn(Request $req, Response $res) =>
            $res->json(['allowed' => true])
        )->middleware([fn($req, $res) => true]);

        $request = Request::create(method: 'GET', path: '/smoke/open');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertTrue($result->getJsonBody()['allowed']);
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 27. Router — secure routes and 401
    // ═════════════════════════════════════════════════════════════════

    public function testRouterSecureRouteNoBearerReturns401(): void
    {
        Router::clear();
        Router::get('/smoke/secret', fn(Request $req, Response $res) =>
            $res->json(['secret' => 'data'])
        )->secure();

        $request = Request::create(method: 'GET', path: '/smoke/secret');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode());
        Router::clear();
    }

    public function testRouterSecureRouteWithBearerAllowed(): void
    {
        Router::clear();
        $secret = 'smoke-test-secret';
        putenv("SECRET={$secret}");
        Router::get('/smoke/secret', fn(Request $req, Response $res) =>
            $res->json(['secret' => 'data'])
        )->secure();

        $token = Auth::getToken(['sub' => 'tester'], $secret);
        $request = Request::create(
            method: 'GET',
            path: '/smoke/secret',
            headers: ['authorization' => "Bearer {$token}"]
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('data', $result->getJsonBody()['secret']);
        putenv('SECRET');
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 28. Router — 404 for unmatched
    // ═════════════════════════════════════════════════════════════════

    public function testRouterReturns404ForNoMatch(): void
    {
        Router::clear();
        $request = Request::create(method: 'GET', path: '/nonexistent');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(404, $result->getStatusCode());
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 29. Router — count and list
    // ═════════════════════════════════════════════════════════════════

    public function testRouterCountAndList(): void
    {
        Router::clear();
        Router::get('/a', fn() => null);
        Router::post('/b', fn() => null);
        Router::put('/c', fn() => null);

        $this->assertSame(3, Router::count());
        $list = Router::getRoutes();
        $this->assertCount(3, $list);

        $methods = array_column($list, 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 30. Router — handler returns string or array directly
    // ═════════════════════════════════════════════════════════════════

    public function testRouterHandlerReturnsString(): void
    {
        Router::clear();
        Router::get('/smoke/plain', fn(Request $req, Response $res) => '<h1>Hello</h1>');

        $request = Request::create(method: 'GET', path: '/smoke/plain');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertStringContainsString('Hello', $result->getBody());
        Router::clear();
    }

    public function testRouterHandlerReturnsArray(): void
    {
        Router::clear();
        Router::get('/smoke/arr', fn(Request $req, Response $res) => ['status' => 'ok']);

        $request = Request::create(method: 'GET', path: '/smoke/arr');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('ok', $result->getJsonBody()['status']);
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 31. Router — handler throws exception returns 500
    // ═════════════════════════════════════════════════════════════════

    public function testRouterHandlerExceptionReturns500(): void
    {
        Router::clear();
        Router::get('/smoke/crash', fn(Request $req, Response $res) =>
            throw new \RuntimeException('Boom'));

        $request = Request::create(method: 'GET', path: '/smoke/crash');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(500, $result->getStatusCode());
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 32. Events
    // ═════════════════════════════════════════════════════════════════

    public function testEventsOnAndEmit(): void
    {
        Events::clear();
        $received = [];
        Events::on('test.event', function ($data) use (&$received) {
            $received[] = $data;
            return 'handled';
        });

        $results = Events::emit('test.event', 'payload');
        $this->assertSame(['payload'], $received);
        $this->assertSame(['handled'], $results);
        Events::clear();
    }

    public function testEventsOnce(): void
    {
        Events::clear();
        $count = 0;
        Events::once('one.time', function () use (&$count) {
            $count++;
        });

        Events::emit('one.time');
        Events::emit('one.time');

        $this->assertSame(1, $count);
        Events::clear();
    }

    public function testEventsOff(): void
    {
        Events::clear();
        $handler = function () { return 'x'; };
        Events::on('removable', $handler);
        $this->assertCount(1, Events::listeners('removable'));

        Events::off('removable', $handler);
        $this->assertCount(0, Events::listeners('removable'));
        Events::clear();
    }

    public function testEventsOffAll(): void
    {
        Events::clear();
        Events::on('multi', function () {});
        Events::on('multi', function () {});
        Events::off('multi');
        $this->assertEmpty(Events::listeners('multi'));
        Events::clear();
    }

    public function testEventsPriority(): void
    {
        Events::clear();
        $order = [];
        Events::on('priority.test', function () use (&$order) { $order[] = 'low'; }, 1);
        Events::on('priority.test', function () use (&$order) { $order[] = 'high'; }, 10);

        Events::emit('priority.test');
        $this->assertSame(['high', 'low'], $order);
        Events::clear();
    }

    public function testEventsListAndClear(): void
    {
        Events::clear();
        Events::on('a', function () {});
        Events::on('b', function () {});

        $events = Events::events();
        $this->assertContains('a', $events);
        $this->assertContains('b', $events);

        Events::clear();
        $this->assertEmpty(Events::events());
    }

    // ═════════════════════════════════════════════════════════════════
    // 33. Container — additional
    // ═════════════════════════════════════════════════════════════════

    public function testContainerGetUnregisteredThrows(): void
    {
        $container = new Container();
        $this->expectException(\RuntimeException::class);
        $container->get('nonexistent');
    }

    public function testContainerOverwriteRegistration(): void
    {
        $container = new Container();
        $container->register('svc', fn() => 'first');
        $container->register('svc', fn() => 'second');
        $this->assertSame('second', $container->get('svc'));
        $container->reset();
    }

    // ═════════════════════════════════════════════════════════════════
    // 34. HtmlElement
    // ═════════════════════════════════════════════════════════════════

    public function testHtmlElementBasic(): void
    {
        $el = new HtmlElement('div', ['class' => 'card'], ['Hello']);
        $this->assertSame('<div class="card">Hello</div>', (string)$el);
    }

    public function testHtmlElementVoidTag(): void
    {
        $el = new HtmlElement('br');
        $this->assertSame('<br>', (string)$el);

        $el = new HtmlElement('img', ['src' => 'photo.jpg', 'alt' => 'Photo']);
        $this->assertSame('<img src="photo.jpg" alt="Photo">', (string)$el);
    }

    public function testHtmlElementNested(): void
    {
        $inner = new HtmlElement('span', [], ['World']);
        $outer = new HtmlElement('div', [], ['Hello ', $inner]);
        $this->assertSame('<div>Hello <span>World</span></div>', (string)$outer);
    }

    public function testHtmlElementBooleanAttribute(): void
    {
        $el = new HtmlElement('input', ['type' => 'checkbox', 'checked' => true]);
        $html = (string)$el;
        $this->assertStringContainsString('checked', $html);
        $this->assertStringNotContainsString('checked="', $html);
    }

    public function testHtmlElementFalseAttribute(): void
    {
        $el = new HtmlElement('input', ['disabled' => false]);
        $html = (string)$el;
        $this->assertStringNotContainsString('disabled', $html);
    }

    public function testHtmlElementEscapesAttributes(): void
    {
        $el = new HtmlElement('div', ['title' => 'He said "hello"']);
        $html = (string)$el;
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testHtmlElementInvoke(): void
    {
        $div = new HtmlElement('div');
        $result = $div('child text');
        $this->assertSame('<div>child text</div>', (string)$result);
    }

    public function testHtmlElementInvokeWithAttrs(): void
    {
        $div = new HtmlElement('div');
        $result = $div(['class' => 'box'], 'content');
        $this->assertSame('<div class="box">content</div>', (string)$result);
    }

    public function testHtmlElementHelpers(): void
    {
        $helpers = HtmlElement::helpers();
        $this->assertArrayHasKey('_div', $helpers);
        $this->assertArrayHasKey('_p', $helpers);
        $this->assertArrayHasKey('_span', $helpers);
        $this->assertArrayHasKey('_a', $helpers);

        $p = $helpers['_p']('Hello');
        $this->assertSame('<p>Hello</p>', (string)$p);

        $a = $helpers['_a'](['href' => '/home'], 'Home');
        $this->assertSame('<a href="/home">Home</a>', (string)$a);
    }

    // ═════════════════════════════════════════════════════════════════
    // 35. Request — additional
    // ═════════════════════════════════════════════════════════════════

    public function testRequestIsMethod(): void
    {
        $req = Request::create(method: 'POST', path: '/test');
        $this->assertTrue($req->isMethod('POST'));
        $this->assertTrue($req->isMethod('post'));
        $this->assertFalse($req->isMethod('GET'));
    }

    public function testRequestQueryParam(): void
    {
        $req = Request::create(method: 'GET', path: '/search', query: ['q' => 'hello', 'page' => '2']);
        $this->assertSame('hello', $req->queryParam('q'));
        $this->assertSame('2', $req->queryParam('page'));
        $this->assertNull($req->queryParam('missing'));
        $this->assertSame('default', $req->queryParam('missing', 'default'));
    }

    public function testRequestBearerToken(): void
    {
        $req = Request::create(
            method: 'GET',
            path: '/api',
            headers: ['authorization' => 'Bearer my-token-123']
        );
        $this->assertSame('my-token-123', $req->bearerToken());
    }

    public function testRequestBearerTokenMissing(): void
    {
        $req = Request::create(method: 'GET', path: '/api');
        $this->assertNull($req->bearerToken());
    }

    public function testRequestWantsJson(): void
    {
        $req = Request::create(
            method: 'GET',
            path: '/api',
            headers: ['accept' => 'application/json']
        );
        $this->assertTrue($req->wantsJson());
    }

    public function testRequestIsJson(): void
    {
        $req = Request::create(
            method: 'POST',
            path: '/api',
            headers: ['content-type' => 'application/json'],
            body: ['key' => 'value']
        );
        $this->assertTrue($req->isJson());
    }

    public function testRequestInput(): void
    {
        $req = Request::create(
            method: 'POST',
            path: '/api',
            body: ['name' => 'Alice', 'age' => 30]
        );
        $this->assertSame('Alice', $req->input('name'));
        $this->assertSame(30, $req->input('age'));
        $this->assertNull($req->input('missing'));
        $this->assertSame('fallback', $req->input('missing', 'fallback'));
    }

    public function testRequestIpDefault(): void
    {
        $req = Request::create(method: 'GET', path: '/');
        $this->assertSame('127.0.0.1', $req->ip);
    }

    // ═════════════════════════════════════════════════════════════════
    // 36. Auth — additional
    // ═════════════════════════════════════════════════════════════════

    public function testAuthGetPayloadWithoutVerification(): void
    {
        $secret = 'test-secret';
        $token = Auth::getToken(['user' => 'bob'], $secret);
        $payload = Auth::getPayload($token);

        $this->assertNotNull($payload);
        $this->assertSame('bob', $payload['user']);
    }

    public function testAuthGetPayloadMalformed(): void
    {
        $this->assertNull(Auth::getPayload('not-a-jwt'));
        $this->assertNull(Auth::getPayload('a.b'));
        $this->assertNull(Auth::getPayload(''));
    }

    public function testAuthRefreshToken(): void
    {
        $secret = 'refresh-secret';
        $token = Auth::getToken(['sub' => 'user-1'], $secret, 3600);
        $newToken = Auth::refreshToken($token, $secret, 7200);

        $this->assertNotNull($newToken);
        $this->assertNotSame($token, $newToken);

        $payload = Auth::validToken($newToken, $secret);
        $this->assertSame('user-1', $payload['sub']);
    }

    public function testAuthRefreshTokenInvalid(): void
    {
        $this->assertNull(Auth::refreshToken('invalid', 'secret'));
    }

    public function testAuthAuthenticateRequest(): void
    {
        $secret = 'auth-secret';
        $token = Auth::getToken(['role' => 'admin'], $secret);

        $payload = Auth::authenticateRequest(
            ['Authorization' => "Bearer {$token}"],
            $secret
        );

        $this->assertNotNull($payload);
        $this->assertSame('admin', $payload['role']);
    }

    public function testAuthAuthenticateRequestNoBearer(): void
    {
        $this->assertNull(Auth::authenticateRequest([], 'secret'));
    }

    public function testAuthValidateApiKey(): void
    {
        $this->assertTrue(Auth::validateApiKey('my-key', 'my-key'));
        $this->assertFalse(Auth::validateApiKey('wrong', 'my-key'));
        $this->assertFalse(Auth::validateApiKey('any', ''));
    }

    public function testAuthMiddleware(): void
    {
        $secret = 'mw-secret';
        $mw = Auth::middleware($secret);
        $this->assertIsCallable($mw);

        $token = Auth::getToken(['sub' => 'test'], $secret);
        $req = Request::create(
            method: 'GET',
            path: '/api',
            headers: ['authorization' => "Bearer {$token}"]
        );

        $result = $mw($req);
        $this->assertIsArray($result);
        $this->assertSame('test', $result['sub']);
    }

    public function testAuthMiddlewareNoToken(): void
    {
        $mw = Auth::middleware('secret');
        $req = Request::create(method: 'GET', path: '/api');
        $this->assertNull($mw($req));
    }

    // ═════════════════════════════════════════════════════════════════
    // 37. Queue — additional
    // ═════════════════════════════════════════════════════════════════

    public function testQueueMultipleChannels(): void
    {
        $queuePath = sys_get_temp_dir() . '/tina4_smoke_queue_' . bin2hex(random_bytes(4));
        mkdir($queuePath, 0755, true);

        $q = new Queue('file', ['path' => $queuePath]);

        $q->push('channel_a', ['msg' => 'a1']);
        $q->push('channel_b', ['msg' => 'b1']);
        $q->push('channel_a', ['msg' => 'a2']);

        $this->assertSame(2, $q->size('channel_a'));
        $this->assertSame(1, $q->size('channel_b'));

        $job = $q->pop('channel_b');
        $this->assertSame('b1', $job['payload']['msg']);

        $this->removeDirRecursive($queuePath);
    }

    // ═════════════════════════════════════════════════════════════════
    // 38. Router — cache and noCache chaining
    // ═════════════════════════════════════════════════════════════════

    public function testRouterCacheFlag(): void
    {
        Router::clear();
        Router::get('/cached', fn() => 'ok')->cache();

        $list = Router::getRoutes();
        $cached = array_filter($list, fn($r) => $r['pattern'] === '/cached');
        $cached = array_values($cached);

        $this->assertTrue($cached[0]['cache']);
        Router::clear();
    }

    public function testRouterNoCacheFlag(): void
    {
        Router::clear();
        Router::get('/nocache', fn() => 'ok')->cache()->noCache();

        $list = Router::getRoutes();
        $route = array_filter($list, fn($r) => $r['pattern'] === '/nocache');
        $route = array_values($route);

        $this->assertFalse($route[0]['cache']);
        Router::clear();
    }

    // ═════════════════════════════════════════════════════════════════
    // 39. Migration — multiple migrations
    // ═════════════════════════════════════════════════════════════════

    public function testMigrationMultipleFiles(): void
    {
        $db = new SQLite3Adapter(':memory:');
        $migDir = sys_get_temp_dir() . '/tina4_smoke_mig2_' . uniqid();
        mkdir($migDir, 0755, true);

        file_put_contents(
            $migDir . '/20240101000000_create_a.sql',
            'CREATE TABLE table_a (id INTEGER PRIMARY KEY, name TEXT)'
        );
        file_put_contents(
            $migDir . '/20240102000000_create_b.sql',
            'CREATE TABLE table_b (id INTEGER PRIMARY KEY, value REAL)'
        );

        $migration = new Migration($db, $migDir);
        $result = $migration->migrate();

        $this->assertCount(2, $result['applied']);
        $this->assertTrue($db->tableExists('table_a'));
        $this->assertTrue($db->tableExists('table_b'));

        array_map('unlink', glob($migDir . '/*.sql'));
        rmdir($migDir);
        $db->close();
    }

    // ═════════════════════════════════════════════════════════════════
    // Helper
    // ═════════════════════════════════════════════════════════════════

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
