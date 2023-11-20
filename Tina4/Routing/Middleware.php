<?php

namespace Tina4;

use phpDocumentor\Reflection\Types\Self_;

class Middleware
{

    /**
     * Gets the sanitized name of the middleware
     */
    public static function getName(string $name): string
    {
        return str_replace(" ", "", ucwords(str_replace("_", " ", trim($name))));
    }

    public static function add(string $name, $function): Middleware
    {
        global $arrMiddleware;
        $arrMiddleware[self::getName($name)] = ["middleware" => $name, "function" => $function];
        return new static;
    }
}