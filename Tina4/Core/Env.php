<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Reads a .env file or .env.{environment} file for settings that should not be committed up with the repository
 * @package Tina4
 */
class Env extends Data
{
    /**
     * Env constructor.
     * @param string $forceEnvironment
     */
    public function __construct($forceEnvironment = "")
    {
        parent::__construct();

        if (!empty(getenv("ENVIRONMENT"))) {
            $environment = getenv("ENVIRONMENT");
        }

        if (empty($environment)) {
            $environment = $forceEnvironment;
        }

        $this->readParams($environment);
    }

    /**
     * The readEnvParams reads the environment variables from the .env.{ENVIRONMENT} file
     * @param $environment
     * @tests tina4
     *   assert ("test") === null,"Parsing the environment"
     *   assert file_exists(".env.test") === true,"File does not exist .env.test"
     */
    public function readParams($environment): void
    {
        $fileName =   $this->documentRoot . ".env";

        if (!empty($environment)) {
            $fileName .= ".{$environment}";
        }

        if (file_exists($fileName)) {
            Debug::message("Parsing {$fileName}", TINA4_LOG_DEBUG);
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
                    if (($line[0] === "[" && $line[strlen($line) - 1] === "]") || ($line[0] === "#")) {
                        //Ignore [Sections] && Comments #
                    } else {
                        $variables = explode("=", $line, 2);
                        if (isset($variables[0], $variables[1])) {
                            if (!defined(trim($variables[0]))) {
                                Debug::message("Defining {$variables[0]} = $variables[1]", TINA4_LOG_DEBUG);
                                //echo 'return (defined("'.$variables[1].'") ? '.$variables[1].' : "'.$variables[1].'");';
                                if (defined($variables[1])) {
                                    define(trim($variables[0]), eval('return (defined("' . $variables[1] . '") ? ' . $variables[1] . ' : "' . $variables[1] . '");'));
                                } else {
                                    if (strpos($variables[1], '"') !== false || strpos($variables[1], '[') !== false) {
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
            Debug::message("Created an ENV file for you {$fileName}");
            file_put_contents($fileName, "[Project Settings]\nVERSION=1.0.0\nTINA4_DEBUG=true\nTINA4_DEBUG_LEVEL=[TINA4_LOG_ALL]\n[Open API]\nSWAGGER_TITLE=Tina4 Project\nSWAGGER_DESCRIPTION=Edit your .env file to change this description\nSWAGGER_VERSION=1.0.0");

        }
    }
}