<?php
namespace Tina4;

use Twig\Error\LoaderError;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Debug
 * Used to assist in debugging
 * @package Tina4
 */
class Debug
{

    public static $errorHappened = false;
    private static $errorLog = [];

    /**
     * Create a debug message
     * @param $message
     * @param int $debugType
     * @param null $fileName
     */
    public static function message($message, $debugType = 9004, $fileName = null): void
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

        if ($debugType === DEBUG_SCREEN) {
            if (!empty(self::$errorLog)) {
                self::$errorLog = array_merge(self::$errorLog, $error);
            } else {
                self::$errorLog = $error;
            }
        }

        if (is_array($message) || is_object($message)) {
            $message = str_replace(PHP_EOL, "", print_r($message, 1));
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

    /**
     * @param $errorNo
     * @param $errorString
     * @param $errorFile
     * @param $errorLine
     * @return bool|null
     */
    public static function handleError($errorNo, $errorString, $errorFile, $errorLine): ?bool
    {
        if (defined("TINA4_DEBUG") && TINA4_DEBUG) {
            $error = [];
            $error[] = ["time" => date("Y-m-d H:i:s"), "file" => $errorFile, "line" => $errorLine, "message" => trim($errorNo . " " . $errorString)];

            $debugTrace = debug_backtrace();
            foreach ($debugTrace as $id => $trace) {
                if ($id !== 0 && isset($trace["file"]) && strpos($trace["file"], "Tina4") === false) {
                    $error[] = ["time" => date("Y-m-d H:i:s"), "file" => $trace["file"], "line" => $trace["line"], "message" => trim($errorNo . " " . $errorString)];
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

    public static function render(): string
    {
        $htmlTemplate = '<style>
    h5 {
        background: lightblue;
        margin: 0px;
        padding: 5px;
        color: darkorchid;
        
    }

    .debugLog {
        font-family: Helvetica;
        position:relative;
        left:0px;
        width: 100%;
        background: white;
        padding: 0px;
        border: 2px solid deepskyblue;
        margin: 0px;
    }
    .debugLog pre {
        padding-left: 5px;
    }
    
</style>
<div class="debugLog">
<h5>Error Log - You will see this message because .env contains <b style="color:darkblue">TINA4_DEBUG=true</b>, please make sure this setting is off in production</h5> 
<pre>
{% for error in errors %}
{{error.time}}: {{error.message}} in {{ error.file }} ({{error.line}}) 
{%endfor%}
</pre>
</div>';

        $consoleTemplate = 'DEBUG / ERROR:
{% for error in errors %}
{{error.time}}: {{error.message | raw}} in {{ error.file }} ({{error.line}}) 
{%endfor%}';


        if (self::$errorHappened) {
            try {

                if (is_array(self::$errorLog))
                {
                    self::$errorLog = array_unique(self::$errorLog, SORT_REGULAR);
                }

                if (!defined("TINA4_SUPPRESS")) {
                   return renderTemplate($htmlTemplate, ["errors" => self::$errorLog]);
                } else {
                   return renderTemplate($consoleTemplate, ["errors" => self::$errorLog]);
                }
            } catch (LoaderError $e) {
            }
        }
        return "";
    }
}
