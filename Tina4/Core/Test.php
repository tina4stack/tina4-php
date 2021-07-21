<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Testing class for Tina4 library which reads tests based on annotations
 * @package Tina4
 */
class Test
{
    use Utility;

    public $colorRed = "\e[31;1m";
    public $colorOrange = "\e[33;1m";
    public $colorGreen = "\e[32;1m";
    public $colorCyan = "\e[36;1m";
    public $colorYellow = "\e[0;33m";
    public $colorReset = "\e[0m";

    public $testClass = null;
    public $lastClass = "";

    public $rootPath;

    public function __construct(?string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    /**
     * Run all the tests
     * @param bool $onlyShowFailed
     * @param array $testGroups array of comma separated groupMembers to be included in the output
     * @throws \ReflectionException
     */
    public function run(bool $onlyShowFailed = true, array $testGroups = []): void
    {

        //Find all the functions and classes with annotated methods
        //Look for test annotations
        $annotation = new Annotation();
        $tests = $annotation->get("tests");

        $logLevel = Debug::$logLevel;
        Debug::$logLevel = [];
        echo $this->colorGreen . "BEGINNING OF TESTS" . $this->colorReset . PHP_EOL;
        echo str_repeat("=", 80) . PHP_EOL;
        //Run the tests

        foreach ($tests as $id => $test) {
            $groupMember = [];
            // Extracting which group the test belongs to.
            if (isset($test["annotations"]["tests"][0])) {
                $testString = $test["annotations"]["tests"][0];
                $testString = (substr($testString, 0, strpos($testString, "assert")));
                $groupMember = array_map("trim", explode(",", $testString));
            }
            // Check if only a subset group or annotations are being requested.
            if (!empty($testGroups) && !empty($groupMember)) {
                // Check if the group on the test declaration is the same as the requested group
                if (array_intersect($groupMember, $testGroups)) {
                    $this->parseAnnotations($test, $onlyShowFailed);
                }
                // No groups were appended to the test call
            } else {
                // Include test unless a tina4 test is running in a tina4 Project
                if (!in_array("tina4", $groupMember) || file_exists(TINA4_DOCUMENT_ROOT . "Tina4")) {
                    $this->parseAnnotations($test, $onlyShowFailed);
                }
            }
        }

        echo str_repeat("=", 80) . PHP_EOL;
        echo $this->colorGreen . "END OF TESTS" . $this->colorReset . PHP_EOL;
        Debug::$logLevel = $logLevel;
    }

    /**
     * Parse annotations that have been found with the @ tests prefix
     * @param $annotations
     * @param $onlyShowFailed
     */
    public function parseAnnotations($annotations, $onlyShowFailed): void
    {

        $testResult = "";
        $tests = explode(PHP_EOL, $annotations["annotations"]["tests"][0]);
        $testCount = 0;
        $testFailed = 0;



        if ($annotations["type"] === "function") {
            $testResult .= $this->colorCyan . "Testing Function " . $annotations["method"] . $this->colorReset . PHP_EOL;
        } else {
            $testResult .= $this->colorCyan . "Testing Class {$annotations["class"]}->" . $annotations["method"] . $this->colorReset . PHP_EOL;
            if ($this->lastClass !== $annotations["class"]) {
                $this->lastClass = $annotations["class"];
                unset($this->testClass);

                $params = [];
                foreach ($annotations["params"] as $id => $inParam) {
                    $params[] = '$' . $inParam->name;
                    eval('$' . $inParam->name . ' = null;');
                }

                eval('$this->testClass = new ' . $annotations["class"] . '(' . implode(",", $params) . ');');
            }
        }



        foreach ($tests as $tid => $test) {
            $test = trim($test);
            $testCount++;
            if ($annotations["type"] === "function") {
                $message = $this->runTest($testCount, $test, $annotations["method"]);
            } else {
                $message = $this->runTest($testCount, $test, $annotations["method"], $this->testClass, $annotations["isStatic"]);
            }
            if (empty($message)) {
                $testCount--;
            }
            if ($onlyShowFailed && strpos($message, "Failed") !== false) {
                $testResult .= $message;
                $testFailed++;
            } elseif (!$onlyShowFailed) {
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
     * Run a test based on what was annotated
     * @param $testNo
     * @param $test
     * @param $method
     * @param null $testClass
     * @param bool $isStatic
     * @return string
     */
    public function runTest($testNo, $test, $method, $testClass = null, $isStatic = false)
    {

        preg_match_all('/^(assert)(.*),(.*)$/m', $test, $testParts, PREG_SET_ORDER);
        if (empty($testParts)) {
            return "";
        }

        if (strtolower($testParts[0][1]) !== "assert") {
            return "";
        } else {
            preg_match_all('/(^.*[\W\w])(\ \<\=|\ \>\=|\ \<|\ \>|\ \=\=\=|\ \=\=|\ \!\=\=|\ \!\=)(.*)/m', $testParts[0][2], $parts, PREG_SET_ORDER);

            $actualExpression = trim($parts[0][1]);
            $expectedExpression = trim($parts[0][3]);
            $actualResult = "-";

            if (!empty($testClass)) {
                $condition = trim($testParts[0][2]);
                //check for enclosing method before the ()
                preg_match_all('/(.*)\((.*)\)/m', $condition, $methods, PREG_SET_ORDER);

                $enclosingMethod = "";

                if (isset($methods[0][1])) {
                    $enclosingMethod = $methods[0][1];
                    if (in_array($enclosingMethod, ["is_array", "is_object", "is_bool", "is_double", "is_float", "is_integer", "is_null", "is_string", "is_int", "is_numeric", "is_long", "is_callable", "is_countable", "is_iterable", "is_scalar", "is_real", "is_resource"])) {
                        $condition = str_replace($enclosingMethod, "", $condition);
                        $actualExpression = str_replace($enclosingMethod, "", $actualExpression);
                    } else {
                        $enclosingMethod = "";
                    }
                }

                if (\strpos($condition, '$this') !== false || strpos($condition, 'self::') !== false) {
                    if ($isStatic) {
                        $condition = str_replace('$this::', '$testClass::', $condition);
                        $condition = str_replace('self::', '$testClass::', $condition);
                        $actualExpression = str_replace('$this::', '$testClass::', $actualExpression);
                        $actualExpression = str_replace('self::', '$testClass::', $actualExpression);
                    } else {
                        $condition = str_replace('$this->', '$testClass->', $condition);
                        $actualExpression = str_replace('$this->', '$testClass->', $actualExpression);
                    }
                }

                //Check if starts with bracket then we are calling the method
                if ($condition[0] === "(") {
                    if ($isStatic) {
                        $condition = '$testClass::' . $method . $condition; //("test") === true
                    } else {
                        $condition = '$testClass->' . $method . $condition;
                    }


                    //add the enclosing method
                    if (!empty($enclosingMethod)) {
                        $condition = $enclosingMethod . "(" . $condition;
                        $condition = str_replace(" !=", ") !=", $condition);
                        $condition = str_replace(" ==", ") ==", $condition);
                    }


                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->' . $method . $actualExpression . ', 1));');
                } elseif ($condition[0] === '$' && strpos($condition, '$testClass') === false) {
                    if ($isStatic) {
                        $condition = '$testClass::' . str_replace('$', '', $condition);
                    } else {
                        $condition = '$testClass->' . str_replace('$', '', $condition);
                    }
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->' . str_replace('$', '', $actualExpression) . ', 1));');
                } else {
                    //Condition does not have form x === y or x !== y
                    if (!empty($actualExpression)) {
                        @eval('$actualResult = str_replace(PHP_EOL, "", print_r(' . $actualExpression . ',1));');
                    }
                }

                try {
                    @eval('$condition = (' . $condition . ');');
                } catch (\Exception $exception) {
                    echo $condition;
                }
            } else {
                $condition = trim($testParts[0][2]);
                if ($condition[0] === "(") {
                    $condition = $method . $condition;
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r(' . $method . $actualExpression . ', true));');
                } else {
                    $actualResult = $actualExpression;
                }
            }

            $expectedResult = "";
            if (!empty($expectedExpression)) {
               @eval('$expectedResult = str_replace(PHP_EOL, "", print_r(' . $expectedExpression . ',1));');
            }

            return "# {$testNo} " . $method . ": " . $this->assert($condition, trim($testParts[0][3]), trim($testParts[0][2]), $actualResult, $expectedResult) . "\n";
        }
    }

    /**
     * Asserts something to see if it's true and then prints a message if it isn't
     * @param $condition
     * @param string $message
     * @param string $conditionText
     * @param string $actualResult
     * @param string $expectedResult
     * @return string
     * @tests tina4
     *   assert (1 === 1) === "\e[32;1mPassed ()\e[0m", "Test is positive"
     */
    public function assert($condition, $message = "Test failed!", $conditionText = "", $actualResult = "", $expectedResult=""): string
    {
        $result = false;
        if ($condition !== true && $condition !== false) {
            @eval('$result = (' . $condition . ');');
        } else {
            $result = $condition;
        }
        if ($result === true) {
            return $this->colorGreen . "Passed (" . $conditionText . ")" . $this->colorReset;
        } else {
            return $this->colorRed . "Failed (" . $conditionText . ") " . $message . ", " . $this->colorOrange . "\nActual:\n{$actualResult}" . $this->colorReset. $this->colorYellow."\nExpected:\n{$expectedResult}".$this->colorReset;
        }
    }
}
