<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * This class facilitates finding annotations across a Tina4 project
 * @package Tina4
 */
class Annotation
{
    /**
     * Gets all the annotations from the code based on a filter
     * @param string $annotationName Filter for the annotations
     * @return array An array of the annotations
     * @throws \ReflectionException
     */
    final public function get(string $annotationName = ""): array
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

    /**
     * Gets all the user defined functions
     * @return array Array of the defined functions
     * @tests tina4
     *   assert is_object() !== true, "Expects an array"
     */
    final public function getFunctions(): array
    {
        $allFunctions =  get_defined_functions(true);
        return $allFunctions["user"];
    }

    /**
     * Gets all the classes in the system
     * @return array An array of classes
     * @tests tina4
     *   assert is_array() === true, "Expects an array"
     */
    final public function getClasses(): array
    {
        return get_declared_classes();
    }

    /**
     * Gets the annotations for a function
     * @param string $functionName Name of the function for th
     * @param string $annotationName Name of the annotation
     * @return array An array of annotations on a function
     * @throws \ReflectionException
     * @tests tina4
     *   assert ("strpos", "param") === [], "Expects value class"
     */
    final public function getFunctionAnnotations(string $functionName, string $annotationName = ""): array
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
     * @param string $docComment
     * @param string $annotationName
     * @return array
     * @tests tina4
     *   assert ('@weird weird')["weird"][0] === "weird", "Expects value of param to be weird"
     */
    final public function parseAnnotations(string $docComment, string $annotationName = ""): array
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
     * Gets the annotations for a class
     * @param string $className Name of the class to scan for annotations
     * @param string $annotationName Name of the annotation
     * @return array
     * @throws \ReflectionException
     */
    final public function getClassAnnotations(string $className, string $annotationName = ""): array
    {
        // Check if there are any tests on the Class itself.
        $annotations = [];
        $reflection = new \ReflectionClass($className);
        $docComment = $reflection->getDocComment();
        $annotation = $this->parseAnnotations($docComment, $annotationName);
        $constructor = $reflection->getConstructor();
        if (!empty($annotation)) {
            $annotations[] = ["type" => "class", "class" => $className, "annotations" => $annotation, "method" => null, "params" => $constructor->getParameters(), "isStatic" => null];
        }

        // Check for any tests on the methods
        $methods = get_class_methods($className);
        foreach ($methods as $mid => $method) {
            $docComment = $reflection->getMethod($method)->getDocComment();
            $isStatic = $reflection->getMethod($method)->isStatic();
            $annotation = $this->parseAnnotations($docComment, $annotationName);

            if (!empty($annotation)) {
                $annotations[] = ["type" => "classMethod", "class" => $className, "method" => $method, "params" => $reflection->getMethod($method)->getParameters(), "isStatic" => $isStatic, "annotations" => $annotation];
            }
        }

        return $annotations;
    }
}
