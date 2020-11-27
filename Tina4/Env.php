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
        $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $vendorDir = dirname(dirname($reflection->getFileName()));
        $fileName = $vendorDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".env";
        if (!empty($environment)) {
            $fileName .= ".{$environment}";
        }
        if (file_exists($fileName)) {
            DebugLog::message("Parsing {$fileName}");
            $fileContents = explode(PHP_EOL, file_get_contents($fileName));
            foreach ($fileContents as $id => $line) {
                if (empty($line)) {
                    //Ignore blanks
                } else
                    if ($line[0] == "[" && $line[strlen($line) - 1] == "]" || $line[0] == "#") {
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
                                    define(trim($variables[0]), $variables[1]);
                                }
                            }
                        }
                    }
            }
        } else {
            die("No environment {$fileName} file found");
        }
    }
}