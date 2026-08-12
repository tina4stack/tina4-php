<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Front controller for AutocrudContractTest — a REAL Tina4 application
 * served by `php -S`, exercising a REAL AutoCrud-registered model over a
 * real socket + real SQLite file (path shared with the parent test process
 * via TINA4_TEST_DB_PATH), so feature 27's CRUD-DEC-01/02 fix is proven
 * end-to-end: secure-by-default writes, a consistent 422 with field errors
 * on an invalid create/update, and the CRUD-MASS-ASSIGNMENT guard
 * (is_deleted + the PK are never body-writable).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tina4\AutoCrud;
use Tina4\Database\Database;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

$dbPath = getenv('TINA4_TEST_DB_PATH');
if ($dbPath === false || $dbPath === '') {
    http_response_code(500);
    echo 'TINA4_TEST_DB_PATH not set';
    exit(1);
}

$db = Database::create('sqlite:///' . $dbPath);
ORM::bindDatabase($db);

/**
 * Soft-delete enabled + is_deleted DECLARED as a real typed property — the
 * worst case for CRUD-MASS-ASSIGNMENT (a genuine writable-looking column,
 * not merely framework-injected DDL a client would never guess).
 */
class AutocrudContractItem extends ORM
{
    public string $tableName = 'autocrud_contract_item';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
    public int $id = 0;
    public string $name = '';
    public int $is_deleted = 0;

    public array $fields = [
        'name' => ['required' => true, 'maxLength' => 20],
    ];
}

(new AutocrudContractItem($db))->createTable();

// Secure-by-default: register() defaults $public = false.
$crud = new AutoCrud($db);
$crud->register(AutocrudContractItem::class);
$crud->generateRoutes();

// Mirrors App::handle()'s non-streaming path: status + headers + body, so the
// socket sees real status codes (401/404/422/201/200), not just a body.
$response = Router::dispatch(Request::fromGlobals(), new Response());

http_response_code($response->getStatusCode() ?? 200);
foreach ($response->getHeaders() as $headerName => $headerValue) {
    if (!headers_sent()) {
        header("{$headerName}: {$headerValue}");
    }
}
echo $response->getBody();
