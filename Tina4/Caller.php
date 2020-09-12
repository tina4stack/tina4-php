<?php

namespace Tina4;
class Caller
{
    /**
     * Method to call classes using twig
     * Example in twig {{ Tina4.call ("Class", "Method", "Params") }}
     * @param $class
     * @param string $method
     * @param array $params
     * @return string
     */
    function call($class, $method = "", $params = [])
    {
        eval ('$class = new ' . $class . '();');
        if (!is_array($params)) {
            $params[] = $params;
        }
        $object = [$class, $method];
        return call_user_func_array($object, $params);
    }
}