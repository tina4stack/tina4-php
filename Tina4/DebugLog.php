<?php
namespace Tina4;
if(!defined("DEBUG_CONSOLE")) define("DEBUG_CONSOLE", 9001);
if(!defined("DEBUG_SCREEN"))define("DEBUG_SCREEN", 9002);
if(!defined("DEBUG_ALL"))define("DEBUG_ALL", 9003);
if(!defined("DEBUG_NONE")) define("DEBUG_NONE", 9004);

class DebugLog
{
    static function message($message, $debugType=DEBUG_NONE){

        if($debugType == DEBUG_NONE) return;

        if (is_array($message)|| is_object($message)) {
            $message = print_r ($message, 1);
        }

        $debugTrace = debug_backtrace();
        foreach($debugTrace as $id => $value) {
            if ($id !== 0) {
                $message .= "\n\e[1;31;10m" . $value["file"] . "\e[0m \e[1;33;10m(" . $value["line"] . ")\e[0m";
            }
        }


        if ($debugType == DEBUG_ALL || $debugType == DEBUG_SCREEN) echo "<pre>".date("\nY-m-d h:i:s - ") . $message . "\n</pre>";
        if ($debugType == DEBUG_ALL || $debugType == DEBUG_CONSOLE) error_log( "\e[1;34;10m{$message}\e[0m");

    }
}