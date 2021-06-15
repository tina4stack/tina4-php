<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Psr\Log\LogLevel;
use Twig\Error\LoaderError;

/**
 * Debug class to make messages in the console
 * @property Debug logger
 * @package Tina4
 */
class Debug implements \Psr\Log\LoggerInterface
{
    use Utility;

    public static $errors = [];
    public static $logger;
    public static $logLevel = [];
    public static $context = [];
    public $colorRed = "\e[31;1m";
    public $colorOrange = "\e[33;1m";
    public $colorGreen = "\e[32;1m";
    public $colorCyan = "\e[36;1m";
    public $colorYellow = "\e[0;33m'";
    public $colorReset = "\e[0m";

    public static function exceptionHandler($exception = null)
    {
        $trace = $exception->getTrace()[0];
        if (isset($trace["file"])) {
            self::message("Backtrace:" . "\n[" . $trace["file"] . ":" . $trace["line"] . "]", TINA4_LOG_CRITICAL);
        }
        self::message($exception->getMessage() . "\n[" . $exception->getFile() . ":" . $exception->getLine() . "]", TINA4_LOG_CRITICAL);

        if (TINA4_DEBUG) {
            if (isset($trace["file"])) {
                self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:cyan\">Backtrace:</span> Occurred", "line" => $trace["line"], "file" => $trace["file"], "codeSnippet" => self::getCodeSnippet($trace["file"], $trace["line"])];
            }
            self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Exception:</span> " . $exception->getMessage(), "line" => $exception->getLine(), "file" => $exception->getFile(), "codeSnippet" => self::getCodeSnippet($exception->getFile(), $exception->getLine())];
            echo self::render();
        }
    }

    public static function message($message, $level = LogLevel::INFO)
    {
        if (self::$logger === null) {
            self::$logger = new self;
        }

        self::$logger->log($level, $message, self::$context);

    }

    /**
     * Gets 10 lines around line where error occurred
     * @param string $fileName
     * @param int $lineNo
     * @return string
     */
    public static function getCodeSnippet(string $fileName, int $lineNo): string
    {
        $lines = explode(PHP_EOL, file_get_contents($fileName));
        $lineStart = $lineNo - 5;
        if ($lineStart < 0) {
            $lineStart = 0;
        }
        $lineEnd = $lineNo + 5;
        if ($lineEnd > count($lines) - 1) {
            $lineEnd = count($lines) - 1;
        }

        $codeContent = [];
        for ($i = $lineStart; $i < $lineEnd; $i++) {
            $lineNr = $i + 1;
            if ($lineNr == $lineNo) {
                $codeContent[] = "<span class='selected'><span class='lineNo'>{$lineNr}</span>" . ($lines[$i]) . "</span>";
            } else {
                $codeContent[] = "<span class='lineNo'>{$lineNr}</span>" . ($lines[$i]);
            }

        }

        return implode(PHP_EOL, $codeContent);
    }

    public static function render(): string
    {
        if (count(self::$errors) === 0) return "";
        $htmlTemplate = '<html><body><style>
    h5 {
        background: lightblue;
        margin: 0px;
        padding: 5px;
        color: darkorchid;
        
    }

    .body {
        display: block;
        margin: 0;
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
    
    .code {
        border: 1px solid red;
        background: ghostwhite;
        padding: 2px;
        margin: 10px;
    }
    
    .code .lineNo {
        background: white;
        padding: 1px;
    }
    
    .code .selected {
        background: yellow;
        display: inline-block;
        padding: 0px;
        width: 100%;
    }
    
    .error {
       display: block;
       color: whitesmoke;
       background: lightsteelblue;
       padding: 10px;
       margin: 0;
    }
</style>
<div class="debugLog">
<h5>Error Log - You will see this message because .env contains <b style="color:darkblue">TINA4_DEBUG=true</b>, please make sure this setting is off in production</h5> 
{% for error in errors %}
<span class="error">{{error.time}}: {{error.message | raw}} in {{ error.file }} ({{error.line}})</span>
<pre class="code">
{{error.codeSnippet| raw}}
</pre> 
{%endfor%}
</div></body></html>';

        $consoleTemplate = 'DEBUG / ERROR:
{% for error in errors %}
{{error.time}}: {{error.message | raw}} in {{ error.file }} ({{error.line}}) 
{%endfor%}';
        try {

            if (is_array(self::$errors)) {
                self::$errors = array_unique(self::$errors, SORT_REGULAR);
            }

            if (!defined("TINA4_SUPPRESS")) {
                return renderTemplate($htmlTemplate, ["errors" => self::$errors]);
            } else {
                return renderTemplate($consoleTemplate, ["errors" => self::$errors]);
            }
        } catch (LoaderError $e) {
        }

        return "";
    }

    /**
     * @param string $errorNo
     * @param string $errorString
     * @param string $errorFile
     * @param string $errorLine
     */
    public static function errorHandler(string $errorNo = "", string $errorString = "", string $errorFile = "", string $errorLine = ""): void
    {
        self::message($errorString . "\n[" . $errorFile . ":" . $errorLine . "]", TINA4_LOG_ERROR);
        self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Error($errorNo):</span> " . $errorString, "line" => $errorLine, "file" => $errorFile, "codeSnippet" => self::getCodeSnippet($errorFile, $errorLine)];
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Logs a log for the current level
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (is_string($level)) {
            if (!is_string($message)) {
                $message .= "\n" . print_r($message, 1);
            }
            switch ($level) {
                case LogLevel::INFO:
                    $color = $this->colorCyan;
                    break;
                case LogLevel::WARNING:
                    $color = $this->colorOrange;
                    break;
                case LogLevel::ERROR:
                case LogLevel::CRITICAL:
                    $color = $this->colorRed;
                    break;
                default:
                    $color = $this->colorCyan;
            }

            $debugLevel = implode("", self::$logLevel);
            if (strpos($debugLevel, "all") !== false || strpos($debugLevel, $level) !== false) {
                $output = $color . strtoupper($level) . $this->colorReset . ":" . $message;
                error_log($output);
                if (!file_exists(TINA4_DOCUMENT_ROOT . "/log")) {
                    if (!mkdir($concurrentDirectory = TINA4_DOCUMENT_ROOT . "/log", 0777, true) && !is_dir($concurrentDirectory)) {
                        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                    }
                }
                file_put_contents(TINA4_DOCUMENT_ROOT . "/log/debug.log", date("Y-m-d H:i:s: ") . $message . PHP_EOL, FILE_APPEND);
            }

        }
    }

    public function alert($message, array $context = [])
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}