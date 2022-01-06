<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Used in conjunction with Twig to call PHP classes and methods in the project
 * @package Tina4
 */
class Caller
{
    /**
     * Method to call classes using twig
     * Example in twig {{ Tina4.call ("Class", "Method", "Params") }}
     * @param $inputClass
     * @param string $method
     * @param array $variables
     * @return mixed
     * @throws \Exception
     */
    public function call($inputClass, $method = "", $variables = [])
    {
        $classInstance = new \ReflectionClass($inputClass);
        $class = $classInstance->newInstance();
        if (!is_array($variables)) {
            $params[] = $variables;
        } else {
            $params = $variables;
        }
        if (method_exists($class, $method)) {
            $object = [$class, $method];
            return call_user_func_array($object, $params);
        } else {
            throw new \Exception("Unknown {$method} on {$inputClass}");
        }
    }
}
