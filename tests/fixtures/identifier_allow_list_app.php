<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Front controller for IdentifierAllowListContractTest (ADR-0069) - a REAL
 * Tina4 application served by `php -S`, exposing two AutoCrud-registered
 * models over a real SQLite file (path shared with the parent test process via
 * TINA4_TEST_DB_PATH). The parent test creates and seeds both tables BEFORE
 * the server starts, including a real column the declared model does not
 * declare, so the list route's filter/sort allow-list is proven end-to-end.
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
 * Declared fields: id, name, score, givenName mapped to the first_name column
 * by fieldMapping, and sortRank mapped to sort_rank by autoMap. The table
 * also has an internal_note column this model does NOT declare - it must not
 * be reachable through filter or sort.
 */
class AllowListItem extends ORM
{
    public string $tableName = 'allow_list_item';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
    public int $score = 0;
    public ?string $givenName = null;
    public int $sortRank = 0;   // autoMap: column sort_rank
    public array $fieldMapping = ['givenName' => 'first_name'];
}

/** No declared fields - the allow-list is the table's real columns. */
class AllowListDynamic extends ORM
{
    public string $tableName = 'allow_list_dynamic';
    public string $primaryKey = 'id';
}

$crud = new AutoCrud($db);
$crud->register(AllowListItem::class);
$crud->register(AllowListDynamic::class);
$crud->generateRoutes();

$response = Router::dispatch(Request::fromGlobals(), new Response());

http_response_code($response->getStatusCode() ?? 200);
foreach ($response->getHeaders() as $headerName => $headerValue) {
    if (!headers_sent()) {
        header("{$headerName}: {$headerValue}");
    }
}
echo $response->getBody();
