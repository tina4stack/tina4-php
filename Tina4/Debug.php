<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Tina4\Debug — Compatibility shim that forwards to Tina4\Log.
 *
 * Historical name kept alive so chapter-15 documentation examples and
 * existing user code that wrote `Tina4\Debug::error(...)` continue to
 * work. Internally everything routes through `Tina4\Log` (the real
 * logger). The level constants (`TINA4_LOG_DEBUG`, `_INFO`, `_WARNING`,
 * `_ERROR`, `_CRITICAL`) are also defined here for the legacy
 * `Debug::message($msg, $level, $context)` shape.
 *
 * For new code, prefer the level-specific methods on `Tina4\Log`:
 *
 *     \Tina4\Log::debug("…")
 *     \Tina4\Log::info("…")
 *     \Tina4\Log::warning("…")
 *     \Tina4\Log::error("…")
 *     \Tina4\Log::critical("…")
 */
class Debug
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    public static function debug(string $message, array $context = []): void
    {
        Log::debug($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        Log::info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        Log::critical($message, $context);
    }

    /**
     * Generic message-with-level shape that chapter 15 documents.
     *
     *     \Tina4\Debug::message("Login failed", \Tina4\Debug::LEVEL_WARNING, ["user_id" => 42]);
     */
    public static function message(string $message, string $level = self::LEVEL_INFO, array $context = []): void
    {
        $level = strtolower($level);
        switch ($level) {
            case self::LEVEL_DEBUG:    Log::debug($message, $context); break;
            case self::LEVEL_WARNING:  Log::warning($message, $context); break;
            case self::LEVEL_ERROR:    Log::error($message, $context); break;
            case self::LEVEL_CRITICAL: Log::critical($message, $context); break;
            default:                    Log::info($message, $context); break;
        }
    }
}

// Define the level constants the chapter-15 docs reference, so legacy
// `Debug::message($msg, TINA4_LOG_INFO, ...)` style calls work too.
if (!defined('TINA4_LOG_DEBUG'))    { define('TINA4_LOG_DEBUG',    Debug::LEVEL_DEBUG); }
if (!defined('TINA4_LOG_INFO'))     { define('TINA4_LOG_INFO',     Debug::LEVEL_INFO); }
if (!defined('TINA4_LOG_WARNING'))  { define('TINA4_LOG_WARNING',  Debug::LEVEL_WARNING); }
if (!defined('TINA4_LOG_ERROR'))    { define('TINA4_LOG_ERROR',    Debug::LEVEL_ERROR); }
if (!defined('TINA4_LOG_CRITICAL')) { define('TINA4_LOG_CRITICAL', Debug::LEVEL_CRITICAL); }
