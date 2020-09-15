<?php

namespace Tina4;
class Caller
{
    /**
     * Method to call classes using twig
     * Example in twig {{ Tina4.call ("Class", "Method", "Params") }}
     * @param $inputClass
     * @param string $method
     * @param array $variables
     * @return string
     */
    function call($inputClass, $method = "", $variables=[])
    {
        eval ('$class = new ' . $inputClass . '();');
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