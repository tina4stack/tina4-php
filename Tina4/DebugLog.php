<?php

namespace Tina4;

class DebugLog
{

    /**
     * Create a debug message
     * @param $message
     * @param int $debugType
     * @param null $fileName
     */
    static function message($message, $debugType = 9004, $fileName=null)
    {
        if (defined("TINA4_DEBUG_LEVEL")) $debugType = TINA4_DEBUG_LEVEL;

        if (!defined("TINA4_DEBUG") ||  $debugType === DEBUG_NONE || TINA4_DEBUG === false)
        {
            return;
        }

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, 1);
        }

        $debugTrace = debug_backtrace();
        foreach ($debugTrace as $id => $value) {
            if ($id !== 0) {
                if (isset($value["file"])) {
                    $message .= "\n\e[1;31;10m" . $value["file"] . "\e[0m \e[1;33;10m(" . $value["line"] . ")\e[0m";
                } else {
                    if (isset($value["class"])) {
                        $message .= "\n\e[1;30;10mClass: " . $value["class"] . "\e[0m";
                    }
                }
            }
        }

        if ($debugType === DEBUG_ALL || $debugType === DEBUG_SCREEN) echo "<pre>" . date("\nY-m-d h:i:s - ") . $message . "\n</pre>";
        if ($debugType === DEBUG_ALL || $debugType === DEBUG_CONSOLE) {
          if (empty($fileName)) {
              error_log("\e[1;34;10m{$message}\e[0m");
          }  else {
              file_put_contents($fileName, date("Y-m-d").": ".$message."\n", FILE_APPEND);
          }
        }
    }
}