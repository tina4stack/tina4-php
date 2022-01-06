<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Psr\Log\LogLevel;

/**
 * Debug class to make messages in the console
 * @property Debug logger
 * @package Tina4
 */
class Debug implements \Psr\Log\LoggerInterface
{
    public static $errors = [];
    public static $logLevel = [];
    public static $context = [];

    public static $logger;

    public $colorRed = "\e[31;1m";
    public $colorOrange = "\e[33;1m";
    public $colorGreen = "\e[32;1m";
    public $colorCyan = "\e[36;1m";
    public $colorYellow = "\e[0;33m'";
    public $colorReset = "\e[0m";

    public $documentRoot = "./";

    /**
     * Creates a debug message based on the debug level
     * @param string $message
     * @param string $level
     */
    public static function message(string $message, string $level = LogLevel::INFO) : void
    {
        if (self::$logger === null) {
            self::$logger = new self();
        }

        self::$logger->log($level, $message, self::$context);
    }

    /**
     * Gets a cool color for the output
     * @param string $level
     * @return string
     */
    private function getColor(string $level): string
    {
        $color = $this->colorCyan;
        switch ($level) {
            case LogLevel::ALERT:
                $color = $this->colorYellow;
                break;
            case LogLevel::NOTICE:
                $color = $this->colorGreen;
                break;
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
        }
        return $color;

    }

    /**
     * Logs a log for the current level
     * @param string $level
     * @param string $message
     * @param array $context
     */
    final public function log($level, $message, array $context = []): void
    {
        if (is_string($level)) {

            if (defined("TINA4_DOCUMENT_ROOT")) {
                $this->documentRoot = TINA4_DOCUMENT_ROOT;
            }

            if (!is_string($message)) {
                $message .= "\n" . print_r($message, 1);
            }

            $debugLevel = implode("", self::$logLevel);

            $color = $this->getColor($level);

            if (strpos($debugLevel, "all") !== false || strpos($debugLevel, $level) !== false) {
                $output = $color . strtoupper($level) . $this->colorReset . ":" . $message;
                error_log($output);
                if (!file_exists($this->documentRoot . "/log") && !mkdir($concurrentDirectory = $this->documentRoot . "/log", 0777, true) && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
                file_put_contents($this->documentRoot . "/log/debug.log", date("Y-m-d H:i:s: ") . $message . PHP_EOL, FILE_APPEND);
            }
        }
    }

    /**
     * Implements the emergency message
     * @param string $message
     * @param array $context
     */
    final public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Implements alert message
     * @param string $message
     * @param array $context
     */
    final public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Implements critical error message
     * @param string $message
     * @param array $context
     */
    final public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Implements error message
     * @param string $message
     * @param array $context
     */
    final public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Implements warning message
     * @param string $message
     * @param array $context
     */
    final public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Implements notice message
     * @param string $message
     * @param array $context
     */
    final public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Implements info message
     * @param string $message
     * @param array $context
     */
    final public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Implements debug message
     * @param string $message
     * @param array $context
     */
    final public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
