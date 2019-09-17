<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/03/27
 * Time: 10:36 PM
 */
namespace Tina4;

class DataError
{
    private $errorCode;
    private $errorMessage;

    function __construct($errorCode="", $errorMessage="")
    {

        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    function getError() {
        return ["errorCode" => $this->errorCode, "errorMessage" => $this->errorMessage];
    }

    function getErrorText() {
        return $this->errorCode." ".$this->errorMessage;
    }

    function __toString()
    {
        return json_encode($this->getError());
    }
}
