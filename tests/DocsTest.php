<?php

/**
 * Tina4\Docs — Live API RAG test corpus.
 *
 * Mirrors tina4-python/tests/test_docs.py. Same test names, same
 * assertions, different language. Both files live RED until the
 * corresponding `Docs` module is implemented in each framework.
 *
 * Spec: plan/v3/22-LIVE-API-RAG.md
 */

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Docs;
use Tina4\App;

/**
 * @group docs
 * @group live-rag
 */
class DocsTest extends TestCase
{
    /** @var string Temp project root with one user model + route + template */
    private static string $fixture;

    public static function setUpBeforeClass(): void
    {
        // Build a deterministic fixture project under sys_get_temp_dir().
        // The fixture has a known shape so user-code tests can assert
        // against it without relying on the real /tmp/tina4-supervisor-test.
        $root = sys_get_temp_dir() . '/tina4-docs-fixture-' . bin2hex(random_bytes(4));
        self::$fixture = $root;
        @mkdir("$root/src/orm", 0755, true);
        @mkdir("$root/src/routes", 0755, true);
        @mkdir("$root/src/templates", 0755, true);

        file_put_contents("$root/src/orm/User.php", <<<'PHP'
<?php
namespace App\Models;
class User extends \Tina4\ORM
{
    public string $tableName = 'users';
    public string $primaryKey = 'id';

    /** Find a user by email — returns null if not found. */
    public static function findByEmail(string $email): ?self
    {
        return null;
    }

    /** @internal */
    private function _hashPassword(string $plain): string
    {
        return $plain;
    }
}
PHP);

        file_put_contents("$root/src/routes/contact.php", <<<'PHP'
<?php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::post('/contact', function (Request $request, Response $response) {
    return $response->json(['ok' => true]);
});
PHP);

        file_put_contents("$root/src/templates/home.twig", <<<'TWIG'
{% extends 'base.twig' %}
{% block content %}
  <h1>{{ title }}</h1>
{% endblock %}
TWIG);
    }

