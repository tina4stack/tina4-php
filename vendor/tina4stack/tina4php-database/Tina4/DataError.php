<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Class DataError Used to get and return an error
 * @package Tina4
 */
class DataError
{
    private $errorCode;
    private $errorMessage;
/**
     * DataError constructor.
     * @param string $errorCode Code of error
     * @param string $errorMessage Message of error
     */
    public function __construct($errorCode = "", $errorMessage = "")
    {

        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    /**
     * @return string Contains the code and message of the error as a single string
     */
    public function getErrorText(): string
    {
        return $this->errorCode . " " . $this->errorMessage;
    }

    /**
     * @return false|string
     */
    public function __toString(): string
    {
        return json_encode($this->getError());
    }

    /**
     * @return array Containing The code and message of the error
     */
    public function getError(): array
    {
        return ["errorCode" => $this->errorCode, "errorMessage" => $this->errorMessage];
    }

    /**
     * Gets the last error code
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Gets the last error message
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
