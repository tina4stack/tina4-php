<?php


namespace Tina4;


use Psr\Log\LogLevel;
use Twig\Error\LoaderError;


/**
 * @property Debug logger
 */
class Debug implements \Psr\Log\LoggerInterface
{
    use Utility;

    public static array $errors = [];

    public string $colorRed = "\e[31;1m";
    public string $colorOrange = "\e[33;1m";
    public string $colorGreen = "\e[32;1m";
    public string $colorCyan = "\e[36;1m";
    public string $colorYellow = "\e[0;33m'";
    public string $colorReset = "\e[0m";

    public static $logger;
    public static array $logLevel = [];
    public static array $context = [];

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
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

    public function debug($message, array $context = []):void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Logs a log for the current level
     * @param string $level
     * @param string $message
     * @param array $context
     * @tests
     *   assert log(\Psr\Log\LogLevel::INFO,"Testing info") === null,"Could not log Info"
     *   assert log(\Psr\Log\LogLevel::WARNING,"Testing warning") === null,"Could not log Warning"
     *   assert log(\Psr\Log\LogLevel::DEBUG,"Testing debug") === null,"Could not log Debug"
     */
    public function log($level, $message, array $context = []):void
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
            }
        }
    }

    public static function message ($message, $level=LogLevel::INFO)
    {
        if (self::$logger === null)
        {
            self::$logger = new self;
        }

        self::$logger->log($level, $message, self::$context);

    }

    public static function exceptionHandler($exception=null)
    {
        $trace = $exception->getTrace()[0];
        if (isset($trace["file"])) {
            self::message("Backtrace:" . "\n[" . $trace["file"] . ":" . $trace["line"] . "]", TINA4_LOG_CRITICAL);
        }
        self::message($exception->getMessage()."\n[".$exception->getFile().":".$exception->getLine()."]", TINA4_LOG_CRITICAL );

        if (TINA4_DEBUG)
        {
            if (isset($trace["file"])) {
                self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:cyan\">Backtrace:</span> Occurred", "line" => $trace["line"], "file" => $trace["file"], "codeSnippet" => self::getCodeSnippet($trace["file"], $trace["line"])];
            }
            self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Exception:</span> ".$exception->getMessage(), "line" => $exception->getLine(), "file" => $exception->getFile(), "codeSnippet" => self::getCodeSnippet($exception->getFile(), $exception->getLine())];
            echo self::render();
        }
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

                if (is_array(self::$errors))
                {
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
    public static function errorHandler ($errorNo="",$errorString="",$errorFile="",$errorLine=""): void
    {
        self::message($errorString. "\n[".$errorFile.":".$errorLine."]", TINA4_LOG_ERROR );
        self::$errors[] = ["time" => date("Y-m-d H:i:s"), "message" => "<span style=\"color:red\">Error($errorNo):</span> ".$errorString, "line" => $errorLine, "file" => $errorFile, "codeSnippet" => self::getCodeSnippet($errorFile, $errorLine)];
    }

    /**
     * Gets 10 lines around line where error occurred
     * @param $fileName
     * @param $lineNo
     * @return string
     */
    public static function getCodeSnippet($fileName, $lineNo) : string
    {
        $lines = explode(PHP_EOL, file_get_contents($fileName));
        $lineStart = $lineNo - 5;
        if ($lineStart < 0)
        {
            $lineStart = 0;
        }
        $lineEnd = $lineNo+5;
        if ($lineEnd > count($lines)-1)
        {
            $lineEnd = count($lines)-1;
        }

        $codeContent = [];
        for ($i = $lineStart; $i < $lineEnd; $i++)
        {
            $lineNr = $i+1;
            if ($lineNr == $lineNo)
            {
                $codeContent[] = "<span class='selected'><span class='lineNo'>{$lineNr}</span>".($lines[$i])."</span>";
            } else
            {
                $codeContent[] = "<span class='lineNo'>{$lineNr}</span>".($lines[$i]);
            }

        }

        $codeContent = implode(PHP_EOL, $codeContent);
        return $codeContent;
    }
}