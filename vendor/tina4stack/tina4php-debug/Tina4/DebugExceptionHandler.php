<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;


use Psr\Log\LogLevel;

/**
 * This class handles exceptions
 * @package Tina4
 */
class DebugExceptionHandler
{

    /**
     * Exception handler for better debugging
     * @param null $exception
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public static function exceptionHandler($exception = null): void
    {
        $trace = $exception->getTrace();

        if (count($trace) > 0) {
            $trace = $trace[0];
            if (isset($trace["file"])) {
                Debug::message("Backtrace:" . "\n[" . $trace["file"] . ":" . $trace["line"] . "]", LogLevel::CRITICAL);
            }
        }

        Debug::message($exception->getMessage() . "\n[" . $exception->getFile() . ":" . $exception->getLine() . "]", LogLevel::CRITICAL);

        if (TINA4_DEBUG) {
            if (isset($trace["file"])) {
                Debug::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:cyan\">Backtrace:</span> Occurred", "line" => $trace["line"], "file" => $trace["file"], "codeSnippet" => DebugCodeHandler::getCodeSnippet($trace["file"], $trace["line"])];
            }
            Debug::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Exception:</span> " . $exception->getMessage(), "line" => $exception->getLine(), "file" => $exception->getFile(), "codeSnippet" => DebugCodeHandler::getCodeSnippet($exception->getFile(), $exception->getLine())];
            echo DebugRender::render();
        }
    }
}