<?php


namespace Tina4;


use PHPUnit\Util\Exception;

class Test
{

    public $colorRed = "\e[31;1m";
    public $colorOrange = "\e[33;1m";
    public $colorGreen = "\e[32;1m";
    public $colorCyan = "\e[36;1m";
    public $colorReset = "\e[0m";

    public $score;

    public function assert($condition, $message = "Test failed!", $conditionText = "", $actualResult=""): string
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
            return $this->colorRed . "Failed (" . $conditionText . ") " . $message . ", ".$this->colorOrange."Actual: {$actualResult}".$this->colorReset;
        }
    }

    public function runTest($testNo, $test, $method, $class = null)
    {
        preg_match_all('/^(assert)(.*),(.*)$/m', $test, $testParts, PREG_SET_ORDER);
        if (empty($testParts)) return "";

        if (strtolower($testParts[0][1]) !== "assert") {
            return "";
        } else {
            preg_match_all('/(^.*[\W\w])(\ \=|\ \!)(.*)/m', $testParts[0][2], $parts, PREG_SET_ORDER);

            $actualExpression = trim($parts[0][1]);
            $actualResult = "-";

            if (!empty($class)) {
                $condition = trim($testParts[0][2]);

                eval('$testClass = new ' . $class . '();');
                if ($condition[0] === "(") {
                    $condition = '$testClass->' . $method . $condition;
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->'.$method.$actualExpression.', true));');
                } else if (strpos($condition[0], '$') !== false) {
                    $condition = '$testClass->' . str_replace('$', '', $condition);
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r($testClass->'.str_replace('$', '',$actualExpression).', true));');
                } else {
                    $condition = $condition;
                    $actualResult = $actualExpression;
                }

                @eval('$condition = (' . $condition . ');');

            } else {
                $condition = trim($testParts[0][2]);
                if ($condition[0] === "(") {
                    $condition = $method . $condition;
                    eval('$actualResult = str_replace(PHP_EOL, "", print_r('.$method.$actualExpression.', true));');
                } else {
                    $actualResult = $actualExpression;
                }

            }

            return "# {$testNo} " . $method . ": " . $this->assert($condition, trim($testParts[0][3]), trim($testParts[0][2]), $actualResult) . "\n";
        }
    }

    public function parseAnnotations($annotations, $onlyShowFailed): void
    {
        $testResult = "";
        $tests = explode(PHP_EOL, $annotations["annotations"]["tests"]);
        switch ($annotations["type"]) {
            case "function":
                $testCount = 0;
                $testFailed = 0;
                $testResult .= $this->colorCyan . "Testing Function " . $annotations["method"] . $this->colorReset . PHP_EOL;
                foreach ($tests as $tid => $test) {
                    $test = trim($test);
                    $testCount++;
                    $message = $this->runTest($testCount, $test, $annotations["method"]);
                    if ($onlyShowFailed && strpos($message, "Failed") !== false) {
                        $testResult .= $message;
                        $testFailed++;
                    } else if (!$onlyShowFailed) {
                        $testResult .= $message;
                    }
                }
                if ($testCount - $testFailed !== $testCount) {
                    $testResult .= $this->colorOrange . "Tests: Passed " . ($testCount - $testFailed) . " of {$testCount} " . round(($testCount - $testFailed) / $testCount * 100.00, 2) . "%" . $this->colorReset . PHP_EOL;
                } else {
                    $testResult = substr($testResult, 0, -1) . $this->colorGreen . " 100%" . $this->colorReset . PHP_EOL;
                }
                break;
            case "class":

                break;
            case "classMethod":
                $testCount = 0;
                $testFailed = 0;
                $testResult .= $this->colorCyan . "Testing Class {$annotations["class"]}->" . $annotations["method"] . $this->colorReset . PHP_EOL;
                foreach ($tests as $tid => $test) {
                    $test = trim($test);
                    $testCount++;
                    $message = $this->runTest($testCount, $test, $annotations["method"], $annotations["class"]);
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

                        $testResult = substr($testResult, 0, -1) . $this->colorGreen . " 100%" . $this->colorReset . PHP_EOL;
                    }
                } else {
                    $testResult .= $this->colorRed . " No Valid Tests!" . $this->colorReset . PHP_EOL;
                }
                break;
            default:
                echo "Unknown " . $annotations["type"] . PHP_EOL;
                break;
        }

        echo $testResult;

    }

    public function run($onlyShowFailed = true): void
    {
        //Find all the functions and classes with annotated methods
        //Look for test annotations
        $annotation = new \Tina4\Annotation();
        $functions = $annotation->getFunctions();
        $tests = $annotation->get("tests");
        echo $this->colorGreen . "BEGINNING OF TESTS".$this->colorReset . PHP_EOL;
        echo str_repeat("=", 80) . PHP_EOL;
        //Run the tests
        foreach ($tests as $id => $test) {
            $this->parseAnnotations($test, $onlyShowFailed);
        }

        echo str_repeat("=", 80) . PHP_EOL;
        echo $this->colorGreen . "END OF TESTS".$this->colorReset . PHP_EOL;

        //Output the results

    }
}