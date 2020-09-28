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
        if (substr($dateString,-1,1) == "Z") {
            $delimiter="T";
        } else {
            $delimiter=" ";
        }
        $dateParts = explode ($delimiter, $dateString);
        $d = \DateTime::createFromFormat($databaseFormat,  $dateParts[0]);
        if (count($dateParts) > 1) {
            return $d->format($outputFormat).$delimiter.$dateParts[1];
        } else {
            return $d->format($outputFormat);
        }
    }
}