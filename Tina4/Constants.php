<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * HTTP status codes and content types.
 * Standard constants for use in route handlers across all Tina4 frameworks.
 *
 *   use const Tina4\HTTP_OK;
 *   use const Tina4\APPLICATION_JSON;
 *
 *   Router::get("/api/users", function(Request $request, Response $response) {
 *       return $response(users, HTTP_OK);
 *   });
 */

namespace Tina4;

// ── HTTP Status Codes ──

const HTTP_OK = 200;
const HTTP_CREATED = 201;
const HTTP_ACCEPTED = 202;
const HTTP_NO_CONTENT = 204;

const HTTP_MOVED = 301;
const HTTP_REDIRECT = 302;
const HTTP_NOT_MODIFIED = 304;

const HTTP_BAD_REQUEST = 400;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_METHOD_NOT_ALLOWED = 405;
const HTTP_CONFLICT = 409;
const HTTP_GONE = 410;
const HTTP_UNPROCESSABLE = 422;
const HTTP_TOO_MANY = 429;

const HTTP_SERVER_ERROR = 500;
const HTTP_BAD_GATEWAY = 502;
const HTTP_UNAVAILABLE = 503;

// ── Content Types ──

const APPLICATION_JSON = "application/json";
const APPLICATION_XML = "application/xml";
const APPLICATION_FORM = "application/x-www-form-urlencoded";
const APPLICATION_OCTET = "application/octet-stream";

const TEXT_HTML = "text/html; charset=utf-8";
const TEXT_PLAIN = "text/plain; charset=utf-8";
const TEXT_CSV = "text/csv";
const TEXT_XML = "text/xml";
