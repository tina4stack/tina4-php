<?php
namespace Tina4;

/**
 * Class Request
 * @package Tina4
 */
class Request
{
    public $data = null;
    public $params = null;
    public $inlineParams = null;
    public $server = null;
    public $session = null;

    function __construct($rawRequest)
    {
        $this->data = (object)[];
        if (!empty($_REQUEST)) {
            $this->params = $_REQUEST;
        }
        if (!empty($_SERVER)) {
            $this->server = $_SERVER;
        }
        if (!empty($_SESSION)) {
            $this->session = $_SESSION;
        }

        if (!empty($rawRequest)) {
            $this->data = json_decode($rawRequest);
        } else {
            foreach ($_REQUEST as $key => $value) {
                $this->data->{$key} = $value;
            }
        }
    }

    /**
     * Returns the data object that was created from the request
     * @return mixed|null
     */
    function __invoke()
    {
        return $this->data;
    }

    function __toString()
    {
        return json_encode($this->data);
    }

    function asArray() {

        return (array)json_decode(json_encode($this->data));
    }

}
