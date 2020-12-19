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

    public function __construct($rawRequest)
    {
        Debug::message($rawRequest);
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
    public function __invoke()
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return json_encode($this->data);
    }

    public function asArray()
    {
        return (array)json_decode(json_encode($this->data), true);
    }

}
