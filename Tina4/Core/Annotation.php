<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Annotation
 * This class facilitates finding annotations across a Tina4 project
 * @package Tina4
 */
class Annotation
{
    /**
     * Gets all the user defined functions
     * @return array
     * @tests
     *   assert is_object() !== true, "Expects an array"
     */
    public function getFunctions(): array
    {
        return get_defined_functions()["user"];
    }


    /**
     * Gets all the classes in the system
     * @return array
     * @tests
     *   assert is_array() === true, "Expects an array"
     */
    public function getClasses(): array
    {
        $classes = get_declared_classes();
        $autoloaderClassName = "";
        foreach ( $classes as $className) {
            if (strpos($className, 'ComposerAutoloaderInit') === 0) {
                $autoloaderClassName = $className;
                break;
            }
        }
        $classLoader = $autoloaderClassName::getLoader();
        foreach ($classLoader->getClassMap() as $path) {
            require_once $path;
        }
        return get_declared_classes();
    }

    /**
     * @param $docComment
     * @param string $annotationName
     * @return array
     * @tests
     *   assert ('  * @param weird')["param"][0] === "weird", "Expects value of param to be weird"
     */
    public function parseAnnotations($docComment, $annotationName = ""): array
    {
        //clean *
        $docComment = preg_replace('/^.[\*|\/|\n|\ |\r]+|^(.*)\*/m', "", $docComment);

        $annotations = [];
        preg_match_all('/^@([^\n|\r\n|\t]+)/m', $docComment, $comments, PREG_OFFSET_CAPTURE, 0);

        foreach ($comments[1] as $id => $comment) {
            $name = explode(" ", $comment[0]);
            if ($id < count($comments[1]) - 1) {
                $toPos = $comments[1][$id + 1][1] - $comment[1] - 1;
            } else {
                $toPos = strlen($docComment) - 1;
            }
            if ($annotationName === "" || $annotationName === $name[0]) {
                $annotations[$name[0]][] = trim(substr($docComment, $comment[1] + strlen($name[0]), $toPos - strlen($name[0])));
            }
        }

        return $annotations;
    }

    /**
     * Gets the annotations for a function
     * @param $functionName
     * @param string $annotationName
     * @return array
     * @throws \ReflectionException
     * @tests
     *   assert ("tina4_auto_loader", "param")[0]["annotations"]["param"][0] === '$class', "Expects value class"
     */
    public function getFunctionAnnotations($functionName, $annotationName=""): array
    {
        $annotations = [];

        $reflection = new \ReflectionFunction($functionName);
        $docComment = $reflection->getDocComment();
        $annotation = $this->parseAnnotations($docComment, $annotationName);
        if (!empty($annotation)) {
            $annotations[] = ["type" => "function", "method" => $functionName, "params" => $reflection->getParameters(), "annotations" => $annotation];
        }

        return $annotations;
    }

    /**
     * Gets the annotations for a class
     * @param $className
     * @param string $annotationName
     * @return array
     * @throws \ReflectionException
     */
    public function getClassAnnotations($className, $annotationName="") : array
    {
        $annotations = [];

        $reflection = new \ReflectionClass($className);
        $docComment = $reflection->getDocComment();
        $annotation = $this->parseAnnotations($docComment, $annotationName);

        if (!empty($annotation)) {
            $annotations[] = ["type" => "class", "class" => $className, "annotations" => $annotation, "method" => null, "params" => [], "isStatic" => null];
        }

        $methods = get_class_methods($className);
        foreach ($methods as $mid => $method) {
            $docComment = $reflection->getMethod($method)->getDocComment();
            $isStatic =  $reflection->getMethod($method)->isStatic();
            $annotation = $this->parseAnnotations($docComment, $annotationName);

            if (!empty($annotation)) {
                $annotations[] = ["type" => "classMethod", "class" => $className, "method" => $method, "params" => $reflection->getMethod($method)->getParameters(), "isStatic" => $isStatic, "annotations" => $annotation];
            }
        }

        return $annotations;
    }

    /**
     * Gets all the annotations from the code based on a filter
     * @param string $annotationName
     * @return array
     * @throws \ReflectionException
     */
    public function get($annotationName = ""): array
    {
        $functions = $this->getFunctions();
        asort($functions);
        $classes = $this->getClasses();
        asort($classes);

        $annotations = [];

        //Get annotations for each function
        foreach ($functions as $id => $function) {
            $annotations = array_merge($annotations, $this->getFunctionAnnotations($function, $annotationName));
        }

        //Get annotations for each class and class method
        foreach ($classes as $cid => $class) {
            $annotations = array_merge($annotations, $this->getClassAnnotations($class, $annotationName));
        }

        return $annotations;
    }
}