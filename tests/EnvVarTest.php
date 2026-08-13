<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * EnvVarTest — verifies the 25 env vars wired in v3.12.4 read defaults and
 * apply overrides. Each var has at least one default + one override case.
 * Log rotation has dedicated tests (size trigger, keep window, disabled).
 */

use PHPUnit\Framework\TestCase;
use Tina4\DotEnv;
use Tina4\Database\Database;
use Tina4\Frond;
use Tina4\GraphQL;
use Tina4\Log;
use Tina4\LogConfigurationError;
use Tina4\McpServer;
use Tina4\Messenger;
use Tina4\Session;
use Tina4\Swagger;

class EnvVarTest extends TestCase
{
    private string $tempDir;

    /** Env keys we mutate — cleared between tests. */
    private const TRACKED_VARS = [
        'TINA4_HOST',
        'TINA4_SUPPRESS',
        'TINA4_ENV_FILE',
        'TINA4_HEALTH_PATH',
        'TINA4_TRAILING_SLASH_REDIRECT',
        'TINA4_LOG_FILE',
        'TINA4_LOG_DIR',
        'TINA4_LOG_FORMAT',
        'TINA4_LOG_OUTPUT',
        'TINA4_LOG_CRITICAL',
        'TINA4_LOG_ROTATE_SIZE',
        'TINA4_LOG_ROTATE_KEEP',
        // TINA4_LOG_MAX_SIZE / TINA4_LOG_KEEP were DELETED on 2026-08-01 —
        // legacy aliases with a different unit from the name they aliased. The
        // canonical names above are the only rotation vars. LoggerContractTest
        // pins that the deleted names are now inert.
        'TINA4_LOG_STRICT',
        'TINA4_SESSION_HTTPONLY',
        'TINA4_SESSION_NAME',
        'TINA4_SESSION_SECURE',
        'TINA4_SESSION_SAMESITE',
        'TINA4_TEMPLATE_CACHE_TTL',
        'TINA4_GRAPHQL_AUTO_SCHEMA',
        'TINA4_GRAPHQL_ENDPOINT',
        'TINA4_MAIL_IMAP_ENCRYPTION',
        'TINA4_MAIL_IMAP_HOST',
        'TINA4_MCP',
        'TINA4_MCP_PORT',
        'TINA4_DEBUG',
        'TINA4_SWAGGER_CONTACT_EMAIL',
        'TINA4_SWAGGER_ENABLED',
        'TINA4_SWAGGER_LICENSE',
        'TINA4_DB_POOL',
    ];

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_envvar_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        DotEnv::resetEnv();
        foreach (self::TRACKED_VARS as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        Log::reset();
    }

