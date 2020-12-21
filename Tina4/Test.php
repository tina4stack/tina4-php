<?php

namespace Tina4;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Test
 * @package Tina4
 */
class Test
{
    use Utility;

    public $testClass = null;
    public $lastClass = "";

    public $colorRed = "\e[31;1m";
    public $colorOrange = "\e[33;1m";
    public $colorGreen = "\e[32;1m";
    public $colorCyan = "\e[36;1m";
    public $colorReset = "\e[0m";

    public $score;

    /**
     * Asserts something to see if it's true and then prints a message if it isn't
     * @param $condition
     * @param string $message
     * @param string $conditionText
     * @param string $actualResult
     * @return string
     */
    public function assert($condition, $message = "Test failed!", $conditionText = "", $actualResult = ""): string
    {
        $result = false;
        if ($condition !== true && $condition !== false) {
            @eval('$result = (' . $condition . ');');
        } else {
            $result = $condition;
        }
        if ($result === true) {
            return $this->colorGreen . "Passed (" . $conditionText . ") " . $this->colorReset;
        } else {
            return $this->colorRed . "Failed (" . $conditionText . ") " . $message . ", " . $this->colorOrange . "Actual: {$actualResult}" . $this->colorReset;
        }
    }

    /**
     * Run a test based on what was annotated
     * @param $testNo
     * @param $test
     * @param $method
     * @param null $testClass
     * @return string
     */
    public function runTest($testNo, $test, $method, $testClass = null)
    {
        preg_match_all('/^(assert)(.*),(.*)$/m', $test, $testParts, PREG_SET_ORDER);
        if (empty($testParts)) return "";

        if (strtolower($testParts[0][1]) !== "assert") {
            return "";
        } else {
            preg_match_all('/(^.*[\W\w])(\ \=|\ \!)(.*)/m', $testParts[0][2], $parts, PREG_SET_ORDER);

            $actualExpression = trim($parts[0][1]);
            $actualResult = "-";

            if (!empty($testClass)) {
                $condition = trim($testParts[0][2]);

                if ($condition[0] === "(") {
                    $condition = '$testClass->' . $method . $condition;
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->' . $method . $actualExpression . ', true));');
                } else if (strpos($condition[0], '$') !== false) {
                    $condition = '$testClass->' . str_replace('$', '', $condition);
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->' . str_replace('$', '', $actualExpression) . ', true));');
                } else {
                    $condition = $condition;

                    //Condition does not have form x === y or x !== y
                    if (!empty($actualExpression)) {
                        eval('$actualResult = ' . $actualExpression . ';');
                    }
                }

                @eval('$condition = (' . $condition . ');');

            } else {
                $condition = trim($testParts[0][2]);
                if ($condition[0] === "(") {
                    $condition = $method . $condition;
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r(' . $method . $actualExpression . ', true));');
                } else {
                    $actualResult = $actualExpression;
                }

            }

            return "# {$testNo} " . $method . ": " . $this->assert($condition, trim($testParts[0][3]), trim($testParts[0][2]), $actualResult) . "\n";
        }
    }

    /**
     * Parse annotations that have been found with the @tests prefix
     * @param $annotations
     * @param $onlyShowFailed
     */
    public function parseAnnotations($annotations, $onlyShowFailed): void
    {
        $testResult = "";
        $tests = explode(PHP_EOL, $annotations["annotations"]["tests"]);
        $testCount = 0;
        $testFailed = 0;

        if ($annotations["type"] === "function") {
            $testResult .= $this->colorCyan . "Testing Function " . $annotations["method"] . $this->colorReset . PHP_EOL;
        } else {
            $testResult .= $this->colorCyan . "Testing Class {$annotations["class"]}->" . $annotations["method"] . $this->colorReset . PHP_EOL;
            if ($this->lastClass !== $annotations["class"]) {
                $this->lastClass = $annotations["class"];
                eval('$this->testClass = new ' . $annotations["class"] . '();');
            }
        }

        foreach ($tests as $tid => $test) {
            $test = trim($test);
            $testCount++;
            if ($annotations["type"] === "function") {
                $message = $this->runTest($testCount, $test, $annotations["method"]);
            } else {
                $message = $this->runTest($testCount, $test, $annotations["method"], $this->testClass);
            }
            if (empty($message)) $testCount--;
            if ($onlyShowFailed && strpos($message, "Failed") !== false) {
                $testResult .= $message;
                $testFailed++;
            } else if (!$onlyShowFailed) {
                $testResult .= $message;
            }
        }
        if ($testCount !== 0) {
            if ($testCount - $testFailed !== $testCount) {
                $testResult .= $this->colorOrange . "Tests: Passed " . ($testCount - $testFailed) . " of {$testCount} " . round(($testCount - $testFailed) / $testCount * 100.00, 2) . "%" . $this->colorReset . PHP_EOL;
            } else {

                $testResult = substr($testResult, 0, -strlen(PHP_EOL)) . $this->colorGreen . " 100%" . $this->colorReset . PHP_EOL;
            }
        } else {
            $testResult .= $this->colorRed . " No Valid Tests!" . $this->colorReset . PHP_EOL;
        }

        echo $testResult;

    }

    /**
     * Run all the tests
     * @param bool $onlyShowFailed
     * @throws \ReflectionException
     */
    public function run($onlyShowFailed = true): void
    {
        //Autoload all the include paths for testing in the system
        foreach (TINA4_INCLUDE_LOCATIONS as $id => $directory) {
            $this->includeDirectory($directory);
        }

        foreach (TINA4_ROUTE_LOCATIONS as $id => $directory) {
            $this->includeDirectory($directory);
        }
        //Find all the functions and classes with annotated methods
        //Look for test annotations
        $annotation = new Annotation();
        $tests = $annotation->get("tests");
        echo $this->colorGreen . "BEGINNING OF TESTS" . $this->colorReset . PHP_EOL;
        echo str_repeat("=", 80) . PHP_EOL;
        //Run the tests
        foreach ($tests as $id => $test) {
            $this->parseAnnotations($test, $onlyShowFailed);
        }

        echo str_repeat("=", 80) . PHP_EOL;
        echo $this->colorGreen . "END OF TESTS" . $this->colorReset . PHP_EOL;

        //Output the results

    }
}