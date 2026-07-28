<?php
/**
 * Tina4 Store — Complete Framework Demo (PHP)
 *
 * Run: php index.php 0.0.0.0:7145
 * Browse: http://localhost:7145
 */
require_once __DIR__ . '/vendor/autoload.php';

use Tina4\Database\Database;
use Tina4\Router;
use Tina4\Container;
use Tina4\Queue;
use Tina4\I18n;
use Tina4\Frond;
use Tina4\Auth;
use Tina4\ORM;

// ── Dependency Injection Container ─────────────────────────────
$container = new Container();
$container->singleton('db', fn() => Database::create('data/store.db'));
$container->singleton('queue', fn() => new Queue(topic: 'orders'));
$container->singleton('i18n', fn() => new I18n('en', 'src/locales'));

// ── Database Setup ─────────────────────────────────────────────
$db = $container->get('db');
ORM::bindDatabase($db);
\Tina4\App::setDatabase($db);

$migration = new \Tina4\Migration($db, './migrations');
$migration->migrate();

// ── Register Event Handlers ────────────────────────────────────
require_once __DIR__ . '/src/app/services/notification_service.php';

// ── Template Globals ───────────────────────────────────────────
require_once __DIR__ . '/src/app/template.php';

// ── Background Tasks ──────────────────────────────────────────
$app = new \Tina4\App();

// ── Seed Demo Data (only if DB is empty) ───────────────────────
// The explicit scan is REQUIRED, and it is not belt-and-braces.
//
// Tina4 auto-discovers src/orm/ inside App::start(), which run() calls on the
// LAST line of this file. Nothing at this file's top level -- seeding included
// -- can see a discovered model yet, and seed_store.php instantiates Category.
//
// It only appeared to work in development because composer had classmapped
// src/orm into this app's own vendor autoloader. The base image ships the
// framework WITHOUT the app's vendor tree (see the .dockerignore note on
// **/vendor), so the classmap is gone and boot died with:
//
//     Fatal error: Class "Category" not found in src/seeds/seed_store.php:9
//
// scan() is idempotent -- it guards on already-seen files -- so start() scanning
// the same directory again later is a no-op.
\Tina4\ModelDiscovery::scan(__DIR__ . '/src/orm');

require_once __DIR__ . '/src/seeds/seed_store.php';
seedStore($db);

// Queue worker — processes orders every 2 seconds
$queue = $container->get('queue');
$app->background(function () use ($queue) {
    processOrders($queue);
}, 2.0);

// ── Start Server ───────────────────────────────────────────────
$app->run();