    public static function tearDownAfterClass(): void
    {
        self::rrmdir(self::$fixture);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function docs(): Docs
    {
        return new Docs(self::$fixture);
    }

    // ── 1 ────────────────────────────────────────────────────────────
    public function testSearchFindsFrameworkRender(): void
    {
        $hits = $this->docs()->search('render template', 5);
        $names = array_map(fn($h) => $h['fqn'] ?? '', $hits);
        $this->assertNotEmpty($hits, 'search returned zero results');
        $this->assertContains(
            'Tina4\\Response::render',
            array_slice($names, 0, 3),
            'Tina4\\Response::render should rank in top 3 for "render template"',
        );
    }

    // ── 2 ────────────────────────────────────────────────────────────
    public function testSearchFindsUserModel(): void
    {
        $hits = $this->docs()->search('User', 5);
        $userHits = array_filter($hits, fn($h) => ($h['source'] ?? '') === 'user');
        $this->assertNotEmpty($userHits, 'expected at least one user-source hit for "User"');
        $found = false;
        foreach (array_slice($hits, 0, 3) as $h) {
            if (str_contains($h['fqn'] ?? '', 'App\\Models\\User')) { $found = true; break; }
        }
        $this->assertTrue($found, 'App\\Models\\User should rank in top 3 for "User"');
    }

    // ── 3 ────────────────────────────────────────────────────────────
    public function testSearchSourceFilter(): void
    {
        $fwOnly = $this->docs()->search('User', 10, 'framework');
        foreach ($fwOnly as $h) {
            $this->assertSame('framework', $h['source'], 'source=framework leaked a non-framework hit');
        }
        $userOnly = $this->docs()->search('User', 10, 'user');
        foreach ($userOnly as $h) {
            $this->assertSame('user', $h['source'], 'source=user leaked a non-user hit');
        }
    }

    // ── 4 ────────────────────────────────────────────────────────────
    public function testClassEndpointReturnsFullMethodList(): void
    {
        $spec = $this->docs()->classSpec('Tina4\\Response');
        $this->assertNotNull($spec, 'Tina4\\Response should be reflectable');
        $this->assertArrayHasKey('methods', $spec);
        $this->assertGreaterThanOrEqual(5, count($spec['methods']));
        foreach ($spec['methods'] as $m) {
            $this->assertArrayHasKey('signature', $m);
            $this->assertArrayHasKey('file', $m);
            $this->assertArrayHasKey('line', $m);
        }
    }

    // ── 5 ────────────────────────────────────────────────────────────
    public function testClassEndpointMissingClass(): void
    {
        $spec = $this->docs()->classSpec('Tina4\\NotARealClass');
        $this->assertNull($spec, 'unknown class should return null (404 path on HTTP)');
    }

    // ── 6 ────────────────────────────────────────────────────────────
    public function testMethodEndpointReturnsSignature(): void
    {
        $spec = $this->docs()->methodSpec('Tina4\\Response', 'render');
        $this->assertNotNull($spec);
        $this->assertSame('render', $spec['name']);
        $this->assertNotEmpty($spec['signature']);
        $this->assertSame('public', $spec['visibility']);
        $this->assertSame('framework', $spec['source']);
    }

    // ── 7 ────────────────────────────────────────────────────────────
    public function testUserClassAppearsInClassEndpoint(): void
    {
        $spec = $this->docs()->classSpec('App\\Models\\User');
        $this->assertNotNull($spec, 'user model should be reflectable');
        $this->assertSame('user', $spec['source']);
        $methods = array_map(fn($m) => $m['name'], $spec['methods']);
        $this->assertContains('findByEmail', $methods);
    }

    // ── 8 ────────────────────────────────────────────────────────────
    public function testIndexEndpointListsAll(): void
    {
        $idx = $this->docs()->index();
        $this->assertGreaterThanOrEqual(50, count($idx), 'expected at least 50 entities (framework + user)');
        $sources = array_unique(array_map(fn($e) => $e['source'], $idx));
        $this->assertContains('framework', $sources);
        $this->assertContains('user', $sources);
    }

    // ── 9 ────────────────────────────────────────────────────────────
    public function testMcpDocsSearchMatchesHttp(): void
    {
        // MCP tool returns the same shape as Docs::search.
        $direct = $this->docs()->search('render', 3);
        $viaMcp = \Tina4\Docs::mcpSearch('render', 3);
        $this->assertEquals(
            array_map(fn($h) => $h['fqn'], $direct),
            array_map(fn($h) => $h['fqn'], $viaMcp),
            'MCP and direct call must return the same ranked hits',
        );
    }

    // ── 10 ───────────────────────────────────────────────────────────
    public function testMcpDocsMethodMatchesHttp(): void
    {
        $direct = $this->docs()->methodSpec('Tina4\\Response', 'render');
        $viaMcp = \Tina4\Docs::mcpMethod('Tina4\\Response', 'render');
        $this->assertEquals($direct, $viaMcp);
    }

    // ── 11 ───────────────────────────────────────────────────────────
    public function testIndexRefreshesOnUserFileChange(): void
    {
        $docs = $this->docs();
        $before = $docs->methodSpec('App\\Models\\User', 'findActive');
        $this->assertNull($before, 'precondition: findActive does not exist yet');

        // Touch the file with a new method.
        $path = self::$fixture . '/src/orm/User.php';
        $src  = file_get_contents($path);
        $patched = str_replace(
            'public string $primaryKey',
            "public static function findActive(): array { return []; }\n    public string \$primaryKey",
            $src,
        );
        file_put_contents($path, $patched);
        // mtime resolution is 1s on some FS — bump explicitly.
        touch($path, time() + 2);

        $after = $docs->methodSpec('App\\Models\\User', 'findActive');
        $this->assertNotNull($after, 'index should pick up newly-added method on next query');
    }

    // ── 12 ───────────────────────────────────────────────────────────
    public function testDriftDetectorFindsDocInconsistency(): void
    {
        // Write a CLAUDE.md-style file that references a non-existent
        // method, run the drift check, expect non-empty diff.
        $tmp = self::$fixture . '/CLAUDE.md';
        file_put_contents($tmp, "```php\n\$response->fakeMethodThatDoesNotExist();\n```\n");
        $report = Docs::checkDocs($tmp);
        $this->assertNotEmpty($report['drift'], 'drift detector should flag fakeMethodThatDoesNotExist');
    }

    // ── 13 ───────────────────────────────────────────────────────────
    public function testSyncOverwritesMarkedSection(): void
    {
        $tmp = self::$fixture . '/CLAUDE.md';
        $original = "Prose above\n<!-- BEGIN GENERATED API -->\nold content\n<!-- END GENERATED API -->\nProse below\n";
        file_put_contents($tmp, $original);
        Docs::syncDocs($tmp);
        $updated = file_get_contents($tmp);
        $this->assertStringContainsString('Prose above', $updated, 'prose above must be preserved');
        $this->assertStringContainsString('Prose below', $updated, 'prose below must be preserved');
        $this->assertStringNotContainsString('old content', $updated, 'old generated content must be replaced');
        $this->assertStringContainsString('Tina4\\Response', $updated, 'fresh content must include real classes');
    }

    // ── 14 ───────────────────────────────────────────────────────────
    public function testSearchResponseUnder50ms(): void
    {
        $docs = $this->docs();
        $docs->search('warm-up', 1); // build the index once
        $start = microtime(true);
        $docs->search('render', 5);
        $elapsedMs = (microtime(true) - $start) * 1000;
        $this->assertLessThan(50.0, $elapsedMs, "search took {$elapsedMs}ms (budget 50ms)");
    }

    // ── 15 ───────────────────────────────────────────────────────────
    public function testNoPrivateMethodsInDefaultSearch(): void
    {
        $hits = $this->docs()->search('hashPassword', 10);
        foreach ($hits as $h) {
            $this->assertNotSame(
                'App\\Models\\User::_hashPassword',
                $h['fqn'] ?? '',
                'private/internal methods must be excluded from default search',
            );
        }
        $optIn = $this->docs()->search('hashPassword', 10, 'all', true);
        $names = array_map(fn($h) => $h['fqn'] ?? '', $optIn);
        $this->assertContains(
            'App\\Models\\User::_hashPassword',
            $names,
            'include_private=true must surface private methods',
        );
    }

    // ── 16 ───────────────────────────────────────────────────────────
    public function testNoVendorThirdPartyInResults(): void
    {
        $hits = $this->docs()->search('PHPUnit', 20);
        foreach ($hits as $h) {
            $src = $h['source'] ?? '';
            $this->assertNotSame('vendor', $src, 'vendor results leaked into default search');
            $this->assertNotEquals(
                0,
                strncmp($h['file'] ?? '', 'vendor/', 7),
                'vendor/ paths leaked into default search',
            );
        }
    }
}
