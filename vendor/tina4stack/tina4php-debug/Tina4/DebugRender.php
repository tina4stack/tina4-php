<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use PHPUnit\Util\Exception;
use Twig\Error\LoaderError;

/**
 * Render debugging information
 * @package Tina4
 */
class DebugRender
{
    /**
     * @param string $templateName Name of the template to be rendered
     * @param array $data Data for the template
     * @return string rendered template
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public static function renderTemplate(string $templateName, array $data): string
    {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'templates');
        $twig = new \Twig\Environment($loader);
        return $twig->render($templateName, $data);
    }

    /**
     * Renders the debugging information on the screen with the line number where the error occurred
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public static function render(): string
    {
        if (count(Debug::$errors) === 0) {
            return "";
        }

        try {
            if (is_array(Debug::$errors)) {
                Debug::$errors = array_unique(Debug::$errors, SORT_REGULAR);
            }
            if (!defined("TINA4_SUPPRESS")) {
                return self::renderTemplate(DEBUG_TEMPLATE, ["errors" => Debug::$errors]);
            }
            return self::renderTemplate(CONSOLE_TEMPLATE, ["errors" => Debug::$errors]);
        } catch (LoaderError $e) {

        }

        return "";
    }
}