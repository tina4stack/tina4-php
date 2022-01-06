<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Psr\Log\LogLevel;

/**
 * Debug error handler
 * @package Tina4
 */
class DebugErrorHandler
{
    /**
     * Error handler to handle errors
     * @param string $errorNo
     * @param string $errorString
     * @param string $errorFile
     * @param string $errorLine
     */
    public static function errorHandler(string $errorNo = "", string $errorString = "", string $errorFile = "", string $errorLine = ""): void
    {
        Debug::message($errorString . "\n[" . $errorFile . ":" . $errorLine . "]", LogLevel::ERROR);
        Debug::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Error($errorNo):</span> " . $errorString, "line" => $errorLine, "file" => $errorFile, "codeSnippet" => DebugCodeHandler::getCodeSnippet($errorFile, $errorLine)];
    }
}