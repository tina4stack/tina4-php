<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

/**
 * Ruth class for legacy
 */
class Ruth
{
    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function get(string $routePath, $function): void
    {
        \Tina4\Get::add($routePath, $function);
    }

    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function post(string $routePath, $function): void
    {
        \Tina4\Post::add($routePath, $function);
    }

    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function put(string $routePath, $function): void
    {
        \Tina4\Put::add($routePath, $function);
    }

    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function patch(string $routePath, $function): void
    {
        \Tina4\Patch::add($routePath, $function);
    }

    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function delete(string $routePath, $function): void
    {
        \Tina4\Delete::add($routePath, $function);
    }

    /**
     * Get router
     * @param string $routePath
     * @param $function
     */
    public static function any(string $routePath, $function): void
    {
        \Tina4\Any::add($routePath, $function);
    }

    /**
     * @param string $routeType
     * @param string $routePath
     * @param $function
     */
    public static function addRoute(string $routeType, string $routePath, $function): void
    {
        switch ($routeType) {
            case TINA4_ANY:
                \Tina4\Any::add($routePath, $function);
                break;
            case TINA4_PUT:
                \Tina4\Put::add($routePath, $function);
                break;
            case TINA4_POST:
                \Tina4\Post::add($routePath, $function);
                break;
            case TINA4_PATCH:
                \Tina4\Patch::add($routePath, $function);
                break;
            case TINA4_DELETE:
                \Tina4\Delete::add($routePath, $function);
                break;
            case TINA4_GET:
            default:
                \Tina4\Get::add($routePath, $function);
        }
    }
}