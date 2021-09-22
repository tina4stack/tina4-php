<?php

namespace Tina4;


class Env
{
    private $environment = "";

    /**
     * Env constructor.
     * @param string $forceEnvironment
     */
    function __construct($forceEnvironment = "")
    {
        if (!empty(getenv("ENVIRONMENT"))) {
            $this->environment = getenv("ENVIRONMENT");
        }

        if (!empty($forceEnvironment)) {
            $this->environment = $forceEnvironment;
        }

        $this->readParams($this->environment);
    }

    /**
     * The readEnvParams reads the environment variables from the .env.{ENVIRONMENT} file
     * @param $environment
     */
    function readParams($environment)
    {
        $fileName = $_SERVER["DOCUMENT_ROOT"].DIRECTORY_SEPARATOR.".env";

        if (!file_exists($fileName)) {
            $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
            $vendorDir = dirname(dirname($reflection->getFileName()));
            $fileName = $vendorDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".env";
        }

        if (!empty($environment)) {
            $fileName .= ".{$environment}";
        }

        if (file_exists($fileName)) {
            DebugLog::message("Parsing {$fileName}");

            $fileContents = file_get_contents($fileName);
            if (strpos($fileContents, "\r")) {
                $fileContents = explode("\r\n", $fileContents);
            } else {
                $fileContents = explode("\n", $fileContents);
            }
            foreach ($fileContents as $id => $line) {
                if (empty($line)) {
                    //Ignore blanks
                } else
                    if (($line[0] == "[" && $line[strlen($line) - 1] == "]") || ($line[0] == "#")) {
                        //Ignore [Sections] && Comments #
                    } else {
                        $variables = explode("=", $line, 2);
                        if (isset($variables[0]) && isset($variables[1])) {
                            if (!defined(trim($variables[0]))) {
                                DebugLog::message("Defining {$variables[0]} = $variables[1]");
                                //echo 'return (defined("'.$variables[1].'") ? '.$variables[1].' : "'.$variables[1].'");';
                                if (defined($variables[1])) {
                                    define(trim($variables[0]), eval('return (defined("' . $variables[1] . '") ? ' . $variables[1] . ' : "' . $variables[1] . '");'));
                                } else {
                                    if (strpos($variables[1], '"') !== false || strpos($variables[1], '[') !== false ) {
                                        $variable = eval('return ' . $variables[1] . ';');
                                    } else {
                                        $variable = $variables[1];
                                    }
                                    define(trim($variables[0]), $variable);
                                }
                            }
                        }
                    }
            }
        } else {
            DebugLog::message("Created an ENV file for you {$fileName}");
            file_put_contents($fileName, "[Example Env File]\nVERSION=1.0.0\nTINA4_DEBUG=true\nTINA4_DEBUG_LEVEL=DEBUG_CONSOLE");

        }
    }
}