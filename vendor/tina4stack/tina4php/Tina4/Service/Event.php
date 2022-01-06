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
class Event
{
    /**
     * Adds and event with it's description
     * @param string $eventName
     * @param array $params
     */
    public static function trigger(string $eventName, array $params = []): void
    {
        global $TINA4_EVENTS;
        global $TINA4_EVENTS_DETAIL;
        $debugTrace = debug_backtrace();
        $TINA4_EVENTS_DETAIL[$eventName]["event"] = ["params" => $params, "debug" => $debugTrace[0]];
        if (isset($TINA4_EVENTS[$eventName])) {
            foreach ($TINA4_EVENTS[$eventName] as $id => $method) {
                call_user_func_array($method, $params);
            }
        }
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
