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
$container->singleton('queue', fn() => new Queue('orders'));
$container->singleton('i18n', fn() => new I18n('src/locales', 'en'));

// ── Database Setup ─────────────────────────────────────────────
$db = $container->get('db');
ORM::setGlobalDb($db);
\Tina4\App::setDatabase($db);

$migration = new \Tina4\Migration($db, './migrations');
$migration->migrate();

// ── Seed Demo Data (only if DB is empty) ───────────────────────
require_once __DIR__ . '/src/seeds/seed_store.php';
seedStore($db);

// ── Register Event Handlers ────────────────────────────────────
require_once __DIR__ . '/src/app/services/notification_service.php';

// ── Template Globals ───────────────────────────────────────────
require_once __DIR__ . '/src/app/template.php';

// ── Background Tasks ──────────────────────────────────────────
$app = new \Tina4\App();

// Queue worker — processes orders every 2 seconds
$queue = $container->get('queue');
$app->background(function () use ($queue) {
    processOrders($queue);
}, 2.0);

// ── Start Server ───────────────────────────────────────────────
$app->run();
