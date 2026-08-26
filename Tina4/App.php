<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Main application entry point for Tina4 v3.
 * Ties together configuration, routing, middleware, and lifecycle management.
 */
class App
{
    /**
     * Framework version. DECLARED HERE, not resolved at runtime.
     *
     * This is the single source of truth, and the release bumps it. Ruby does the
     * same with Tina4::VERSION; Python and Node read their package manifests,
     * which those ecosystems ship inside the installed package.
     *
     * It used to default to '0.0.0' and be resolved on boot from composer
     * metadata. That could not work in the base Docker image and had been
     * reporting '0.0.0' on /health in every published image: the resolver read
     * composer.json's `version` key (this repo has none -- Packagist derives the
     * version from git tags) and then vendor/composer/installed.json for
     * `tina4stack/tina4php` (absent, because in THIS repo Tina4 is the project,
     * not a vendored dependency). Both paths missed, so it fell to the literal.
     *
     * A declared constant cannot miss. A Packagist install ships the source of
     * its own tag, so the literal in that copy is that tag by construction.
     *
     * @var string
     */
    public static string $VERSION = '3.13.119';

    /**
     * The health path that is registered no matter what TINA4_HEALTH_PATH says.
     * Probes written against it must keep working when an operator configures a
     * different path, so this is an alias that is only ever added, never moved.
     */
    public const HEALTH_LEGACY_PATH = '/health';

    /**
     * The health path used when TINA4_HEALTH_PATH is unset. Identical in all
     * four frameworks; the under-prefix keeps it clear of an application route
     * that happens to be called /health.
     */
    public const HEALTH_DEFAULT_PATH = '/__health';

    /**
     * Legacy env var names that v3.12 retired. Each maps to its new
     * TINA4_-prefixed canonical name. If any of these are set in the
     * environment we refuse to boot — silently ignoring them would
     * cause auth/db/mail to fall back to defaults with no warning.
     *
     * Bypass with TINA4_ALLOW_LEGACY_ENV=true in CI / migration scripts
     * that genuinely need both names set during a transition window.
     *
     * @var array<string, string>
     */
    public const LEGACY_ENV_VARS = [
        'DATABASE_URL'           => 'TINA4_DATABASE_URL',
        'DATABASE_USERNAME'      => 'TINA4_DATABASE_USERNAME',
        'DATABASE_PASSWORD'      => 'TINA4_DATABASE_PASSWORD',
        'DB_URL'                 => 'TINA4_DATABASE_URL',
        'SECRET'                 => 'TINA4_SECRET',
        'API_KEY'                => 'TINA4_API_KEY',
        'JWT_ALGORITHM'          => 'TINA4_JWT_ALGORITHM',
        'SMTP_HOST'              => 'TINA4_MAIL_HOST',
        'SMTP_PORT'              => 'TINA4_MAIL_PORT',
        'SMTP_USERNAME'          => 'TINA4_MAIL_USERNAME',
        'SMTP_PASSWORD'          => 'TINA4_MAIL_PASSWORD',
        'SMTP_FROM'              => 'TINA4_MAIL_FROM',
        'SMTP_FROM_NAME'         => 'TINA4_MAIL_FROM_NAME',
        'IMAP_HOST'              => 'TINA4_MAIL_IMAP_HOST',
        'IMAP_PORT'              => 'TINA4_MAIL_IMAP_PORT',
        'IMAP_USER'              => 'TINA4_MAIL_IMAP_USERNAME',
        'IMAP_PASS'              => 'TINA4_MAIL_IMAP_PASSWORD',
        'HOST_NAME'              => 'TINA4_HOST_NAME',
        'SWAGGER_TITLE'          => 'TINA4_SWAGGER_TITLE',
        'SWAGGER_DESCRIPTION'    => 'TINA4_SWAGGER_DESCRIPTION',
        'SWAGGER_VERSION'        => 'TINA4_SWAGGER_VERSION',
        'ORM_PLURAL_TABLE_NAMES' => 'TINA4_ORM_PLURAL_TABLE_NAMES',
    ];

    /**
     * Refuse to boot if pre-3.12 un-prefixed env vars are still set.
     *
     * Tina4 v3.12 hard-renamed every framework-specific env var to use
     * the TINA4_ prefix. Booting silently with a legacy DATABASE_URL or
     * SECRET would let auth, DB, or mail fall back to insecure defaults
     * while the user thought their config was being read. Better to die
     * loudly with a list of names to fix.
     *
     * Bypass with TINA4_ALLOW_LEGACY_ENV=true in CI / migration scripts
     * that genuinely need both names set during a transition window.
     *
     * @param bool $exit If true, exit(2) on failure (default). If false, throws RuntimeException.
     * @return void
     * @throws \RuntimeException if $exit=false and legacy vars are set
     */
    public static function checkLegacyEnvVars(bool $exit = true): void
    {
        $bypass = strtolower((string)(getenv('TINA4_ALLOW_LEGACY_ENV') ?: ($_ENV['TINA4_ALLOW_LEGACY_ENV'] ?? '')));
        if (in_array($bypass, ['true', '1', 'yes'], true)) {
            return;
        }

        $found = [];
        foreach (self::LEGACY_ENV_VARS as $old => $new) {
            $val = getenv($old);
            if ($val !== false || array_key_exists($old, $_ENV) || array_key_exists($old, $_SERVER)) {
                $found[] = $old;
            }
        }
        if ($found === []) {
            return;
        }
        sort($found);

        $sep = str_repeat('─', 72);
        $lines = [
            '',
            $sep,
            'Tina4 v3.12 requires TINA4_ prefix on all framework env vars.',
            'Your environment still has these legacy names:',
            '',
        ];
        foreach ($found as $old) {
            $new = self::LEGACY_ENV_VARS[$old];
            $lines[] = sprintf('    %-28s  ->  %s', $old, $new);
        }
        $lines[] = '';
        $lines[] = 'Note: these may come from a .env file loaded by dotenv, not just';
        $lines[] = 'the runtime environment - check your image / build context (a .env';
        $lines[] = 'baked into a Docker image is loaded at startup) as well as k8s/CI env.';
        $lines[] = '';
        $lines[] = 'FIX: run `tina4 env --migrate` to rewrite your .env automatically';
        $lines[] = '(it renames every legacy name to its TINA4_ form in place).';
        $lines[] = 'Or rename manually. See https://tina4.com/release/3.12.0';
        $lines[] = 'Set TINA4_ALLOW_LEGACY_ENV=true to bypass during migration.';
        $lines[] = $sep;
        $lines[] = '';
        $msg = implode("\n", $lines);

        if ($exit) {
            // v3.13.14 (#119): the STDERR constant is only auto-defined for the
            // `cli` SAPI — NOT for `cli-server` (the built-in dev server) or
            // some FPM setups. In `namespace Tina4` a bare `STDERR` resolves to
            // `Tina4\STDERR` then the global `\STDERR`; when neither exists the
            // legacy-env guard died with "Undefined constant Tina4\STDERR",
            // masking the actionable migration message. Use the php://stderr
            // stream, which every SAPI provides.
            $stderr = defined('STDERR') ? \STDERR : fopen('php://stderr', 'wb');
            fwrite($stderr, $msg);
            exit(2);
        }
        throw new \RuntimeException($msg);
    }

    /** @var Database\DatabaseAdapter|null Shared database instance */
    private static ?Database\DatabaseAdapter $database = null;

    /** @var float Application start time for uptime tracking */
    private readonly float $startTime;

    /** @var bool Whether the app is running */
    private bool $running = false;

    /** @var array<callable> Registered shutdown callbacks */
    private array $shutdownCallbacks = [];

    /** @var array<string, mixed> Registered routes: method => [pattern => handler] */
    private array $routes = [];

    /** @var array<callable> Middleware stack */
    private array $middleware = [];

    /** @var bool Whether this instance set an error handler */
    private bool $errorHandlerSet = false;

    /** @var bool Whether this instance set an exception handler */
    private bool $exceptionHandlerSet = false;

