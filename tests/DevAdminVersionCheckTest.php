<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The dev-admin version check must not report "up to date" for a check it
 * never made.
 *
 * It used to answer HTTP 200 with latest == current whenever the call to
 * Packagist failed, and the toolbar renders that as a green
 * "Latest: vX — You are up to date!". A developer several releases behind, on
 * a machine with no route out, was told the opposite of the truth — and the
 * toolbar's own "Could not check for updates" branch could never fire, because
 * the failure arrived as a success.
 *
 * REAL dispatch, as everywhere else in this suite. The one thing replaced is
 * the registry itself: an https stream wrapper stands in for Packagist so the
 * test can decide what the network does, instead of asking the internet what
 * it feels like today.
 */

require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/** Stands in for repo.packagist.org. A null body means the fetch fails. */
class FakeRegistryStream
{
    public static ?string $body = null;
    /** @var resource|null */
    public $context;
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (self::$body === null) {
            return false;
        }
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $out = substr(self::$body ?? '', $this->pos, $count);
        $this->pos += strlen($out);
        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$body ?? '');
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return [];
    }

    /** @return array<string,int> */
    public function url_stat(string $path, int $flags): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}

class DevAdminVersionCheckTest extends TestCase
{
    private string|false $savedDebug = false;

    protected function setUp(): void
    {
        $this->savedDebug = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=true');
        $_ENV['TINA4_DEBUG'] = 'true';
        FakeRegistryStream::$body = null;
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', FakeRegistryStream::class);
        DevAdmin::register();
    }

    protected function tearDown(): void
    {
        stream_wrapper_restore('https');
        Router::clear();
        // DevAdmin::register() installs error and exception handlers; the rest
        // of the suite tears them down the same way.
        Middleware::reset();
        McpServer::resetDefaultServer();
        ErrorTracker::reset();
        FakeRegistryStream::$body = null;
        unset($_ENV['TINA4_DEBUG']);
        $this->savedDebug === false ? putenv('TINA4_DEBUG') : putenv("TINA4_DEBUG={$this->savedDebug}");
    }

    /** @return array<string,mixed> */
    private function check(): array
    {
        $request = Request::create(method: 'GET', path: '/__dev/api/version-check', remoteIp: '127.0.0.1');
        $response = Router::dispatch($request, new Response(true));
        return json_decode($response->getBody(), true);
    }

    /** A Packagist body carrying the given version strings. */
    private static function registryBody(array $versions): string
    {
        return json_encode([
            'packages' => [
                'tina4stack/tina4php' => array_map(fn($v) => ['version' => $v], $versions),
            ],
        ]);
    }

    public function testAnUnreachableRegistryIsNotReportedAsUpToDate(): void
    {
        FakeRegistryStream::$body = null; // the fetch fails outright

        $payload = $this->check();

        $this->assertNull($payload['latest'], 'a check that did not happen must not answer with a version');
        $this->assertNotSame($payload['current'], $payload['latest'], 'the toolbar reads latest == current as "you are up to date"');
        $this->assertNotEmpty($payload['error'] ?? '', 'the reason has to reach the client');
        $this->assertSame(App::$VERSION, $payload['current']);
    }

    public function testAnAnswerWithNoStableVersionIsNotReportedAsUpToDate(): void
    {
        // Reaching Packagist is not the same as learning the version.
        FakeRegistryStream::$body = self::registryBody(['dev-main', '3.13.131-rc1']);

        $payload = $this->check();

        $this->assertNull($payload['latest']);
        $this->assertNotEmpty($payload['error'] ?? '');
    }

    public function testAReachableRegistryReportsTheHighestStableVersion(): void
    {
        FakeRegistryStream::$body = self::registryBody(['3.13.125', 'dev-main', '3.13.131', '3.13.9']);

        $payload = $this->check();

        $this->assertSame('3.13.131', $payload['latest']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    public function testTheToolbarActsOnAMissingLatestBeforeComparingVersions(): void
    {
        $request = Request::create(method: 'GET', path: '/__dev/toolbar.js', remoteIp: '127.0.0.1');
        $js = Router::dispatch($request, new Response(true))->getBody();

        $this->assertStringContainsString('couldNotCheck', $js, 'no branch for a check that did not happen');
        $this->assertStringContainsString('if (!latest) { couldNotCheck', $js);
        $this->assertLessThan(
            strpos($js, 'if (latest === current)'),
            strpos($js, 'if (!latest)'),
            'the up-to-date branch must not run first — a null would fall into it'
        );
    }
}
