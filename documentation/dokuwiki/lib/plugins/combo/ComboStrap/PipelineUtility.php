<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;

/**
 * Class PipelineUtility
 * @package ComboStrap
 * A pipeline to perform filter transformation
 *
 * See also
 * https://getbootstrap.com/docs/5.0/helpers/text-truncation/
 */
class PipelineUtility
{

    /**
     * @param $input
     * @return string
     */
    static public function execute($input)
    {

        /**
         * Get the value
         */
        $firstQuoteChar = strpos($input, '"');
        $input = substr($input, $firstQuoteChar + 1);
        $secondQuoteChar = strpos($input, '"');
        $value = substr($input, 0, $secondQuoteChar);
        $input = substr($input, $secondQuoteChar + 1);

        /**
         * Go to the first | and delete it from the input
         */
        $pipeChar = strpos($input, '|');
        $input = substr($input, $pipeChar + 1);

        /**
         * Get the command and applies them
         */
        $commands = preg_split("/\|/", $input);
        foreach ($commands as $command) {
            $command = trim($command, " )");
            $leftParenthesis = strpos($command, "(");
            $commandName = substr($command, 0, $leftParenthesis);
            $signature = substr($command, $leftParenthesis + 1);
            $commandArgs = preg_split("/,/", $signature);
            $commandArgs = array_map(
                'trim',
                $commandArgs,
                array_fill(0, sizeof($commandArgs), "\"")
            );
            $commandName = trim($commandName);
            if (!empty($commandName)) {
                switch ($commandName) {
                    case "replace":
                        $value = self::replace($commandArgs, $value);
                        break;
                    case "head":
                        $value = self::head($commandArgs, $value);
                        break;
                    case "tail":
                        $value = self::tail($commandArgs, $value);
                        break;
                    case "rconcat":
                        $value = self::concat($commandArgs, $value, "right");
                        break;
                    case "lconcat":
                        $value = self::concat($commandArgs, $value, "left");
                        break;
                    case "cut":
                        $value = self::cut($commandArgs, $value);
                        break;
                    case "trim":
                        $value = trim($value);
                        break;
                    case "capitalize":
                        $value=ucwords($value);
                        break;
                    default:
                        LogUtility::msg("command ($commandName) is unknown", LogUtility::LVL_MSG_ERROR, "pipeline");
                }
            }
        }
        return $value;
    }

    private static function replace(array $commandArgs, $value)
    {
        $search = $commandArgs[0];
        $replace = $commandArgs[1];
        return str_replace($search, $replace, $value);
    }

    /**
     * @param array $commandArgs
     * @param $value
     * @return false|string
     * See also: https://getbootstrap.com/docs/5.0/helpers/text-truncation/
     */
    private static function head(array $commandArgs, $value)
    {
        $length = $commandArgs[0];
        return substr($value, 0, $length);
    }

    private static function concat(array $commandArgs, $value, $side)
    {
        $string = $commandArgs[0];
        switch ($side) {
            case "left":
                return $string . $value;
            case "right":
                return $value . $string;
            default:
                LogUtility::msg("The side value ($side) is unknown", LogUtility::LVL_MSG_ERROR, "pipeline");
        }


    }

    private static function tail(array $commandArgs, $value)
    {
        $length = $commandArgs[0];
        return substr($value, strlen($value) - $length);
    }

    private static function cut(array $commandArgs, $value)
    {
        $pattern = $commandArgs[0];
        $words = preg_split("/$pattern/i", $value);
        if ($words !== false) {
            $selector = $commandArgs[1];
            $startEndSelector = preg_split("/-/i", $selector);
            $start = $startEndSelector[0] - 1;
            $end = null;
            if (isset($startEndSelector[1])) {
                $end = $startEndSelector[1];
                if (empty($end)) {
                    $end = sizeof($words);
                }
                $end = $end - 1;
            }
            if ($end == null) {
                if (isset($words[$start])) {
                    return $words[$start];
                } else {
                    return $value;
                }
            } else {
                $result = "";
                for ($i = $start; $i <= $end; $i++) {
                    if (isset($words[$i])) {
                        if (!empty($result)) {
                            $result .= $pattern;
                        }
                        $result .= $words[$i];
                    }
                }
                return $result;
            }

        } else {
            return "An error occurred: could not split with the pattern `$pattern`, the value `$value`.";
        }
    }

}
