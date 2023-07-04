<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * This handles triggering and creating of events in a system
 * @package Tina4
 */
class Thread
{

    /**
     * Figures out the code by reading the source file
     * @throws \ReflectionException
     */
    public static function closureDump(\Closure $c, $name="someMethod") : string
    {
        $code = 'function '.$name.'(';
        $reflection = new \ReflectionFunction($c);
        $params = array();
        foreach($reflection->getParameters() as $param) {
            $varType = '$';

            if($param->isPassedByReference()){
                $varType = '&'.$varType;
            }

            $default = "";
            if (!empty($param->isDefaultValueAvailable())) {
                $default .= '=';
                if (is_array($param->getDefaultValue()) && empty($param->getDefaultValue())) {
                    $default .= "[]";
                } else {
                    $default .= var_export($param->getDefaultValue(), TRUE);
                }
            }

            $params []= $varType.$param->getName().$default;
        }
        $code .= implode(', ', $params);
        $code .= '){';
        $lines = file($reflection->getFileName());
        for($l = $reflection->getStartLine(); $l < $reflection->getEndLine(); $l++) {
            if ($lines[$l] == "});") {
                $code .= "};";
            } else {
                $code .= $lines[$l];
            }
        }
        return str_replace(PHP_EOL, "", str_replace('"', '\"', $code));
    }

    /**
     * Adds and event with it's description
     * @param string $eventName
     * @param array $params
     * @throws \ReflectionException
     */
    public static function trigger(string $eventName, array $params = []): void
    {
        global $TINA4_EVENTS;
        global $TINA4_EVENTS_DETAIL;
        $debugTrace = debug_backtrace();
        $TINA4_EVENTS_DETAIL[$eventName]["event"] = ["params" => $params, "debug" => $debugTrace[0]];
        if (isset($TINA4_EVENTS[$eventName])) {
            //ignore_user_abort(true);
            foreach ($TINA4_EVENTS[$eventName] as $id => $method) {
                $code = self::closureDump($method, $eventName);
                foreach ($params as $param) {
                    $vars[] = var_export($param, TRUE);
                }
                $code = 'require_once \"./vendor/autoload.php\"; const TINA4_SUPPRESS = true; '.$code.$eventName.'('.join(',', $vars).');';

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose($handle = popen('start /B php -r "' . $code . '"', 'r'));
                } else {
                    pclose($handle = popen('php -r "' . $code . '" > /dev/null & ', 'r'));
                }
                \Tina4\Debug::message("Event ".$eventName." fired with handle ".$handle, TINA4_LOG_NOTICE);
            }
        }
    }

    /**
     * Runs a threaded method
     * @param string $eventName
     * @param array $params
     * @return void
     * @throws \ReflectionException
     */
    public static function run (string $eventName, array $params = []): void
    {
        self::trigger($eventName, $params);
    }

    /**
     * Alias for onTrigger
     * @param $eventName
     * @param $method
     */
    public static function addTrigger($eventName, $method): void
    {
        self::onTrigger($eventName, $method);
    }

    /**
     * Method to handle on an event, this is what will be triggered when the event is fired
     * @param $eventName
     * @param $method
     */
    public static function onTrigger($eventName, $method): void
    {
        global $TINA4_EVENTS;
        global $TINA4_EVENTS_DETAIL;
        $debugTrace = debug_backtrace();
        $TINA4_EVENTS_DETAIL[$eventName]["handler"][] = ["debug" => $debugTrace[0]];
        $TINA4_EVENTS[$eventName][] = $method;
    }

    /**
     * Gets all the events in the system
     */
    public static function getEvents()
    {
        global $TINA4_EVENTS_DETAIL;
        return $TINA4_EVENTS_DETAIL;
    }
}
