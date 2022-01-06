<?php

namespace Tina4;

/**
 * Collection of ORM utilities
 */
trait ORMUtility
{
    /**
     * Gets a proper object name for returning back data
     * @param string $name Improper object name
     * @param boolean $camelCase Return the name as camel case
     * @return string Proper object name
     * @tests tina4
     *   assert ("test_name", true) === "testName", "Camel case is broken"
     *   assert ("test_name", false) === "test_name", "Camel case is broken"
     */
    final public function getObjectName(string $name, bool $camelCase = false): string
    {
        if (isset($this->fieldMapping) && !empty($this->fieldMapping)) {
            $fieldMap = array_change_key_case(array_flip($this->fieldMapping), CASE_LOWER);

            if (isset($fieldMap[$name])) {
                return $fieldMap[$name];
            }

            if (!$camelCase) {
                return $name;
            }

            return $this->camelCase($name);
        }

        if (!$camelCase) {
            return $name;
        }

        return $this->camelCase($name);
    }

    /**
     * Return a camel cased version of the name
     * @param string $name A field name or object name with underscores
     * @return string Camel case version of the input
     */
    final public function camelCase(string $name): string
    {
        $fieldName = "";
        $name = strtolower($name);
        for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
            if ($name[$i] === "_") {
                $i++;
                if ($i < strlen($name)) {
                    $fieldName .= strtoupper($name[$i]);
                }
            } else {
                $fieldName .= $name[$i];
            }
        }
        return $fieldName;
    }


}