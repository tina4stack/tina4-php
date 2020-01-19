<?php
namespace Tina4;
class Caller {
    /**
     * Method to call classes using twig
     * Example in twig {{ Tina4.call ("Class", "Method", "Params") }}
     * @param $class
     * @param string $method
     * @param array $params
     * @return string
     */
    function call ($class, $method="", $params=[]) {
        eval ('$class = new '.$class.'();');
        $object = [$class, $method];
        $result = call_user_func_array($object, $params);

        return $result;
    }
}