    protected function tearDown(): void
    {
        DotEnv::resetEnv();
        foreach (self::TRACKED_VARS as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        Log::reset();
        $this->removeDir($this->tempDir);
    }

    /** Set one or more env vars for the duration of a test. */
    private function setEnv(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }

    /**
     * Pin development mode (TINA4_DEBUG=true) so the log FILE is written.
     *
     * Since v3.13.39 the log file is written by default ONLY in dev — in
     * production / containers the logger is stdout-only (no file). A test that
     * asserts a file is written (without an explicit TINA4_LOG_OUTPUT/FILE)
     * must therefore run in dev. TINA4_DEBUG is a TRACKED_VAR, so tearDown
     * clears it. Mirrors Python's test fixture pinning TINA4_DEBUG=true.
     */
    private function enableDevLogFile(): void
    {
        $this->setEnv(['TINA4_DEBUG' => 'true']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_HOST — bind address (Server.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4HostDefault(): void
    {
        $server = new \Tina4\Server();
        $this->assertSame('0.0.0.0', $server->getHost());
    }

    public function testTina4HostOverride(): void
    {
        $this->setEnv(['TINA4_HOST' => '127.0.0.1']);
        $server = new \Tina4\Server();
        $this->assertSame('127.0.0.1', $server->getHost());
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SUPPRESS — banner suppression (App.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SuppressDefault(): void
    {
        $this->assertFalse(DotEnv::isTruthy(DotEnv::getEnv('TINA4_SUPPRESS', 'false')));
    }

    public function testTina4SuppressOverride(): void
    {
        $this->setEnv(['TINA4_SUPPRESS' => 'true']);
        $this->assertTrue(DotEnv::isTruthy(DotEnv::getEnv('TINA4_SUPPRESS', 'false')));
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_ENV_FILE — alternate .env path (DotEnv.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4EnvFileDefault(): void
    {
        // No override means resolved path stays at caller-supplied default
        $this->assertNull(DotEnv::getEnv('TINA4_ENV_FILE'));
    }

    public function testTina4EnvFileOverride(): void
    {
        $altPath = $this->tempDir . '/custom.env';
        file_put_contents($altPath, "FOOBAR=fromAltEnv\n");
        $this->setEnv(['TINA4_ENV_FILE' => $altPath]);

        // loadEnv() with default '.env' should redirect to the override
        DotEnv::loadEnv();
        $this->assertSame('fromAltEnv', DotEnv::getEnv('FOOBAR'));

        // Cleanup
        putenv('FOOBAR');
        unset($_ENV['FOOBAR'], $_SERVER['FOOBAR']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_HEALTH_PATH — health endpoint URL (App.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4HealthPathDefault(): void
    {
        $this->assertNull(DotEnv::getEnv('TINA4_HEALTH_PATH'));
    }

    public function testTina4HealthPathOverride(): void
    {
        $this->setEnv(['TINA4_HEALTH_PATH' => '/__health']);
        $this->assertSame('/__health', DotEnv::getEnv('TINA4_HEALTH_PATH'));
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_TRAILING_SLASH_REDIRECT — 301 redirect (Router.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4TrailingSlashRedirectDefault(): void
    {
        $this->assertFalse(DotEnv::isTruthy(DotEnv::getEnv('TINA4_TRAILING_SLASH_REDIRECT', 'false')));
    }

    public function testTina4TrailingSlashRedirectOverride(): void
    {
        $this->setEnv(['TINA4_TRAILING_SLASH_REDIRECT' => 'true']);
        $this->assertTrue(DotEnv::isTruthy(DotEnv::getEnv('TINA4_TRAILING_SLASH_REDIRECT', 'false')));
    }

    public function testTrailingSlashRedirect301(): void
    {
        // Spin up a route, hit it with a trailing slash, expect a 301.
        \Tina4\Router::clear();
        \Tina4\Router::get('/foo', fn($req, $res) => $res->json(['ok' => true]));

        $this->setEnv(['TINA4_TRAILING_SLASH_REDIRECT' => 'true']);

        $request = new \Tina4\Request('GET', '/foo/');
        $response = new \Tina4\Response();
        $result = \Tina4\Router::dispatch($request, $response);

        $this->assertSame(301, $result->getStatusCode());
        $headers = $result->getHeaders();
        $location = $headers['location'] ?? $headers['Location'] ?? null;
        $this->assertSame('/foo', $location);
        \Tina4\Router::clear();
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_FILE — log file path (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogFileDefault(): void
    {
        // Log's per-field getters (logFile(), logDir(), ...) were REMOVED in the
        // logger_contract rewrite (2026-08-13, LOG-A02: configuration() is the
        // one introspection surface) -- assert through configuration() and by
        // reading back what was actually written, exactly as LogTest does.
        Log::configure(output: 'file', logDir: $this->tempDir);
        $this->assertNull(Log::configuration()['log_file'], 'no explicit file -> directory layout, no fixed log_file path');

        Log::info('default filename check');
        $this->assertFileExists($this->tempDir . '/tina4.log');
    }

    public function testTina4LogFileOverride(): void
    {
        $this->setEnv(['TINA4_LOG_FILE' => 'app.log']);
        Log::configure(output: 'file', logDir: $this->tempDir);
        $this->assertSame($this->tempDir . '/app.log', Log::configuration()['log_file']);

        Log::info('hello');
        $this->assertFileExists($this->tempDir . '/app.log');
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_DIR — log directory (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogDirDefault(): void
    {
        // A relative logDir resolves against the CWD (Log::configure() has no
        // implicit chdir), matching what configuration() actually reports.
        Log::configure();
        $this->assertSame(getcwd() . '/logs', Log::configuration()['log_dir']);
    }

    public function testTina4LogDirOverride(): void
    {
        $alt = $this->tempDir . '/custom_logs';
        $this->setEnv(['TINA4_LOG_DIR' => $alt]);
        Log::configure(output: 'file');
        $this->assertSame($alt, Log::configuration()['log_dir']);

        Log::info('routed via env');
        $this->assertFileExists($alt . '/tina4.log');
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_FORMAT — text vs json (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogFormatDefault(): void
    {
        // Superseded by the logger_contract rewrite (2026-08-13, Decision 3):
        // the 2026-08-01 "text always unless TINA4_LOG_FORMAT=json" rule is
        // gone. With TINA4_LOG_FORMAT unset the format now derives from
        // TINA4_DEBUG: JSON in production, TEXT only in dev (LogTest covers
        // that axis). TINA4_DEBUG is cleared by setUp(), so this is the
        // production default.
        Log::configure(output: 'file', logDir: $this->tempDir);
        $this->assertSame('json', Log::configuration()['format']);

        Log::info('json by default');
        $line = trim(file_get_contents($this->tempDir . '/tina4.log'));
        $decoded = json_decode($line, true);
        $this->assertNotNull($decoded);
        $this->assertSame('json by default', $decoded['message']);
    }

    public function testTina4LogFormatOverrideJson(): void
    {
        $this->setEnv(['TINA4_LOG_FORMAT' => 'json']);
        Log::configure(output: 'file', logDir: $this->tempDir);
        $this->assertSame('json', Log::configuration()['format']);

        Log::info('json override');
        $line = trim(file_get_contents($this->tempDir . '/tina4.log'));
        $decoded = json_decode($line, true);
        $this->assertNotNull($decoded);
        $this->assertSame('json override', $decoded['message']);
    }

    public function testTina4LogFormatOverrideText(): void
    {
        $this->setEnv(['TINA4_LOG_FORMAT' => 'text']);
        Log::configure(output: 'file', logDir: $this->tempDir);
        $this->assertSame('text', Log::configuration()['format']);

        Log::info('text override');
        $line = trim(file_get_contents($this->tempDir . '/tina4.log'));
        $this->assertNull(json_decode($line, true));
        $this->assertStringContainsString('text override', $line);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_OUTPUT — stdout / file / both (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogOutputDefaultInProduction(): void
    {
        // v3.13.39: with TINA4_LOG_OUTPUT unset, stdout is ALWAYS on but the
        // log FILE is written ONLY in dev (TINA4_DEBUG). In production /
        // containers the logger is stdout-only — a log file inside a container
        // just bloats the writable layer + disk, and 12-factor wants logs on
        // stdout for the platform to capture. (TINA4_DEBUG is cleared by
        // setUp, so this is the production default. The `development:` param
        // to configure() was removed in the logger_contract rewrite --
        // TINA4_DEBUG is read directly.)
        Log::configure(logDir: $this->tempDir);
        $config = Log::configuration();
        $this->assertTrue($config['stdout_enabled'], 'stdout is always on');
        $this->assertFalse(
            $config['file_enabled'],
            'production default is stdout-only (no file)'
        );
    }

    public function testTina4LogOutputDefaultInDev(): void
    {
        // v3.13.39: in development (TINA4_DEBUG truthy) the default still
        // writes a file alongside stdout — so local `tail -f logs/tina4.log`
        // keeps working. Mirrors Python master.
        $this->enableDevLogFile();
        Log::configure(logDir: $this->tempDir);
        $config = Log::configuration();
        $this->assertTrue($config['stdout_enabled']);
        $this->assertTrue(
            $config['file_enabled'],
            'dev default writes a file alongside stdout'
        );
    }

    public function testTina4LogOutputOverrideStdout(): void
    {
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'stdout']);
        Log::configure(logDir: $this->tempDir);
        $config = Log::configuration();
        $this->assertTrue($config['stdout_enabled']);
        $this->assertFalse($config['file_enabled']);

        // file output disabled — log call must not create the file
        Log::info('stdout-only');
        $this->assertFileDoesNotExist($this->tempDir . '/tina4.log');
    }

    public function testTina4LogOutputBoth(): void
    {
        $this->setEnv(['TINA4_LOG_OUTPUT' => 'both']);
        Log::configure(logDir: $this->tempDir);
        $config = Log::configuration();
        $this->assertTrue($config['stdout_enabled']);
        $this->assertTrue($config['file_enabled']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_CRITICAL — RETIRED (v3.13.39). critical is now a first-class
    // top-level severity that ALWAYS emits; the env-var "enable critical()"
    // toggle is gone and is no longer read.
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogCriticalAlwaysEmitsByDefault(): void
    {
        // No env var set — critical must still emit (no opt-in gate). Pin dev
        // so the FILE assertion holds (v3.13.39: file is dev-gated by default).
        $this->enableDevLogFile();
        Log::configure(logDir: $this->tempDir);

        Log::critical('always-emits');
        $this->assertFileExists($this->tempDir . '/tina4.log');
        $content = file_get_contents($this->tempDir . '/tina4.log');
        $this->assertStringContainsString('CRITICAL', $content);
        $this->assertStringContainsString('always-emits', $content);
    }

    public function testTina4LogCriticalEnvVarIsRetired(): void
    {
        // Superseded by the logger_contract rewrite (2026-08-13): TINA4_LOG_CRITICAL
        // is now a REMOVED setting (Log::REMOVED_SETTINGS) -- merely SETTING it
        // (any value, including a falsy one) is a hard configuration error,
        // rather than being silently accepted-but-ignored as before. critical()
        // has no toggle; it always emits, subject only to TINA4_LOG_LEVEL.
        $this->setEnv(['TINA4_LOG_CRITICAL' => 'false']);
        $this->expectException(LogConfigurationError::class);
        Log::configure(logDir: $this->tempDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_ROTATE_SIZE — rotation threshold in bytes (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogRotateSizeDefault(): void
    {
        Log::configure(logDir: $this->tempDir);
        $this->assertSame(Log::DEFAULT_ROTATE_SIZE, Log::configuration()['rotate_size']);
    }

    public function testTina4LogRotateSizeOverride(): void
    {
        $this->setEnv(['TINA4_LOG_ROTATE_SIZE' => '4096']);
        Log::configure(logDir: $this->tempDir);
        $this->assertSame(4096, Log::configuration()['rotate_size']);
    }

    public function testRotationTriggersOnSizeOverflow(): void
    {
        // logger_contract (LOG-V02) put a hard floor of Log::MIN_ROTATE_SIZE
        // (1024 bytes) on TINA4_LOG_ROTATE_SIZE -- use the floor itself so a
        // single pre-filled write still crosses it.
        $this->enableDevLogFile();
        $this->setEnv(['TINA4_LOG_ROTATE_SIZE' => (string) Log::MIN_ROTATE_SIZE, 'TINA4_LOG_ROTATE_KEEP' => '5']);
        Log::configure(logDir: $this->tempDir);

        $logFile = $this->tempDir . '/tina4.log';
        // Pre-fill above the threshold so the next write triggers rotation
        file_put_contents($logFile, str_repeat('x', Log::MIN_ROTATE_SIZE + 50));

        Log::info('post-rotate-entry');

        $this->assertFileExists($logFile . '.1');
        $rotated = file_get_contents($logFile . '.1');
        $this->assertStringContainsString('xxx', $rotated);

        $current = file_get_contents($logFile);
        $this->assertStringContainsString('post-rotate-entry', $current);
    }

    public function testRotationDisabledWhenSizeIsZero(): void
    {
        // Superseded by the logger_contract rewrite (2026-08-13, LOG-V02):
        // 0 is no longer a magic "disable rotation" value. TINA4_LOG_ROTATE_SIZE
        // has a hard floor of Log::MIN_ROTATE_SIZE (1024 bytes); 0 is now an
        // INVALID configuration that raises, matching all four frameworks.
        $this->setEnv(['TINA4_LOG_ROTATE_SIZE' => '0']);
        $this->expectException(LogConfigurationError::class);
        Log::configure(logDir: $this->tempDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_LOG_ROTATE_KEEP — number of rotated files to retain (Log.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4LogRotateKeepDefault(): void
    {
        Log::configure(logDir: $this->tempDir);
        $this->assertSame(Log::DEFAULT_ROTATE_KEEP, Log::configuration()['rotate_keep']);
    }

    public function testTina4LogRotateKeepOverride(): void
    {
        $this->setEnv(['TINA4_LOG_ROTATE_KEEP' => '2']);
        Log::configure(logDir: $this->tempDir);
        $this->assertSame(2, Log::configuration()['rotate_keep']);
    }

    public function testRotationKeepWindow(): void
    {
        // Threshold is Log::MIN_ROTATE_SIZE (the floor LOG-V02 enforces), keep
        // only 2 backups.
        $this->enableDevLogFile();
        $this->setEnv(['TINA4_LOG_ROTATE_SIZE' => (string) Log::MIN_ROTATE_SIZE, 'TINA4_LOG_ROTATE_KEEP' => '2']);
        Log::configure(logDir: $this->tempDir);

        $logFile = $this->tempDir . '/tina4.log';

        // Trigger 4 rotations.
        for ($i = 0; $i < 4; $i++) {
            file_put_contents($logFile, str_repeat("$i", Log::MIN_ROTATE_SIZE + 50));
            Log::info("entry-$i");
        }

        // .1 and .2 must exist; .3+ must not.
        $this->assertFileExists($logFile . '.1');
        $this->assertFileExists($logFile . '.2');
        $this->assertFileDoesNotExist($logFile . '.3');
        $this->assertFileDoesNotExist($logFile . '.4');
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SESSION_HTTPONLY — HttpOnly cookie attr (Session.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SessionHttpOnlyDefault(): void
    {
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringContainsString('HttpOnly', $cookie);
    }

    public function testTina4SessionHttpOnlyOverride(): void
    {
        $this->setEnv(['TINA4_SESSION_HTTPONLY' => 'false']);
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringNotContainsString('HttpOnly', $cookie);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SESSION_NAME — cookie name (Session.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SessionNameDefault(): void
    {
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringStartsWith('tina4_session=', $cookie);
    }

    public function testTina4SessionNameOverride(): void
    {
        $this->setEnv(['TINA4_SESSION_NAME' => 'app_sid']);
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringStartsWith('app_sid=', $cookie);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SESSION_SECURE — Secure cookie attr (Session.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SessionSecureDefault(): void
    {
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringNotContainsString('Secure', $cookie);
    }

    public function testTina4SessionSecureOverride(): void
    {
        $this->setEnv(['TINA4_SESSION_SECURE' => 'true']);
        $session = new Session();
        $session->start();
        $cookie = $session->cookieHeader();
        $this->assertStringContainsString('Secure', $cookie);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_TEMPLATE_CACHE_TTL — Frond template TTL (Frond.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4TemplateCacheTtlDefault(): void
    {
        $this->assertSame('0', DotEnv::getEnv('TINA4_TEMPLATE_CACHE_TTL', '0'));
    }

    public function testTina4TemplateCacheTtlOverride(): void
    {
        $this->setEnv(['TINA4_TEMPLATE_CACHE_TTL' => '300']);
        $this->assertSame('300', DotEnv::getEnv('TINA4_TEMPLATE_CACHE_TTL', '0'));

        // Smoke test: render still works under TTL config.
        $tplDir = $this->tempDir . '/tpls';
        mkdir($tplDir);
        file_put_contents($tplDir . '/hello.twig', 'Hi {{ name }}');
        $frond = new Frond($tplDir);
        $this->assertSame('Hi Tina', $frond->render('hello.twig', ['name' => 'Tina']));
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_GRAPHQL_AUTO_SCHEMA — fromOrm() gate (GraphQL.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4GraphqlAutoSchemaDefault(): void
    {
        $this->assertTrue(DotEnv::isTruthy(DotEnv::getEnv('TINA4_GRAPHQL_AUTO_SCHEMA', 'true')));
    }

    public function testTina4GraphqlAutoSchemaOverride(): void
    {
        $this->setEnv(['TINA4_GRAPHQL_AUTO_SCHEMA' => 'false']);
        $this->assertFalse(DotEnv::isTruthy(DotEnv::getEnv('TINA4_GRAPHQL_AUTO_SCHEMA', 'true')));
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_GRAPHQL_ENDPOINT — GraphQL URL (GraphQL.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4GraphqlEndpointDefault(): void
    {
        $this->assertSame('/graphql', GraphQL::resolvedEndpoint());
    }

    public function testTina4GraphqlEndpointOverride(): void
    {
        $this->setEnv(['TINA4_GRAPHQL_ENDPOINT' => '/api/gql']);
        $this->assertSame('/api/gql', GraphQL::resolvedEndpoint());
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_MAIL_IMAP_ENCRYPTION — IMAP encryption (Messenger.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4MailImapEncryptionDefault(): void
    {
        $msg = new Messenger();
        $this->assertSame('tls', $msg->getImapEncryption());
    }

    public function testTina4MailImapEncryptionOverride(): void
    {
        $this->setEnv(['TINA4_MAIL_IMAP_ENCRYPTION' => 'starttls']);
        $msg = new Messenger();
        $this->assertSame('starttls', $msg->getImapEncryption());
    }

    public function testTina4MailImapEncryptionInvalidFallsBackToTls(): void
    {
        $this->setEnv(['TINA4_MAIL_IMAP_ENCRYPTION' => 'bogus']);
        $msg = new Messenger();
        $this->assertSame('tls', $msg->getImapEncryption());
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_MCP — MCP enable toggle (MCP.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4McpDefaultProductionDisabled(): void
    {
        // No TINA4_DEBUG set => MCP disabled.
        $this->assertFalse(McpServer::isEnabled());
    }

    public function testTina4McpDefaultDebugEnabled(): void
    {
        $this->setEnv(['TINA4_DEBUG' => 'true']);
        $this->assertTrue(McpServer::isEnabled());
    }

    public function testTina4McpExplicitOverride(): void
    {
        // Explicit false beats TINA4_DEBUG=true.
        $this->setEnv(['TINA4_DEBUG' => 'true', 'TINA4_MCP' => 'false']);
        $this->assertFalse(McpServer::isEnabled());

        // Explicit true beats TINA4_DEBUG unset.
        $this->setEnv(['TINA4_MCP' => 'true']);
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
        $this->assertTrue(McpServer::isEnabled());
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_MCP_PORT — MCP port (MCP.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4McpPortDefault(): void
    {
        $this->assertSame(7145 + 2000, McpServer::resolvePort(7145));
        $this->assertSame(8080 + 2000, McpServer::resolvePort(8080));
    }

    public function testTina4McpPortOverride(): void
    {
        $this->setEnv(['TINA4_MCP_PORT' => '9999']);
        $this->assertSame(9999, McpServer::resolvePort(7145));
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SWAGGER_CONTACT_EMAIL — Swagger contact (Swagger.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SwaggerContactEmailDefault(): void
    {
        \Tina4\Router::clear();
        $spec = Swagger::generate();
        $this->assertArrayNotHasKey('contact', $spec['info']);
    }

    public function testTina4SwaggerContactEmailOverride(): void
    {
        $this->setEnv(['TINA4_SWAGGER_CONTACT_EMAIL' => 'support@example.com']);
        \Tina4\Router::clear();
        $spec = Swagger::generate();
        $this->assertSame('support@example.com', $spec['info']['contact']['email']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SWAGGER_ENABLED — Swagger UI toggle (Swagger.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SwaggerEnabledDefaultProduction(): void
    {
        $this->assertFalse(Swagger::isEnabled());
    }

    public function testTina4SwaggerEnabledDefaultDebug(): void
    {
        $this->setEnv(['TINA4_DEBUG' => 'true']);
        $this->assertTrue(Swagger::isEnabled());
    }

    public function testTina4SwaggerEnabledExplicitOverride(): void
    {
        $this->setEnv(['TINA4_DEBUG' => 'true', 'TINA4_SWAGGER_ENABLED' => 'false']);
        $this->assertFalse(Swagger::isEnabled());
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_SWAGGER_LICENSE — Swagger license (Swagger.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4SwaggerLicenseDefault(): void
    {
        \Tina4\Router::clear();
        $spec = Swagger::generate();
        $this->assertArrayNotHasKey('license', $spec['info']);
    }

    public function testTina4SwaggerLicenseOverride(): void
    {
        $this->setEnv(['TINA4_SWAGGER_LICENSE' => 'MIT']);
        \Tina4\Router::clear();
        $spec = Swagger::generate();
        $this->assertSame('MIT', $spec['info']['license']['name']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TINA4_DB_POOL — connection pool default (Database.php)
    // ─────────────────────────────────────────────────────────────────

    public function testTina4DbPoolDefault(): void
    {
        // SQLite in-memory is fine for verifying the pool size accessor.
        $db = new Database('sqlite::memory:');
        $this->assertSame(0, $db->poolSize());
    }

    public function testTina4DbPoolOverride(): void
    {
        $this->setEnv(['TINA4_DB_POOL' => '3']);
        $db = new Database('sqlite::memory:');
        $this->assertSame(3, $db->poolSize());
    }

    public function testTina4DbPoolConstructorArgWinsOverEnv(): void
    {
        // Constructor explicit nonzero arg always overrides env.
        $this->setEnv(['TINA4_DB_POOL' => '3']);
        $db = new Database('sqlite::memory:', null, '', '', 5);
        $this->assertSame(5, $db->poolSize());
    }
}
