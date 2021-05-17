<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Request
 * @package Tina4
 */
class Request
{
    public $data = null;
    public ?array $params = null;
    public ?array $inlineParams = null;
    public ?array $server = null;
    public ?array $session = null;
    public ?array $files = null;

    public function __construct($rawRequest)
    {
        Debug::message($rawRequest, TINA4_LOG_DEBUG);
        $this->data = (object)[];
        if (!empty($_REQUEST)) {
            $this->params = $_REQUEST;
        }

        if (!empty($_FILES)) {
            $this->files = $_FILES;
        }

        if (!empty($_SERVER)) {
            $this->server = $_SERVER;
        }

        if (!empty($_SESSION)) {
            $this->session = $_SESSION;
        }

        if (!empty($rawRequest)) {
            $this->data = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
            //pass raw request anyway
            if ($this->data === null && $rawRequest !== '') {
                $this->data = $rawRequest;
            }
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
        return json_encode($this->data, JSON_THROW_ON_ERROR);
    }

    public function asArray()
    {
        return json_decode(json_encode($this->data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

}
