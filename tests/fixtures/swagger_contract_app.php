<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Front controller for SwaggerContractTest — a REAL Tina4 application served by
 * `php -S`, so the contract suite fetches /swagger/openapi.json over a real
 * socket exactly as the four-way audit did (no generate() shortcut, no mocks).
 *
 * The one app carries everything the "document validates" rule names: ordinary
 * routes, typed AND untyped path params, an ORM model on a write route, a
 * secure-by-default write, and an explicitly-public write. It also registers
 * the framework-internal routes (/, /ai, /rag, ...) that must NOT appear in the
 * document, and /health + /__health for operationId distinctness.
 *
 * The SAME script backs three servers; behaviour is env-driven, so the test
 * varies only the child environment:
 *   - defaults    : TINA4_DEBUG=true, no TINA4_SWAGGER_* set  (info/servers defaults)
 *   - configured  : + TINA4_SWAGGER_CONTACT_TEAM/_URL/_EMAIL   (env surface)
 *   - disabled    : TINA4_SWAGGER_ENABLED=false                (nothing is served)
 *
 * Router::dispatch runs the whole request lifecycle — the secure-by-default
 * auth gate, then static-file serving (the bundled Swagger UI assets), then
 * routing — so a 401/404/200 here is the framework's real answer, not a stub.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tina4\Router;
use Tina4\Swagger;
use Tina4\Request;
use Tina4\Response;
use Tina4\ORM;
use Tina4\Database\Database;

// A real (in-memory) SQLite binding so the ORM model resolves a connection.
ORM::bindDatabase(Database::create('sqlite::memory:'));

/**
 * The ORM model the "model schema keyed by class name" rule is measured on:
 *   id           int, auto-increment PK -> readOnly, never required
 *   name         string (NOT NULL)      -> required
 *   description  ?string (nullable)     -> optional
 * The schema entry must be keyed "ContractWidget" (the CLASS name) and the
 * write route must reference it by $ref rather than inlining it.
 */
class ContractWidget extends ORM
{
    public string $tableName = 'contract_widgets';
    public int $id = 0;
    public string $name = '';
    public ?string $description = null;
}

// ── Application routes ────────────────────────────────────────────────
// Two health probes with distinct leading-underscore shapes: operationId must
// keep them distinct (get_health vs get___health), never collapse + suffix.
Router::get('/health', fn(Request $req, Response $res) => $res->json(['ok' => true]));
Router::get('/__health', fn(Request $req, Response $res) => $res->json(['ok' => true]));

// Typed + untyped path params. One route per token so each mapping is provable
// in isolation. Only tokens the router actually accepts (Router::PARAM_TYPE_PATTERNS)
// can be registered — an invented token throws at registration — so the
// "unknown token degrades to string" case uses the catch-all ".*": a token the
// router accepts but the Swagger type map does not list.
foreach (['int', 'integer', 'float', 'number', 'uuid', 'slug', 'alpha', 'alnum'] as $token) {
    Router::get("/api/kind/{$token}/{value:{$token}}", fn(Request $req, Response $res) => $res->json([]));
}
Router::get('/api/kind/bare/{value}', fn(Request $req, Response $res) => $res->json([]));
Router::get('/api/kind/catchall/{value:.*}', fn(Request $req, Response $res) => $res->json([]));

// A secure-by-default write carrying the ORM model. No ->noAuth(): the server
// must 401 an unauthenticated POST and the document must require bearerAuth.
Router::post('/api/widgets', fn(Request $req, Response $res) => $res->json(['created' => true]))
    ->swagger(['model' => ContractWidget::class]);

// An explicitly-public write. The server must NOT 401 it and the document must
// carry no security block for it.
Router::post('/api/public-signup', fn(Request $req, Response $res) => $res->json(['ok' => true]))
    ->noAuth();

// A bare, UNDECORATED secured write + its explicitly-public twin, with no
// swagger meta at all — the per-op SHAPE fixture (secured-op-per-op-shape-is-
// identical): security + 401 + summary/tags must come out byte-identical to
// the same route registered in python/ruby/node.
Router::post('/contract/shape-item', fn(Request $req, Response $res) => $res->json(['ok' => true]));
Router::post('/contract/shape-public', fn(Request $req, Response $res) => $res->json(['ok' => true]))
    ->noAuth();

// A route registered ONLY when a marker file exists — `php -S` re-executes
// this whole script on EVERY request, so there is no separate "boot" moment
// to snapshot from; the marker simulates a route a hot-reload adds mid-run
// (Node's real mechanism) and proves the document re-reads live routes on
// every request rather than a frozen capture (SWAG-NODE-BOOT-SNAPSHOT parity
// — PHP was never the gap, but the invariant belongs in all four).
$lateRouteMarker = getenv('TINA4_TEST_LATE_ROUTE_MARKER');
if ($lateRouteMarker !== false && is_file($lateRouteMarker)) {
    Router::get('/contract/late-added', fn(Request $req, Response $res) => $res->json(['late' => true]));
}

// ── Framework-internal routes — registered, but never documented ──────
// PHP previously published these in the public API document. They live outside
// the /swagger + /__dev prefixes, so the shared exclusion list must still drop
// them (and the bare "/" landing page).
$internalPaths = [
    '/',
    '/ai',
    '/ai/api/chat',
    '/rag',
    '/vision',
    '/embed',
    '/image',
    '/image/v1/images/generations',
    '/__feedback/api/turn',
    '/__feedback/widget.js',
];
foreach ($internalPaths as $internalPath) {
    Router::get($internalPath, fn(Request $req, Response $res) => $res->json(['internal' => true]));
}

// Registers /swagger + /swagger/openapi.json only when Swagger is enabled
// (explicit TINA4_SWAGGER_ENABLED, else TINA4_DEBUG). Self-gating: on the
// "disabled" server this is a no-op and the spec route never exists.
Swagger::register();

// ── Dispatch the current request and emit the full HTTP response ──────
// Mirrors App::handle()'s non-streaming path: status + headers + body, so the
// socket sees real status codes (200/401/404), not just a body.
$response = Router::dispatch(Request::fromGlobals(), new Response());

http_response_code($response->getStatusCode() ?? 200);
foreach ($response->getHeaders() as $headerName => $headerValue) {
    if (!headers_sent()) {
        header("{$headerName}: {$headerValue}");
    }
}
echo $response->getBody();