    /**
     * Whether a shutdown handler has been registered at the
     * class (static) level. PHP offers no way to unregister a
     * shutdown function, so we install exactly one per process —
     * repeated App constructions in the same process (e.g. during
     * test runs) must not stack handlers.
     */
    private static bool $shutdownHandlerRegistered = false;

    /** @var bool Guard so startup auto-migration runs at most once per process. */
    private static bool $autoMigrated = false;

    private string $basePath;

    /** @var array<array{callback: callable, interval: float}> Pending tick callbacks to register on server start */
    private array $tickCallbacks = [];

    /** @var Server|null The running server, once run() has started it. Lets stopBackground() reach a live tick. */
    private ?Server $server = null;

    public function __construct(
        string $basePath = '',
        private readonly bool $development = false,
    ) {
        // Default basePath to current working directory. This works correctly
        // whether called from index.php or via the CLI (tina4php serve).
        if ($basePath === '') {
            $basePath = getcwd();
        }
        $this->basePath = $basePath;
        $this->startTime = microtime(true);

        $this->errorHandlerSet = true;

        // Error handler: convert warnings and user errors to exceptions so they
        // are caught by try/catch blocks. Notices and deprecations are logged
        // but don't crash the application — this prevents autoloaded files with
        // minor issues from killing the entire server on startup.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // Respect @ suppression operator
            }
            // Throw for errors and warnings — these indicate real problems
            $throwable = E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR;
            if ($severity & $throwable) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            // Log notices and deprecations without crashing
            Log::warning("[PHP] {$message} in {$file}:{$line}");
            return true;
        });

        // Note: set_exception_handler() and register_shutdown_function()
        // are NOT installed here — they live in start() instead.
        // Rationale: tests construct App heavily but only a few call
        // start(). Installing the exception handler in __construct
        // leaks it past test teardown (PHPUnit flags each test risky)
        // because __destruct doesn't run reliably mid-test. Moving
        // the install into start() keeps the production behaviour
        // identical and stops tests from needing per-test cleanup.

        // Set base path for static file serving
        Router::$basePath = $this->basePath;

        // Load environment with strict precedence: real-env > .env.local > .env.
        //
        // DotEnv::loadEnv(overwrite: false) is FIRST-WINS — it sets a key only
        // if it is not already present in the process env. So to get the
        // precedence above we load in *priority order*, each with overwrite
        // FALSE: a variable already set by the real environment (exported
        // before boot) is never clobbered, then .env.local fills local-only
        // keys, then .env fills whatever remains.
        //
        // NOTE: .env.local is loaded BEFORE .env (never with overwrite: true).
        // A stray gitignored .env.local — e.g. one auto-generated on a prior
        // dev boot — must NEVER override a value explicitly set in the real
        // process environment (that broke an integration test where a real
        // TINA4_SECRET was signed against, and is security-relevant for a real
        // production TINA4_SECRET).
        $envLocal = $this->basePath . DIRECTORY_SEPARATOR . '.env.local';
        if (is_file($envLocal)) {
            DotEnv::loadEnv($envLocal, overwrite: false);
        }

        // TINA4_ENV_FILE may override the default '.env' location. Probe the
        // env *before* loading so a process-level export wins; fall back to
        // the default path. Loaded LAST (lowest precedence), overwrite false.
        $envFileName = DotEnv::getEnv('TINA4_ENV_FILE') ?? '.env';
        $envFile = self::isAbsolutePath($envFileName)
            ? $envFileName
            : $this->basePath . DIRECTORY_SEPARATOR . $envFileName;
        if (is_file($envFile)) {
            DotEnv::loadEnv($envFile, overwrite: false);
        }

        // Dev-secret bootstrap — run once at boot, after env load and before
        // auth is used. In dev (TINA4_DEBUG truthy, not CI, not production)
        // with a blank TINA4_SECRET this generates a per-machine secret and
        // persists it to .env.local (gitignored). In CI/prod it only emits an
        // actionable warning. Never throws — boot must not crash.
        Auth::ensureDevSecret($this->basePath);

        // Configure logger. Bootstrap invents NO explicit defaults (LOG-I01 /
        // Decision 17): call configure() with no arguments so level/format/
        // output/log_dir all resolve purely from TINA4_LOG_* env + the
        // framework default, exactly like any other first-use caller. This
        // used to pass "$basePath/logs" and a computed `development` flag as
        // if they were the caller's own instructions, which is what made
        // TINA4_LOG_DIR dead in every booted app once the env>argument
        // precedence bug (ADR-0041) was fixed elsewhere -- the fix is to
        // never pass a framework-computed value through the argument channel
        // at all, not to keep re-deriving one that happens to agree with env.
        Log::configure();

        // NOTE: the request id is generated PER REQUEST in Router::dispatch()
        // (feature 43), not here. A single id minted in the constructor is
        // process-scoped - under the long-running built-in server every request
        // in the process would share it, which is useless for correlation.

        // ext-openssl is SUGGESTED, not required — HMAC JWT, PBKDF2 passwords and
        // random_bytes all need none of it. But PHP's `https` stream wrapper is
        // registered by that extension, so without it EVERY outbound HTTPS call
        // from Tina4\Api dies, and PHP blames a missing file rather than a
        // missing extension. Nothing greps its way to that dependency — it rides
        // on the URL scheme — so announce it once here, at boot, instead of
        // letting each call site rediscover it.
        if (!Api::httpsAvailable()) {
            Log::warning(Api::HTTPS_UNAVAILABLE);
        }

        // Register health check
        $this->registerHealthCheck();

        // Register the Frond live-block re-render endpoint (always on)
        $this->registerLiveEndpoint();

        // NOTE: no signal handlers are registered here. Server::start() owns
        // SIGTERM/SIGINT (PHP keeps exactly ONE handler per signal, and the
        // server registers last, so a pair installed here would be replaced and
        // never run). Worse, on any path that never reaches the event loop
        // nothing calls pcntl_signal_dispatch(), so an installed-but-never-
        // dispatched handler leaves the process IGNORING SIGTERM entirely —
        // `kill` and `docker stop` become no-ops. run() wires shutdown() onto
        // the process exit path instead, which fires on every exit route.
    }

    public function __destruct()
    {
        if ($this->errorHandlerSet) {
            restore_error_handler();
            $this->errorHandlerSet = false;
        }
        if ($this->exceptionHandlerSet) {
            restore_exception_handler();
            $this->exceptionHandlerSet = false;
        }
    }

    /**
     * Register a GET route.
     */
    public function get(string $path, callable $handler): self
    {
        $this->routes['GET'][$path] = $handler;
        return $this;
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, callable $handler): self
    {
        $this->routes['POST'][$path] = $handler;
        return $this;
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, callable $handler): self
    {
        $this->routes['PUT'][$path] = $handler;
        return $this;
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, callable $handler): self
    {
        $this->routes['DELETE'][$path] = $handler;
        return $this;
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, callable $handler): self
    {
        $this->routes['PATCH'][$path] = $handler;
        return $this;
    }

    /**
     * Add middleware to the stack.
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Register a callback for graceful shutdown.
     */
    public function onShutdown(callable $callback): self
    {
        $this->shutdownCallbacks[] = $callback;
        return $this;
    }

    /**
     * Get the health check response data.
     *
     * @return array<string, mixed>
     */
    public function getHealthData(): array
    {
        return [
            'status' => 'ok',
            'version' => self::$VERSION,
            'uptime' => round(microtime(true) - $this->startTime, 2),
            'framework' => 'tina4-php',
        ];
    }

    /**
     * Get the application start time.
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * Get the project base path. Used by the dev reload endpoint to locate
     * src/routes/ for re-discovery on file changes.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Check if the app is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, array<string, callable>>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get the middleware stack.
     *
     * @return array<callable>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Perform graceful shutdown.
     */
    public function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        Log::info('Shutting down Tina4 application');

        foreach ($this->shutdownCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('Shutdown callback error: ' . $e->getMessage());
            }
        }

        // Restore the exception handler installed by start() so the
        // caller's handler stack is left clean. We don't try to un-
        // register the fatal-error shutdown function (PHP doesn't
        // support it); that one is guarded by self::$shutdownHandlerRegistered
        // and its body is a no-op when error_get_last() is null.
        if ($this->exceptionHandlerSet) {
            restore_exception_handler();
            $this->exceptionHandlerSet = false;
        }

        $this->running = false;
        Log::info('Tina4 application stopped');
    }

    /**
     * Start the application (mark as running).
     * Auto-discovers routes from src/routes/ and registers the default landing page
     * if no user-defined "/" route exists.
     */
    public function start(): void
    {
        // Refuse to boot with v3.11 / v2 era un-prefixed env vars set.
        // See self::LEGACY_ENV_VARS / self::checkLegacyEnvVars().
        self::checkLegacyEnvVars();

        $this->running = true;

        // In debug mode, wipe opcache on server boot so stale bytecode
        // from a previous session doesn't shadow file edits made
        // between runs. The user-visible symptom we hit before this
        // was: AI patches `$response->template()` to `$response->render()`,
        // file on disk is correct, but the runtime keeps reporting
        // "Call to undefined method Tina4\Response::template()" because
        // PHP is executing cached opcodes from before the patch. Each
        // /__dev/api/reload POST also invalidates the touched path
        // (see DevAdmin route handler) so no restart is needed for
        // mid-session edits.
        if ($this->isDevelopment() && function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Install global exception + fatal-error capture. Done here
        // rather than in __construct so tests that don't call start()
        // don't leak handlers across the suite. The matching restore
        // happens in stop() / __destruct.
        //
        // Exception handler: any uncaught Throwable from a route,
        // middleware, or bootstrap path gets logged with a full trace
        // and recorded in the dev-toolbar's ErrorTracker. Without
        // this, an uncaught exception bypasses Tina4\Log entirely
        // and surfaces only via PHP's default error output.
        if (!$this->exceptionHandlerSet) {
            set_exception_handler(static function (\Throwable $e): void {
                $msg = sprintf(
                    'Uncaught %s: %s in %s:%d',
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                );
                Log::error($msg, ['trace' => $e->getTraceAsString()]);
                if (class_exists(ErrorTracker::class)) {
                    ErrorTracker::capture(
                        get_class($e),
                        $e->getMessage(),
                        $e->getTraceAsString(),
                        $e->getFile(),
                        $e->getLine()
                    );
                }
            });
            $this->exceptionHandlerSet = true;
        }

        // Shutdown handler: fatal errors (E_ERROR, E_PARSE,
        // E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR) kill the
        // process before any set_error_handler runs, so they'd be
        // invisible without this. PHP has no API to remove a
        // shutdown function, so we guard with a class-level flag —
        // repeated App::start() calls in the same process (test
        // runs, long-running workers with restarts) don't stack.
        if (!self::$shutdownHandlerRegistered) {
            register_shutdown_function(static function (): void {
                $err = error_get_last();
                if ($err === null) {
                    return;
                }
                $fatal = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR;
                if (!($err['type'] & $fatal)) {
                    return;
                }
                Log::error(sprintf(
                    '[FATAL] %s in %s:%d',
                    $err['message'],
                    $err['file'],
                    $err['line']
                ));
                if (class_exists(ErrorTracker::class)) {
                    ErrorTracker::capture(
                        'FatalError',
                        $err['message'],
                        '',
                        $err['file'],
                        $err['line']
                    );
                }
            });
            self::$shutdownHandlerRegistered = true;
        }

        // Register dev admin dashboard (only in development mode)
        if ($this->isDevelopment()) {
            DevAdmin::register();
        }

        // Auto-discover ORM models from src/orm/ — BEFORE routes, because a
        // route doing `new Product()` needs the class to already exist. Same
        // convention as routes/seeds/services: location IS configuration, no
        // require_once and no composer.json edit. Mirrors the Python master,
        // which imports every module under src/ at boot.
        $modelsDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'orm';
        if (is_dir($modelsDir)) {
            try {
                ModelDiscovery::scan($modelsDir);
            } catch (\Throwable $e) {
                Log::error("Model discovery failed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
            }
        }

        // Auto-discover routes from src/routes/
        $routesDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'routes';
        if (is_dir($routesDir)) {
            try {
                RouteDiscovery::scan($routesDir);
            } catch (\Throwable $e) {
                Log::error("Route discovery failed: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
            }
        }

        // Configuration-first OIDC routes mount after application discovery so
        // a canonical-path collision fails loudly rather than being replaced.
        Sso::mountConfigured();

        // Auto-attach CSRF protection when TINA4_CSRF is truthy (true/1/yes/on).
        // OFF by default (unset → not attached); the env flag is the switch, and
        // once attached TINA4_CSRF=false is the kill switch. Idempotent. Done
        // after route discovery (so the middleware sees a full route table) and
        // before serving. Mirrors the Python master's attach_csrf_from_env().
        \Tina4\Middleware\CsrfMiddleware::attachFromEnv();

        // Security headers: register in the default chain UNCONDITIONALLY
        // (secure-by-default, SECHDR-DEC-01). Unlike CSRF this needs no opt-in — a
        // default app ships X-Frame-Options/X-Content-Type-Options/CSP/etc. with no
        // code change. HSTS stays HTTPS-only. Idempotent.
        \Tina4\Middleware\SecurityHeadersMiddleware::attach();

        // Auto-wire i18n → template global t() if locale files exist
        $this->autoWireI18n();

        // Register Swagger/OpenAPI routes
        Swagger::register();

        // Register default landing page if no "/" route exists
        $this->registerLandingPage();

        // Apply pending DB migrations on startup (after DB bind + route
        // discovery, before serving) — NON-BREAKING. See autoMigrateOnStartup().
        $this->autoMigrateOnStartup();

        Log::info('Tina4 v' . self::$VERSION . ' started', [
            'base_path' => $this->basePath,
            'development' => $this->development,
        ]);
    }

    /**
     * Apply pending DB migrations on startup — NON-BREAKING.
     *
     * When a `migrations/` folder exists (with at least one `.sql` file) and
     * `TINA4_AUTO_MIGRATE` is not disabled, pending migrations are applied
     * during boot so the schema is current with no manual `tina4 migrate`
     * step. A failure here is logged LOUD and the service STILL starts — a bad
     * migration must never take the backend down. (The explicit `tina4 migrate`
     * CLI stays fail-fast so CI still gets a non-zero exit.)
     *
     * Disable with `TINA4_AUTO_MIGRATE=false` (also false/0/no/off) — e.g.
     * multi-instance production that migrates as a separate deploy step
     * (concurrent first-apply can race).
     */
    private function autoMigrateOnStartup(): void
    {
        // Run at most once per process — start() may be re-entered per request
        // under PHP-FPM / php -S (via __invoke()/handle()); migrations must not
        // re-run on every request.
        if (self::$autoMigrated) {
            return;
        }
        self::$autoMigrated = true;

        $folder = $this->basePath . DIRECTORY_SEPARATOR . 'migrations';

        // No migrations folder, or no .sql files in it → nothing to do (silent).
        if (!is_dir($folder) || empty(glob($folder . DIRECTORY_SEPARATOR . '*.sql'))) {
            return;
        }

        // Honour the kill switch: false/0/no/off disable (default 'true').
        if (!DotEnv::isTruthy(DotEnv::getEnv('TINA4_AUTO_MIGRATE', 'true'))) {
            Log::debug('TINA4_AUTO_MIGRATE is off — skipping startup migrations');
            return;
        }

        // Resolve a database — falls back to TINA4_DATABASE_URL via getDatabase().
        $db = self::getDatabase();
        if ($db === null) {
            Log::debug('Startup migrations skipped (no database configured)');
            return;
        }

        try {
            $migration = new Migration($db, $folder);
            $result = $migration->migrate();

            // The runner collects per-file failures into 'errors' rather than
            // throwing; treat a non-empty 'errors' set as a failure too. A raw
            // throw (e.g. a bad PHP migration) is caught below — either way the
            // service still boots.
            if (!empty($result['errors'])) {
                $first = (string) reset($result['errors']);
                Log::error(
                    "Startup auto-migration failed: {$first} — the service is "
                    . 'starting anyway. Run `tina4 migrate` to retry.'
                );
            } elseif (!empty($result['applied'])) {
                $count = count($result['applied']);
                Log::info("Applied {$count} pending migration(s) on startup");
            }
        } catch (\Throwable $e) {
            Log::error(
                "Startup auto-migration failed: {$e->getMessage()} — the service "
                . 'is starting anyway. Run `tina4 migrate` to retry.'
            );
        }
    }

    /**
     * Whether the app is running in development mode.
     */
    public function isDevelopment(): bool
    {
        return $this->development || DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
    }

    /**
     * Register the health check endpoint.
     *
     * The path is configurable via TINA4_HEALTH_PATH and defaults to
     * '/__health', matching Python, Ruby and Node and the documented default in
     * docs/php/33-environment-variables.md. '/health' is ALWAYS registered as
     * well, whatever the configured path, because it is what a probe that was
     * written before the env var existed points at: setting TINA4_HEALTH_PATH
     * must add a path, never take one away. PHP previously defaulted to
     * '/health' and registered the configured path ONLY, so '/__health' 404ed
     * on a default install and '/health' 404ed the moment an operator set the
     * env var -- either of which silently takes a pod out of rotation.
     */
    private function registerHealthCheck(): void
    {
        $handler = function (Request $request, Response $response) {
            return $response->json($this->getHealthData());
        };

        $envPath = DotEnv::getEnv('TINA4_HEALTH_PATH');
        $path = ($envPath !== null && $envPath !== '') ? $envPath : self::HEALTH_DEFAULT_PATH;
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        Router::get($path, $handler);
        $this->routes['GET'][$path] = fn() => $this->getHealthData();

        if ($path !== self::HEALTH_LEGACY_PATH) {
            Router::get(self::HEALTH_LEGACY_PATH, $handler);
            $this->routes['GET'][self::HEALTH_LEGACY_PATH] = fn() => $this->getHealthData();
        }
    }

    /**
     * Register GET /__frond/live/{name} - re-renders a {% live %} fragment on
     * demand (poll / sse pull). Always on (production too); the @live_source
     * provider runs with the live request so auth/session re-apply every
     * refresh. 404 for an unknown name or a fragment whose page has not
     * rendered. Mirrors Python's live_endpoint.
     */
    private function registerLiveEndpoint(): void
    {
        Router::get('/__frond/live/{name}', function (Request $request, Response $response, $name) {
            return \Tina4\Frond::respondLive($request, $response, $name);
        });
    }

    /**
     * True when $path is absolute on the current platform.
     */
    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '\\\\');
        }
        return $path[0] === '/';
    }

    /**
     * Auto-wire i18n into the template engine.
     *
     * If src/locales/ contains .json locale files and the user hasn't already
     * registered a 't' global on the Frond engine, create an I18n instance
     * (respecting TINA4_LOCALE and TINA4_LOCALE_DIR env vars) and register
     * t() as a template global so templates can use {{ t("key") }}.
     */
    private function autoWireI18n(): void
    {
        $localeDir = $this->basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'locales';

        // Check env override for locale directory
        $envDir = getenv('TINA4_LOCALE_DIR');
        if ($envDir !== false && $envDir !== '') {
            $localeDir = $envDir;
        }

        // Only proceed if locale directory exists and contains .json files
        if (!is_dir($localeDir)) {
            return;
        }

        $hasJsonFiles = false;
        $files = scandir($localeDir);
        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $hasJsonFiles = true;
                break;
            }
        }

        if (!$hasJsonFiles) {
            return;
        }

        // Don't overwrite a user-registered t() global
        $frond = Response::getFrond();
        $globals = $frond->getGlobals();
        if (isset($globals['t'])) {
            return;
        }

        // Create the I18n instance — constructor is (locale, path); pass null for
        // locale so it resolves from TINA4_LOCALE (then 'en'), and the discovered
        // directory as the path.
        $i18n = new I18n(null, $localeDir);

        // Register t() as a callable template global
        $frond->addGlobal('t', function (string $key, array $params = [], ?string $locale = null) use ($i18n): string {
            return $i18n->translate($key, $params, $locale);
        });
    }

    /**
     * Register the default Tina4 landing page.
     *
     * The framework's branded welcome page renders at "/" ONLY when
     * TINA4_DEBUG=true. When debug is off, the route is not registered at all,
     * so "/" falls through to static / template resolution and finally a 404.
     * This is symmetric with how /__dev/* gates on TINA4_DEBUG — the framework
     * version, dev-admin link, and gallery never leak to real users.
     *
     * If the user has a "/" route, or a src/templates/pages/index.* template,
     * that takes precedence over the landing page.
     */
    private function registerLandingPage(): void
    {
        // Check if user already registered a "/" route
        $routes = Router::getRoutes();
        foreach ($routes as $r) {
            if ($r['pattern'] === '/' && $r['method'] === 'GET') {
                return; // User has their own landing page
            }
        }

        // Check if user has a pages/index.* template — leave the auto-router
        // to handle it. Don't register an explicit "/" route, so the Router
        // can resolve it via the same pages/ pipeline as every other URL.
        $pagesDir = $this->basePath
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'templates'
            . DIRECTORY_SEPARATOR . 'pages';
        foreach (['index.twig', 'index.html'] as $tpl) {
            if (is_file($pagesDir . DIRECTORY_SEPARATOR . $tpl)) {
                return;
            }
        }

        // Production hides the landing page entirely. /__dev/* already gates
        // on TINA4_DEBUG; the landing page is the same kind of dev-only
        // surface (framework version, gallery, deploy buttons).
        if (!$this->isDevelopment()) {
            return;
        }

        $version = self::$VERSION;
        $isDev = true;

        Router::get('/', function (Request $request, Response $response) use ($isDev, $version) {
            return $response->html(self::renderLandingPage($version, $isDev));
        });
    }

    /**
     * Render the built-in Tina4 landing page HTML.
     */
    /**
     * Check if a gallery item's files exist in the project's src/ folder.
     */
    private static function isGalleryDeployed(string $name): bool
    {
        $galleryDir = __DIR__ . '/gallery/' . $name;
        $metaFile = $galleryDir . '/meta.json';
        if (!file_exists($metaFile)) {
            return false;
        }
        $srcDir = $galleryDir . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }
        $projectSrc = getcwd() . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $srcDirNorm = str_replace('\\', '/', $srcDir);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $pathname = str_replace('\\', '/', $file->getPathname());
                $rel = str_replace($srcDirNorm . '/', '', $pathname);
                if (!file_exists($projectSrc . '/' . $rel)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Render a Try It or View button depending on deployment state.
     */
    private static function galleryBtn(string $name, string $tryUrl): string
    {
        if (self::isGalleryDeployed($name)) {
            return '<button class="try-btn" style="background:#22c55e;" onclick="window.open(\'' . $tryUrl . '\',\'_blank\')" data-deployed="1">View &#8599;</button>';
        }
        return '<button class="try-btn" onclick="deployGallery(\'' . $name . '\',\'' . $tryUrl . '\')">Try It</button>';
    }

    private static function renderLandingPage(string $version, bool $isDev): string
    {
        $port = $_SERVER['SERVER_PORT'] ?? getenv('TINA4_PORT') ?: getenv('PORT') ?: '7145';

        $btnRestApi = self::galleryBtn('rest-api', '/api/gallery/hello');
        $btnOrm = self::galleryBtn('orm', '/api/gallery/products');
        $btnAuth = self::galleryBtn('auth', '/gallery/auth');
        $btnQueue = self::galleryBtn('queue', '/api/gallery/queue/status');
        $btnTemplates = self::galleryBtn('templates', '/gallery/page');
        $btnDatabase = self::galleryBtn('database', '/api/gallery/db/tables');
        $btnErrorOverlay = self::galleryBtn('error-overlay', '/api/gallery/crash');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tina4Php</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;position:relative}
.bg-watermark{position:fixed;bottom:-5%;right:-5%;width:45%;opacity:0.04;pointer-events:none;z-index:0}
.hero{text-align:center;z-index:1;padding:3rem 2rem 2rem}
.logo{width:120px;height:120px;margin-bottom:1.5rem}
h1{font-size:3rem;font-weight:700;margin-bottom:0.25rem;letter-spacing:-1px}
.tagline{color:#64748b;font-size:1.1rem;margin-bottom:2rem}
.actions{display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:2.5rem}
.btn{padding:0.6rem 1.5rem;border-radius:0.5rem;font-size:0.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid #334155;color:#94a3b8;background:transparent;min-width:140px;text-align:center;display:inline-block}
.btn:hover{border-color:#64748b;color:#e2e8f0}
.status{display:flex;gap:2rem;justify-content:center;align-items:center;color:#64748b;font-size:0.85rem;margin-bottom:1.5rem}
.status .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:0.4rem}
.footer{color:#334155;font-size:0.8rem;letter-spacing:0.5px}
.section{z-index:1;width:100%;max-width:800px;padding:0 2rem;margin-bottom:2.5rem}
.card{background:#1e293b;border-radius:0.75rem;padding:2rem;border:1px solid #334155}
.card h2{font-size:1.4rem;font-weight:600;margin-bottom:1.25rem;color:#e2e8f0}
.code-block{background:#0f172a;border-radius:0.5rem;padding:1.25rem;overflow-x:auto;font-family:'SF Mono',SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace;font-size:0.85rem;line-height:1.6;color:#4ade80;border:1px solid #1e293b}
.gallery{z-index:1;width:100%;max-width:800px;padding:0 2rem;margin-bottom:3rem}
.gallery h2{font-size:1.4rem;font-weight:600;margin-bottom:1.25rem;color:#e2e8f0;text-align:center}
.gallery-grid{display:flex;gap:1rem;flex-wrap:wrap}
.gallery-card{flex:1 1 220px;background:#1e293b;border:1px solid #334155;border-radius:0.75rem;padding:1.5rem;position:relative;overflow:hidden}
.gallery-card .accent{position:absolute;top:0;left:0;right:0;height:3px}
.gallery-card .accent-purple{background:#7b1fa2}
.gallery-card .accent-green{background:#22c55e}
.gallery-card .accent-blue{background:#a78bfa}
.gallery-card .icon{font-size:1.5rem;margin-bottom:0.75rem}
.gallery-card h3{font-size:1rem;font-weight:600;margin-bottom:0.5rem;color:#e2e8f0}
.gallery-card p{font-size:0.85rem;color:#94a3b8;line-height:1.5}
.gallery-card .try-btn{display:inline-block;margin-top:0.75rem;padding:0.3rem 0.8rem;background:#7b1fa2;color:#fff;border:none;border-radius:0.375rem;font-size:0.75rem;font-weight:600;cursor:pointer;transition:opacity 0.15s}
.gallery-card .try-btn:hover{opacity:0.85}
@keyframes wiggle{0%{transform:rotate(0deg)}15%{transform:rotate(14deg)}30%{transform:rotate(-10deg)}45%{transform:rotate(8deg)}60%{transform:rotate(-4deg)}75%{transform:rotate(2deg)}100%{transform:rotate(0deg)}}
.star-wiggle{display:inline-block;transform-origin:center}
</style>
</head>
<body>
<img src="/images/tina4-logo-icon.webp" class="bg-watermark" alt="">
<div class="hero">
    <img src="/images/tina4-logo-icon.webp" class="logo" alt="Tina4">
    <h1>Tina4Php</h1>
    <p class="tagline">The Intelligent Native Application 4ramework</p>
    <div class="actions">
        <a href="https://tina4.com/php" class="btn" target="_blank">Website</a>
        <a href="/__dev" class="btn">Dev Admin</a>
        <a href="#gallery" class="btn">Gallery</a>
        <a href="https://github.com/tina4stack/tina4-php" class="btn" target="_blank">GitHub</a>
        <a href="https://github.com/tina4stack/tina4-php/stargazers" class="btn" target="_blank"><span class="star-wiggle">&#9734;</span> Star</a>
    </div>
    <div class="status">
        <span><span class="dot"></span>Server running</span>
        <span>Port {$port}</span>
        <span>v{$version}</span>
    </div>
    <p class="footer">Zero dependencies &middot; Convention over configuration</p>
</div>
<div class="section">
    <div class="card">
        <h2>Getting Started</h2>
        <pre class="code-block"><code><span style="color:#64748b">// index.php</span>
<span style="color:#c084fc">require_once</span> <span style="color:#4ade80">'vendor/autoload.php'</span>;

\$app = <span style="color:#c084fc">new</span> <span style="color:#38bdf8">\Tina4\App</span>();

<span style="color:#38bdf8">\Tina4\Router</span>::<span style="color:#fbbf24">get</span>(<span style="color:#4ade80">'/hello'</span>, <span style="color:#c084fc">function</span> (\$request, \$response) {
    <span style="color:#c084fc">return</span> \$response-&gt;json([<span style="color:#4ade80">'message'</span> =&gt; <span style="color:#4ade80">'Hello World!'</span>]);
});

\$app-&gt;run();  <span style="color:#64748b">// starts on port 7145</span></code></pre>
    </div>
</div>
<div class="gallery">
    <h2 id="gallery">What You Can Build</h2>
    <p style="color:#64748b;font-size:0.85rem;text-align:center;margin-bottom:1.25rem;">Click <strong style="color:#94a3b8;">Try It</strong> to deploy working example code into your <code style="color:#4ade80;">src/</code> folder</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#128640;</div>
            <h3>REST API</h3>
            <p>Define routes with one closure</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">Router::get('/api/users', function(\$req, \$res) {
    return \$res-&gt;json(['users' =&gt; []]);
});</pre>
            {$btnRestApi}
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128451;</div>
            <h3>ORM</h3>
            <p>Active record models, zero config</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">class User extends ORM {
    public \$tableName = "users";
    public \$primaryKey = "id";
}</pre>
            {$btnOrm}
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128274;</div>
            <h3>Auth</h3>
            <p>JWT tokens built-in</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$token = Auth::getToken(["user_id" =&gt; 1]);
\$valid = Auth::validToken(\$token);</pre>
            {$btnAuth}
        </div>
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#9889;</div>
            <h3>Queue</h3>
            <p>Background jobs, no Redis needed</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$producer = new Producer(new Queue("emails"));
\$producer-&gt;produce(["to" =&gt; "a@b.com"]);</pre>
            {$btnQueue}
        </div>
        <div class="gallery-card">
            <div class="accent accent-green"></div>
            <div class="icon">&#128196;</div>
            <h3>Templates</h3>
            <p>Twig templates with auto-reload</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">Router::get('/dashboard', function(\$req, \$res) {
    return \$res-&gt;template("dashboard.twig", \$data);
});</pre>
            {$btnTemplates}
        </div>
        <div class="gallery-card">
            <div class="accent accent-blue"></div>
            <div class="icon">&#128225;</div>
            <h3>Database</h3>
            <p>Multi-engine, one API</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$db = new Database("sqlite:///app.db");
\$result = \$db-&gt;fetch("SELECT * FROM users");</pre>
            {$btnDatabase}
        </div>
        <div class="gallery-card">
            <div class="accent accent-purple"></div>
            <div class="icon">&#128680;</div>
            <h3>Error Overlay</h3>
            <p>Rich debug page with source code</p>
            <pre style="background:#0f172a;color:#4ade80;padding:0.75rem;border-radius:0.375rem;font-size:0.75rem;overflow-x:auto;margin-top:0.5rem;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;">\$user = ["name" =&gt; "Alice"];
\$role = \$user["role"];  // KeyError!</pre>
            {$btnErrorOverlay}
        </div>
    </div>
</div>
<script>
function deployGallery(name, tryUrl) {
    var btn = event.target;
    if (btn.dataset.deployed) {
        window.open(tryUrl, '_blank');
        return;
    }
    if (!confirm('This will add example code to your src/ folder. Continue?')) return;
    btn.textContent = 'Deploying...';
    btn.disabled = true;
    fetch('/__dev/api/gallery/deploy', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({name: name})
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.deployed) {
            btn.textContent = 'View \\u2197';
            btn.style.background = '#22c55e';
            btn.dataset.deployed = '1';
            btn.disabled = false;
            // Wait for the newly deployed route to become reachable, then
            // open in a new tab so the gallery home stays open (tina4-book#115).
            var attempts = 0;
            var maxAttempts = 5;
            function pollRoute() {
                fetch(tryUrl, {method: 'HEAD'}).then(function() {
                    window.open(tryUrl, '_blank');
                }).catch(function() {
                    attempts++;
                    if (attempts < maxAttempts) {
                        setTimeout(pollRoute, 500);
                    } else {
                        window.open(tryUrl, '_blank');
                    }
                });
            }
            setTimeout(pollRoute, 500);
        } else {
            btn.textContent = 'Try It';
            btn.disabled = false;
            alert(d.error || 'Deploy failed');
        }
    }).catch(function() {
        btn.textContent = 'Try It';
        btn.disabled = false;
        alert('Deploy failed — check console');
    });
}
</script>
<script>
(function(){
    var star=document.querySelector('.star-wiggle');
    if(!star)return;
    function doWiggle(){
        star.style.animation='wiggle 1.2s ease-in-out';
        star.addEventListener('animationend',function onEnd(){
            star.removeEventListener('animationend',onEnd);
            star.style.animation='none';
            var delay=3000+Math.random()*15000;
            setTimeout(doWiggle,delay);
        });
    }
    setTimeout(doWiggle,3000);
})();
</script>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Register a background task that runs periodically in the server event loop.
     *
     * Returns a {@see BackgroundTask} handle — call `->stop()` to end and
     * deregister the task. This is the ONE background surface (a stop-handle plus
     * a count) shared with Python/Ruby/Node.
     *
     * Breaking (3.13.99): this used to return `$this` (fluent). Split a chained
     * `->background(a)->background(b)` into two separate calls, and use the
     * returned handle's `->stop()` (or {@see stopBackground()}) to stop a task.
     *
     * BG-PHP-FPM-SWOOLE-NOOP guard: under a non-persistent SAPI (php-fpm,
     * apache2handler, php -S / cli-server) there is no long-lived accept loop to
     * run the tick, so the task would SILENTLY never fire. We warn LOUDLY with
     * the remedy rather than drop it in silence. The `cli` SAPI (the Tina4 socket
     * server, `tina4 serve`) does run ticks, so it passes without noise.
     *
     * @param callable $callback  Function to call (no arguments)
     * @param float    $interval  Seconds between invocations (default: 1.0)
     * @return BackgroundTask A handle whose stop() ends and deregisters the task.
     */
    public function background(callable $callback, float $interval = 1.0): BackgroundTask
    {
        $warning = self::backgroundSapiWarning(php_sapi_name());
        if ($warning !== null) {
            Log::warning($warning);
        }

        $this->tickCallbacks[] = ['callback' => $callback, 'interval' => $interval];

        return new BackgroundTask($this, $callback);
    }

    /**
     * The loud remedy to warn with when background() is called under a SAPI that
     * cannot run cooperative ticks — or null when the current SAPI runs them.
     *
     * Pure + static so the FPM guard is testable without a live php-fpm: pass the
     * SAPI name. `cli` is the persistent Tina4 socket server (its accept loop runs
     * the ticks); every request-scoped web SAPI (`fpm-fcgi`, `apache2handler`,
     * `cli-server`, `litespeed`) has no long-lived loop, so a task registered
     * there would silently never run (BG-PHP-FPM-SWOOLE-NOOP). Mirrors the SAPI
     * guard already on {@see run()}.
     *
     * @param  string      $sapi The php_sapi_name() to judge (e.g. 'cli', 'fpm-fcgi').
     * @return string|null The remedy message, or null when ticks run under $sapi.
     */
    public static function backgroundSapiWarning(string $sapi): ?string
    {
        if ($sapi === 'cli') {
            return null;
        }

        return "background() task registered under the '{$sapi}' SAPI, which has "
            . "no long-lived worker to run it — the task will NOT tick here. Run "
            . "under `tina4 serve` (the persistent socket server) or a Swoole "
            . "worker, or use \\Tina4\\Queue for durable out-of-request work.";
    }

    /**
     * Stop a registered background task and DEREGISTER it.
     *
     * Identified by the callback itself, because background() stays fluent and
     * returns $this rather than a handle. Pass the same callable you registered
     * (the identical Closure instance, function name, or [$object, 'method']);
     * matching is by identity, so an equivalent-but-separate closure is not a
     * match. Only the FIRST registration of that callable is removed, so
     * registering one callable twice needs two calls to stop both.
     *
     * Works before and after the server starts: it removes the pending
     * registration AND, once running, the live tick on the server's event loop.
     *
     * Idempotent — stopping an already-stopped task is a safe no-op.
     *
     * @param  callable $callback The exact callable passed to background()
     * @return bool True if a task was removed, false if none matched
     */
    public function stopBackground(callable $callback): bool
    {
        $removed = false;

        foreach ($this->tickCallbacks as $key => $tick) {
            if ($tick['callback'] === $callback) {
                // Do NOT reindex — the running server iterates a key snapshot.
                unset($this->tickCallbacks[$key]);
                $removed = true;
                break;
            }
        }

        // Once run() has started the loop, the Server owns the live copy.
        if ($this->server !== null && $this->server->stopTick($callback)) {
            $removed = true;
        }

        return $removed;
    }

    /**
     * Number of REGISTERED background tasks (stopped ones are already gone).
     *
     * Reports the live server's count once running, otherwise the pending
     * registrations waiting for run().
     *
     * @return int Count of currently-registered background tasks
     */
    public function backgroundTaskCount(): int
    {
        if ($this->server !== null) {
            return $this->server->tickCallbackCount();
        }

        return count($this->tickCallbacks);
    }

    /**
     * Run the application using the custom Tina4 Server.
     * Calls start() to register routes, then launches the non-blocking HTTP/WebSocket server.
     * Falls back to `php -S` if stream_socket_server fails.
     *
     * @param string|null $host Host to bind to. If null, reads TINA4_HOST (default '0.0.0.0').
     * @param int    $port Port to listen on (default: 7145)
     */
    /**
     * Build the startup banner's optional surface lines (issue #99).
     *
     * Only advertise a surface that is actually REACHABLE. In production, or with
     * TINA4_DEBUG off, /swagger and /__dev return 404 -- printing them anyway both
     * misleads an operator into believing a dev surface is exposed and sends a
     * developer to a dead link.
     *
     * Kept as a pure function of (port, two booleans) so the contract is unit
     * testable without booting a server and grepping stdout. Parity: Python
     * banner_surface_lines, Ruby Tina4.banner_surface_lines, Node
     * bannerSurfaceLines.
     *
     * @param int  $port            Port the server is listening on.
     * @param bool $swaggerEnabled  Whether /swagger is actually served.
     * @param bool $devAdminEnabled Whether /__dev is actually served.
     * @return array{0: string, 1: string} [swaggerLine, dashboardLine] -- each
     *         empty, or a newline plus the banner row, ready to interpolate.
     */
    public static function bannerSurfaceLines(int $port, bool $swaggerEnabled, bool $devAdminEnabled): array
    {
        return [
            $swaggerEnabled ? "\n  Swagger:   http://localhost:{$port}/swagger" : '',
            $devAdminEnabled ? "\n  Dashboard: http://localhost:{$port}/__dev" : '',
        ];
    }

    /** True once the PORT deprecation has been said, so it is said once. */
    private static bool $portDeprecationWarned = false;

    /**
     * Resolve the bind port from the environment.
     *
     * TINA4_PORT > PORT (deprecated) > 7145. Bare PORT is a name anything can
     * set - a shared CI runner, a PaaS, another tool - and it must never
     * outrank the framework's own variable.
     */
    private static function resolveBindPort(int $default = 7145): int
    {
        $tina4Port = DotEnv::getEnv('TINA4_PORT');
        if ($tina4Port !== null && ctype_digit((string)$tina4Port)) {
            return (int)$tina4Port;
        }

        $legacy = DotEnv::getEnv('PORT');
        if ($legacy !== null && ctype_digit((string)$legacy)) {
            if (!self::$portDeprecationWarned) {
                self::$portDeprecationWarned = true;
                Log::warning(sprintf(
                    'PORT is deprecated and will be removed in 3.14 - use TINA4_PORT '
                    . 'instead (binding port %d from PORT)',
                    (int)$legacy
                ));
            }
            return (int)$legacy;
        }

        return $default;
    }

    public function run(?string $host = null, ?int $port = null): void
    {
        // SAPI guard (issue #180). run() is the standalone-server entry: it binds
        // a socket and owns the event loop. Under a WEB SAPI (php-fpm,
        // apache2handler, or `php -S` / cli-server) a server is already in front
        // of us, so the correct behaviour is to handle THIS request and return --
        // not to bind another socket.
        //
        // Without this, the shipped index.php (which calls run()) made the
        // documented nginx + php-fpm deployment in nginx.conf.example
        // unserviceable: every request tried findAvailablePort() + new Server()
        // and never produced a response. Delegating here rather than asking users
        // to edit index.php fixes every existing project as well.
        //
        // Delegated BEFORE start(): handle() -> __invoke() -> start(), so the
        // boot sequence still runs exactly once.
        if (php_sapi_name() !== 'cli') {
            $this->handle();
            return;
        }

        $this->start();

        // When called from the CLI serve command, skip starting another server
        if (defined('TINA4_CLI_SERVE') && TINA4_CLI_SERVE) {
            return;
        }

        // Resolve host: explicit arg > TINA4_HOST > default.
        // DEVADMIN-DEC-02: in dev/serve mode (TINA4_DEBUG) the /__dev dashboard
        // exposes an unauthenticated file/SQL/RCE surface, so the DEFAULT bind is
        // loopback, not 0.0.0.0. Only the default changes: an explicit host arg or
        // TINA4_HOST still wins (production passes one and does not set
        // TINA4_DEBUG, and FPM/Swoole never call run()), so a developer who WANTS
        // network exposure sets TINA4_HOST=0.0.0.0 to override deliberately.
        if ($host === null || $host === '') {
            $envHost = DotEnv::getEnv('TINA4_HOST');
            if ($envHost !== null && $envHost !== '') {
                $host = $envHost;
            } else {
                $host = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false')) ? '127.0.0.1' : '0.0.0.0';
            }
        }

        // Resolve port: explicit arg > TINA4_PORT > PORT (deprecated) > 7145.
        //
        // This read NO environment variable at all. $port was a plain
        // parameter defaulting to 7145, so TINA4_PORT - the name the CLI
        // documents and prefers, and the one every .env sets - was ignored on
        // the one path that binds the socket. Setting it did nothing, silently.
        //
        // The signature moved from `int $port = 7145` to `?int $port = null`
        // for the reason ADR-0041 gives: a default in the ARGUMENT slot makes
        // "not passed" indistinguishable from "passed the default", so the
        // environment can never get a look in. Passing an int still works.
        //
        // Bare PORT stays honoured so nothing breaks, and warns so the
        // migration happens. Removal is 3.14.
        if ($port === null) {
            $port = self::resolveBindPort();
        }

        $actualPort = self::findAvailablePort($port);
        if ($actualPort !== $port) {
            echo "Port {$port} is in use, using port {$actualPort} instead.\n";
            $port = $actualPort;
        }

        $suppressBanner = DotEnv::isTruthy(DotEnv::getEnv('TINA4_SUPPRESS', 'false'));

        // Run the app's own graceful shutdown (and every onShutdown() callback)
        // on the way out. The server owns the signal handlers, so this is what
        // makes a trapped SIGTERM actually reach shutdown() — and it fires on
        // every exit route: the loop returning, the shutdown timeout's exit(0),
        // and a fatal error.
        register_shutdown_function(function (): void {
            $this->shutdown();
        });

        try {
            $server = new Server($host, $port);
            // Keep the reference so stopBackground() can reach the live loop.
            $this->server = $server;

            // Register background tasks on the server's event loop
            foreach ($this->tickCallbacks as $tick) {
                $server->onTick($tick['callback'], $tick['interval']);
            }

            if (!$suppressBanner) {
                // Banner — so the user knows the server started
                $routeCount = Router::count();
                $wsCount = count(Router::getWebSocketRoutes());
                $wsInfo = $wsCount > 0 ? " (WebSocket: {$wsCount} routes)" : '';
                echo "\n";
                echo "  Tina4 PHP v" . self::$VERSION . "\n\n";
                // Only advertise a surface that is actually reachable (issue #99).
                [$swaggerLine, $dashboardLine] = self::bannerSurfaceLines(
                    $port,
                    Swagger::isEnabled(),
                    $this->isDevelopment()
                );
                echo "  Server:    http://localhost:{$port}{$wsInfo}{$swaggerLine}{$dashboardLine}\n";
                echo "  Routes:    {$routeCount}\n";
                echo "\n  Press Ctrl+C to stop.\n\n";
            }

            if (!$suppressBanner) {
                self::openBrowser("http://localhost:{$port}");
            }
            $server->start();
        } catch (\RuntimeException $e) {
            // Fallback to PHP built-in server
            Log::warning('Custom server failed, falling back to php -S: ' . $e->getMessage());
            $docRoot = $this->basePath;
            $indexFile = $docRoot . DIRECTORY_SEPARATOR . 'index.php';
            if (is_file($indexFile)) {
                passthru("php -S {$host}:{$port} -t " . escapeshellarg($docRoot) . " " . escapeshellarg($indexFile));
            } else {
                passthru("php -S {$host}:{$port} -t " . escapeshellarg($docRoot));
            }
        }
    }

    /**
     * Handle a request — universal entry point for any PHP server.
     *
     * Makes Tina4 a drop-in for Swoole, RoadRunner, FrankenPHP, ReactPHP, etc.
     *
     * Accepts:
     *   - Tina4\Request object (pass-through)
     *   - Swoole\HTTP\Request (auto-converted)
     *   - PSR-7 ServerRequestInterface (auto-converted)
     *   - Array with 'method', 'path', 'headers', 'body' keys
     *   - null (reads from PHP globals — $_SERVER, php://input)
     *
     * Returns a Tina4\Response that can be sent to any server.
     *
     * Usage:
     *   $app = new \Tina4\App(basePath: __DIR__);
     *
     *   // Swoole
     *   $http->on("request", function($req, $res) use ($app) {
     *       $response = $app($req);
     *       $res->status($response->getStatusCode());
     *       foreach ($response->getHeaders() as $k => $v) $res->header($k, $v);
     *       $res->end($response->getBody());
     *   });
     *
     *   // RoadRunner
     *   while ($req = $worker->waitRequest()) {
     *       $response = $app($req);
     *       $worker->respond(new \Nyholm\Psr7\Response($response->getStatusCode(), $response->getHeaders(), $response->getBody()));
     *   }
     *
     *   // Direct (PHP-FPM / php -S)
     *   $app();
     *
     * @param mixed $request Request object, array, or null for globals
     * @return Response
     */
    public function __invoke(mixed $request = null): Response
    {
        $this->start();

        // Build Tina4 Request from whatever we received
        if ($request instanceof Request) {
            $tina4Request = $request;
        } elseif (is_array($request)) {
            // Array format: ['method' => 'GET', 'path' => '/api/users', 'headers' => [...], 'body' => '...']
            $tina4Request = new Request(
                method: $request['method'] ?? 'GET',
                path: $request['path'] ?? $request['uri'] ?? '/',
                headers: $request['headers'] ?? [],
                body: $request['body'] ?? '',
                query: $request['query'] ?? [],
                ip: $request['ip'] ?? '127.0.0.1',
            );
        } elseif (is_object($request) && property_exists($request, 'server') && method_exists($request, 'rawContent')) {
            // Swoole / OpenSwoole \Swoole\Http\Request.
            //
            // Checked BEFORE PSR-7, and PSR-7 is probed on getUri() rather than
            // getMethod(), because Swoole's request HAS getMethod() (added in
            // Swoole 4.6) and has no getUri() at all. Probing getMethod() first
            // therefore sent every real Swoole request into the PSR-7 branch,
            // where getUri()->getPath() died with "Call to undefined method
            // Swoole\Http\Request::getUri()" - so this branch was unreachable
            // and the Swoole integration documented above could never run.
            // Measured on openswoole 26.2.0 / PHP 8.3: getMethod PRESENT,
            // getUri absent. See tests/AppInvokeSwooleTest.php.
            $tina4Request = new Request(
                method: $request->server['request_method'] ?? 'GET',
                path: $request->server['request_uri'] ?? '/',
                headers: $request->header ?? [],
                body: $request->rawContent() ?: '',
                query: $request->get ?? [],
                ip: $request->server['remote_addr'] ?? '127.0.0.1',
            );
        } elseif (is_object($request) && method_exists($request, 'getUri') && method_exists($request, 'getMethod')) {
            // PSR-7 ServerRequestInterface. Probed structurally rather than with
            // instanceof because Tina4 declares no PSR dependency, so the
            // interface is often not loaded at all.
            $tina4Request = new Request(
                method: $request->getMethod(),
                path: $request->getUri()->getPath(),
                headers: array_map(fn($v) => $v[0] ?? '', $request->getHeaders()),
                body: (string) $request->getBody(),
                query: $request->getQueryParams(),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1',
            );
        } else {
            // Read from PHP globals (php -S, PHP-FPM, Apache)
            $tina4Request = Request::fromGlobals();
        }

        $response = new Response();
        return Router::dispatch($tina4Request, $response);
    }

    /**
     * Handle the current request and send the response to the client.
     * Use this for PHP-FPM / php -S / Apache where output goes to stdout.
     */
    /**
     * Should `php index.php` print the "use tina4 serve" hint?
     *
     * TRUE only when index.php is the script the user actually ran. FALSE when
     * the framework CLI included it (entry script is bin/tina4php or
     * vendor/bin/tina4php), when it was included from some other script, and
     * when the entry script is unknown — silence is the safe default, because a
     * stray line on a CLI run can corrupt machine-read output.
     *
     * Pure and static so it is testable without spawning a process.
     */
    public static function shouldHintCliServe(string $entryScript): bool
    {
        if ($entryScript === '') {
            return false;
        }

        return basename($entryScript) === 'index.php';
    }

    public function handle(): void
    {
        // In pure CLI context (migrate, generate, etc.) — just initialise routes.
        // The CLI includes index.php to pick up user bootstrap code; we must not
        // echo an HTTP response body or call http_response_code() there.
        if (php_sapi_name() === 'cli') {
            $this->start();
            // Running `php index.php` by hand bootstraps routes and returns —
            // correct (index.php is a front controller, not a server launcher),
            // but it looks exactly like a crash: one "started" line, then the
            // process exits 0 with nothing listening. Point at the real command.
            //
            // Guarded twice, because getting this wrong is worse than the
            // papercut: the tina4php CLI INCLUDES this file, and `tina4php
            // commands --json` emits a machine-read manifest on stdout. So the
            // hint only fires when index.php is itself the entry script, and it
            // goes to STDERR, which no consumer of that manifest parses.
            if (self::shouldHintCliServe($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? ''))) {
                fwrite(STDERR, "Routes bootstrapped. index.php is a front controller, not a server."
                    . PHP_EOL . "To serve locally run: tina4 serve" . PHP_EOL);
            }
            return;
        }

        $response = $this();

        // A streamed body (SSE) owns its own emission: status, headers and then
        // each chunk flushed as the generator yields it. Falling through would
        // call http_response_code() a second time after the first chunk had
        // already gone out, which raises "headers already sent" and kills the
        // request with an uncaught ErrorException.
        if ($response->isStreaming()) {
            $response->sendStream();
            return;
        }

        http_response_code($response->getStatusCode() ?? 200);
        foreach ($response->getHeaders() as $name => $value) {
            if (!headers_sent()) {
                header("$name: $value");
            }
        }
        // Emit cookies set via $response->cookie()
        foreach ($response->getCookies() as $name => $opts) {
            if (!headers_sent()) {
                setcookie($name, $opts['value'], [
                    'expires' => $opts['expires'] ?? 0,
                    'path' => $opts['path'] ?? '/',
                    'domain' => $opts['domain'] ?? '',
                    'secure' => $opts['secure'] ?? false,
                    'httponly' => $opts['httponly'] ?? true,
                    'samesite' => $opts['samesite'] ?? 'Lax',
                ]);
            }
        }
        echo $response->getBody();
    }

    /**
     * Close the shared database connection opened for this process.
     *
     * Called from the server's shutdown path so the engine sees a clean
     * disconnect instead of reaping an abandoned session. Resilient by design:
     * a driver that fails to close is logged and never aborts the shutdown, and
     * a process that never opened a connection is a silent no-op. Safe to call
     * twice.
     *
     * Does NOT go through getDatabase() — that would OPEN a connection from
     * TINA4_DATABASE_URL just to close it.
     */
    public static function closeDatabase(): void
    {
        $connections = [];
        foreach ([self::$database, ORM::getGlobalDb()] as $connection) {
            if ($connection !== null && !in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }
        self::$database = null;

        foreach ($connections as $connection) {
            try {
                $connection->close();
            } catch (\Throwable $e) {
                Log::warning('Could not close the database on shutdown: ' . $e->getMessage());
            }
        }
    }

    /**
     * Set the shared database instance.
     */
    public static function setDatabase(Database\DatabaseAdapter $db): void
    {
        self::$database = $db;
        ORM::bindDatabase($db);
    }

    /**
     * Get the shared database instance.
     * If none is set and TINA4_DATABASE_URL is configured, auto-creates one via Database.
     */
    public static function getDatabase(): ?Database\DatabaseAdapter
    {
        if (self::$database === null) {
            $db = Database\Database::fromEnv('TINA4_DATABASE_URL');
            if ($db !== null) {
                self::$database = $db;
            }
        }

        return self::$database;
    }

    /**
     * Create and set a Database wrapper from a connection URL.
     *
     * @param string $url Connection URL (e.g. "pgsql://user:pass@host/db", "sqlite::memory:")
     * @param bool|null $autoCommit Override auto-commit setting
     * @return Database\Database The created Database wrapper
     */
    public static function createDatabase(string $url, ?bool $autoCommit = null): Database\Database
    {
        $db = Database\Database::create($url, $autoCommit);
        self::$database = $db;
        return $db;
    }

    /**
     * Find the first available port starting from $start.
     */
    private static function findAvailablePort(int $start, int $maxTries = 10): int
    {
        for ($i = 0; $i < $maxTries; $i++) {
            $port = $start + $i;
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket !== false) {
                fclose($socket);
                return $port;
            }
        }
        return $start; // Fall back to original port and let Server handle the error
    }

    /**
     * Open the default browser at the given URL.
     */
    private static function openBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            exec("open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
        } elseif (PHP_OS_FAMILY === 'Windows') {
            exec("start " . escapeshellarg($url));
        } else {
            exec("xdg-open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
        }
    }

}
