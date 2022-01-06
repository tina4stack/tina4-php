<?php

namespace Tina4;

trait DataUtility
{
    /**
     * Makes sure the field is a date field and formats the data accordingly
     * @param string|null $dateString
     * @param string $databaseFormat
     * @return bool
     */
    public function isDate(?string $dateString, string $databaseFormat): bool
    {
        if ($dateString === null)
        {
            return false;
        }

        if (is_array($dateString) || is_object($dateString)) {
            return false;
        }
        if (substr($dateString, -1, 1) === "Z") {
            $dateParts = explode("T", $dateString);
        } else {
            $dateParts = explode(" ", $dateString);
        }
        $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);

        return $d && $d->format($databaseFormat) === $dateParts[0];
    }

    /**
     * Returns a formatted date in the specified output format
     * @param string|null $dateString Date input
     * @param string $databaseFormat Format in date format of PHP
     * @param string $outputFormat Output of the date in the specified format
     * @return string The resulting formatted date
     */
    public function formatDate(?string $dateString, string $databaseFormat, string $outputFormat): ?string
    {
        //Hacky fix for weird dates?
        $dateString = str_replace(".000000", "", $dateString);

        if (!empty($dateString)) {
            if ($dateString[strlen($dateString) - 1] === "Z") {
                $delimiter = "T";
                $dateParts = explode($delimiter, $dateString);
                $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);
                if ($d) {
                    return $d->format($outputFormat) . $delimiter . $dateParts[1];
                }
                return null;
            }

            if (strpos($dateString, ":") !== false) {
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
            }

            return null;
        } else {
            return null;
        }
    }

    /**
     * This tests a string result from the DB to see if it is binary or not so it gets base64 encoded on the result
     * @param string|null $string $string Data to be checked to see if it is binary data like images
     * @return bool True if the string is binary
     * @tests tina4
     *
     *   assert(null) === false,"Check if binary returns false"
     */
    public function isBinary(?string $string): bool
    {
        //immediately return back binary if we can get an image size
        if ($string === null || is_numeric($string) || empty($string)) {
            return false;
        }
        if (is_string($string) && strlen($string) > 50 && @is_array(@getimagesizefromstring($string))) {
            return true;
        }
        $isBinary = false;
        $string = str_ireplace("\t", "", $string);
        $string = str_ireplace("\n", "", $string);
        $string = str_ireplace("\r", "", $string);

        if (is_string($string) && ctype_print($string) === false && strspn($string, '01') === strlen($string)) {
            $isBinary = true;
        }

        return $isBinary;
    }
}