<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

/**
 * Declare constants
 */
const DEBUG_TEMPLATE = "debug.twig";
const CONSOLE_TEMPLATE = "console.twig";

//DEBUG & ERROR LOG CONSTANTS
const TINA4_LOG_EMERGENCY = "emergency";
const TINA4_LOG_ALERT = "alert";
const TINA4_LOG_CRITICAL = "critical";
const TINA4_LOG_ERROR = "error";
const TINA4_LOG_WARNING = "warning";
const TINA4_LOG_NOTICE = "notice";
const TINA4_LOG_INFO = "info";
const TINA4_LOG_DEBUG = "debug";
const TINA4_LOG_ALL = "all";

/**
 * Handle exceptions
 * @param Exception $exception
 * @throws \Twig\Error\RuntimeError
 * @throws \Twig\Error\SyntaxError
 */
function tina4_exception_handler($exception)
{
    try {
        \Tina4\DebugExceptionHandler::exceptionHandler($exception);
    } catch (\Twig\Error\LoaderError $e) {

    }
}

/**
 * Handle Errors
 * @param string $errorNo
 * @param string $errorString
 * @param string $errorFile
 * @param string $errorLine
 */
function tina4_error_handler(string $errorNo = "", string $errorString = "", string $errorFile = "", string $errorLine = "")
{
    \Tina4\DebugErrorHandler::errorHandler($errorNo, $errorString, $errorFile, $errorLine);
}


//Initialize the Error handling
//We only want to fiddle with the defaults if we are developing
if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
    set_error_handler("tina4_error_handler");
    set_exception_handler("tina4_exception_handler");
}
