<?php

namespace Tina4;

class DebugLog
{

    public static $errorHappened = false;
    private static $errorLog=[];

    /**
     * Create a debug message
     * @param $message
     * @param int $debugType
     * @param null $fileName
     */
    static function message($message, $debugType = 9004, $fileName = null)
    {

        if (!defined("TINA4_DEBUG") || $debugType === DEBUG_NONE || TINA4_DEBUG === false) {
            return;
        }

        $debugTrace = debug_backtrace();

        $error = [];
        foreach ($debugTrace as $id => $trace) {
            if ($id !== 0 && isset($trace["file"]) && strpos($trace["file"], "Tina4") === false) {
                $error[] = ["time" => date("Y-m-d H:i:s"), "file" => $trace["file"], "line" => $trace["line"], "message" => $message];
                break;
            }
        }

        if ($debugType === DEBUG_SCREEN ) {

            if (!empty(self::$errorLog)) {
                self::$errorLog = array_merge(self::$errorLog, $error);
            } else {
                self::$errorLog = $error;
            }
        }

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, 1);
        }

        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000) . "...\n";
        }

        if ($debugType === DEBUG_ALL || $debugType === DEBUG_CONSOLE) {
            if (empty($fileName)) {
         
                error_log("\e[1;34;10m{$message}\e[0m");
            } else {
                file_put_contents($fileName, date("Y-m-d") . ": " . $message . "\n", FILE_APPEND);
            }
        }
    }


    static function handleError($errorNo,$errorString,$errorFile,$errorLine) {

        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
            $error = [];
            $error[] = ["time" => date("Y-m-d H:i:s"), "file" => $errorFile, "line" => $errorLine, "message" => $errorNo." ".$errorString];

            $debugTrace = debug_backtrace();
            foreach ($debugTrace as $id => $trace) {
                if ($id !== 0 && isset($trace["file"]) && strpos($trace["file"], "Tina4") === false) {
                    $error[] = ["time" => date("Y-m-d H:i:s"), "file" => $trace["file"], "line" => $trace["line"], "message" => $errorNo." ".$errorString];
                }

            }

            if (!empty(self::$errorLog)) {
                self::$errorLog = array_merge(self::$errorLog, $error);
            } else {
                self::$errorLog = $error;
            }

            return true;

        } else {
            return false;
        }
    }

    static function render() {
        $template = '<style>
    .debugLog {
        position:relative;
        left:0px;
        width: 100%;
        background: white;
        padding: 10px;
        border: 2px solid red;
    }
</style>
<div class="debugLog">
<h5>Debug Log:</h5> 
<pre>
{% for error in errors %}
{{error.time}}:  {{ error.file }} ({{error.line}}) {{error.message}}
{%endfor%}
</pre>
</div>';

        if (count(self::$errorLog) > 0 && self::$errorHappened)
        {
            return \Tina4\renderTemplate($template, ["errors" => self::$errorLog]);
        }


    }
}