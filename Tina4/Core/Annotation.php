<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Annotation
 * This class facilitates finding annotations across a Tina4 project
 * @package Tina4\Core
 */
class Annotation
{
    /**
     * Gets all the user defined functions
     * @return array
     */
    public function getFunctions()
    {
        return get_defined_functions()["user"];
    }


    public function getClasses()
    {
        return get_declared_classes();
    }

    /**
     * @param $docComment
     * @param string $annotationName
     * @return array
     */
    public function parseAnnotations($docComment, $annotationName = ""): array
    {
        //clean *
        $docComment = preg_replace('/^.[\n\*|\r\n\*]|^(.*)\*/m', "", $docComment);
        $annotations = [];
        preg_match_all('/@([^\n|\r\n|\t]+)/m', $docComment, $comments, PREG_OFFSET_CAPTURE, 0);

        foreach ($comments[1] as $id => $comment) {
            $name = explode(" ", $comment[0]);
            if ($id < count($comments[1]) - 1) {
                $toPos = $comments[1][$id + 1][1] - $comment[1] - 1;
            } else {
                $toPos = strlen($docComment) - 1;
            }
            if ($annotationName === "" || $annotationName === $name[0]) {
                $annotations[$name[0]] = trim(substr($docComment, $comment[1] + strlen($name[0]), $toPos - strlen($name[0])));
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
        $classes = $this->getClasses();

        $annotations = [];

        //Get annotations for each function
        foreach ($functions as $id => $function) {
            $reflection = new \ReflectionFunction($function);
            $docComment = $reflection->getDocComment();
            $annotation = $this->parseAnnotations($docComment, $annotationName);
            if (!empty($annotation)) {
                $annotations[] = ["type" => "function", "method" => $function, "annotations" => $annotation];
            }
        }

        //Get annotations for each class and class method
        foreach ($classes as $cid => $class) {
            $reflection = new \ReflectionClass($class);
            $docComment = $reflection->getDocComment();

            $annotation = $this->parseAnnotations($docComment, $annotationName);
            if (!empty($annotation)) {
                $annotations[] = ["type" => "class", "class" => $class, "annotations" => $annotation];
            }

            $methods = get_class_methods($class);
            foreach ($methods as $mid => $method) {
                $docComment = $reflection->getMethod($method)->getDocComment();
                $annotation = $this->parseAnnotations($docComment, $annotationName);
                if (!empty($annotation)) {
                    $annotations[] = ["type" => "classMethod", "class" => $class, "method" => $method, "annotations" => $annotation];
                }
            }

        }

        return $annotations;
    }

}