<?php


namespace ComboStrap;


use DateTime;


/**
 * Class Is8601Date
 * @package ComboStrap
 * Format used by Google, Sqlite and others
 */
class Iso8601Date
{
    /**
     * @var DateTime|false
     */
    private $dateTime;


    /**
     * Date constructor.
     */
    public function __construct($dateTime = null)
    {

        if ($dateTime == null) {

            $this->dateTime = new DateTime();

        } else {

            $this->dateTime = $dateTime;

        }

    }

    public static function create($string = null)
    {
        if ($string === null) {
            return new Iso8601Date();
        }


        /**
         * Time ?
         * (ie only YYYY-MM-DD)
         */
        if (strlen($string) <= 10) {
            /**
             * We had the time to 00:00:00
             * because {@link DateTime::createFromFormat} with a format of
             * Y-m-d will be using the actual time otherwise
             *
             */
            $string .= "T00:00:00";
        }

        /**
         * Timezone
         */
        if (strlen($string) <= 19) {
            /**
             * Because this text metadata may be used in other part of the application
             * We add the timezone to make it whole
             * And to have a consistent value
             */
            $string .= date('P');
        }

        /**
         * Date validation
         * Atom is the valid ISO format (and not IS8601 due to backward compatibility)
         *
         * See:
         * https://www.php.net/manual/en/class.datetimeinterface.php#datetime.constants.iso8601
         */
        $dateTime = DateTime::createFromFormat(DateTime::ATOM, $string);
        return new Iso8601Date($dateTime);
    }

    public static function createFromTimestamp($timestamp)
    {
       $dateTime = new DateTime();
       $dateTime->setTimestamp($timestamp);
        return new Iso8601Date($dateTime);
    }

    /**
     * And note {@link DATE_ISO8601}
     * because it's not the compliant IS0-8601 format
     * as explained here
     * https://www.php.net/manual/en/class.datetimeinterface.php#datetime.constants.iso8601
     * ATOM is
     *
     * This format is used by Sqlite, Google and is pretty the standard everywhere
     * https://www.w3.org/TR/NOTE-datetime
     */
    public static function getFormat()
    {
        return DATE_ATOM;
    }

    public function isValidDateEntry()
    {
        if ($this->dateTime !== false) {
            return true;
        } else {
            return false;
        }
    }

    public function getDateTime()
    {
        return $this->dateTime;
    }

    public function __toString()
    {
        return $this->getDateTime()->format(self::getFormat());
    }

    /**
     * Shortcut to {@link DateTime::format()}
     * @param $string
     * @return string
     * @link https://php.net/manual/en/datetime.format.php
     */
    public function format($string)
    {
        return $this->getDateTime()->format($string);
    }


}
