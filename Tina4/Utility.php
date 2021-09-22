<?php

namespace Tina4;

trait Utility
{
    /**
     * Makes sure the field is a date field and formats the data accordingly
     * @param $dateString
     * @param $databaseFormat
     * @return bool
     */
    public function isDate($dateString, $databaseFormat) {

        if (is_array($dateString) || is_object($dateString)) return false;

        if (substr($dateString,-1,1) == "Z") {
            $dateParts = explode ("T", $dateString);
        } else {
            $dateParts = explode (" ", $dateString);
        }
        $d = \DateTime::createFromFormat($databaseFormat,$dateParts[0]);
        return $d && $d->format($databaseFormat) === $dateParts[0];
    }

    /**
     * Returns a formatted date
     * @param $dateString
     * @param $databaseFormat
     * @param $outputFormat
     * @return string
     */
    public function formatDate($dateString, $databaseFormat, $outputFormat) {
        //Hacky fix for weird dates?
        $dateString = str_replace(".000000", "", $dateString);

        if (!empty($dateString)) {
            if (substr($dateString, -1, 1) == "Z") {
                $delimiter = "T";
                $dateParts = explode($delimiter, $dateString);
                $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);
                if ($d) {
                    return $d->format($outputFormat) . $delimiter . $dateParts[1];
                } else {
                    return null;
                }
            } else {
                if (strpos($dateString,":") !== false) {
                    $databaseFormat .= " H:i:s";
                    if (strpos($outputFormat, "T")) {
                        $outputFormat .= "H:i:s";
                    } else {
                        $outputFormat .= " H:i:s";
                    }
                }
                $d = \DateTime::createFromFormat($databaseFormat, $dateString);
                if ($d) {
                    return $d->format($outputFormat);
                } else {
                    return null;
                }
            }
        } else {
            return null;
        }
    }

    /**
     * This tests a string result from the DB to see if it is binary or not so it gets base64 encoded on the result
     * @param $string
     * @return bool
     */
    public function isBinary($string)
    {
        //immediately return back binary if we can get an image size
        if (is_array(getimagesizefromstring($string))) return true;
        $isBinary = false;
        $string = str_ireplace("\t", "", $string);
        $string = str_ireplace("\n", "", $string);
        $string = str_ireplace("\r", "", $string);
        if (is_string($string) && ctype_print($string) === false && strspn ( $string , '01') === strlen($string)) {
            $isBinary = true;
        }
        return $isBinary;
    }

    /**
     * Return a camel cased version of the name
     * @param $name
     * @return string
     */
    public function camelCase($name)
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