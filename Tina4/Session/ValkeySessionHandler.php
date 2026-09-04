<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Valkey Session Handler — stores sessions in Valkey (Redis-compatible) using the
 * RESP protocol via raw TCP sockets. Zero external dependencies.
 *
 * Valkey is wire-compatible with Redis, so this is a thin subclass of
 * RedisSessionHandler. It changes only the env-var prefix (TINA4_SESSION_VALKEY_*)
 * and the brand word in messages -- every read/write/destroy/RESP method is
 * inherited, so there is no duplicated logic.
 *
 * Environment variables:
 *   TINA4_SESSION_VALKEY_HOST     — hostname (default: localhost)
 *   TINA4_SESSION_VALKEY_PORT     — port (default: 6379)
 *   TINA4_SESSION_VALKEY_PASSWORD — password (default: none)
 *   TINA4_SESSION_VALKEY_DB       — database number (default: 0)
 *   TINA4_SESSION_VALKEY_PREFIX   — key prefix (default: tina4:session:)
 *   TINA4_SESSION_TTL             — session TTL in seconds (default: 3600)
 */

namespace Tina4\Session;

class ValkeySessionHandler extends RedisSessionHandler
{
    protected string $env = 'VALKEY';
    protected string $brand = 'Valkey';
}
