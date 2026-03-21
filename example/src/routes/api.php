<?php

use Tina4\Route;
use const Tina4\HTTP_OK;
use const Tina4\HTTP_CREATED;
use const Tina4\HTTP_BAD_REQUEST;
use const Tina4\HTTP_SERVER_ERROR;

/**
 * GET /api/hello — Simple JSON greeting.
 */
Route::get("/api/hello", function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response(
        ["message" => "Hello from Tina4 PHP!", "timestamp" => date("c")],
        HTTP_OK
    );
});

/**
 * GET /api/users — List all users.
 */
Route::get("/api/users", function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $users = (new User())->select("*", 100)->asArray();
        return $response($users, HTTP_OK);
    } catch (\Exception $e) {
        \Tina4\Debug::message("Error fetching users: " . $e->getMessage(), TINA4_LOG_ERROR);
        return $response(["error" => "Failed to fetch users"], HTTP_SERVER_ERROR);
    }
});

/**
 * POST /api/users — Create a new user.
 *
 * Expects JSON body: {"firstName": "...", "lastName": "...", "email": "..."}
 */
Route::post("/api/users", function (\Tina4\Request $request, \Tina4\Response $response) {
    try {
        $user = new User($request);

        if (empty($user->firstName) || empty($user->email)) {
            return $response(
                ["error" => "firstName and email are required"],
                HTTP_BAD_REQUEST
            );
        }

        $user->save();

        return $response($user->asArray(), HTTP_CREATED);
    } catch (\Exception $e) {
        \Tina4\Debug::message("Error creating user: " . $e->getMessage(), TINA4_LOG_ERROR);
        return $response(["error" => "Failed to create user"], HTTP_SERVER_ERROR);
    }
});
