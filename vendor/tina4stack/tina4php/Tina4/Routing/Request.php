<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Implements request params which get passed through to the routers
 * @package Tina4
 */
class Request
{
    public $data = null;
    public $params = null;
    public $inlineParams = null;
    public $server = null;
    public $session = null;
    public $files = null;
    public function __construct($rawRequest, $customRequest=null)
    {
        if (!empty($customRequest->get)) {
            foreach ($customRequest->get as $key => $value) {
                $_REQUEST[$key] = $value;
                $_GET[$key] = $value;
            }
        }

        if (!empty($customRequest->post))
        {
            foreach ($customRequest->post as $key => $value) {
                $_REQUEST[$key] = $value;
                $_POST[$key] = $value;
            }
        }
        if (!empty($customRequest->files)) {
            foreach ($customRequest->files as $key => $value) {
                $_FILES[$key] = $value;
            }
        }

        if (!empty($customRequest->cookie)) {
            foreach ($customRequest->cookie as $key => $value) {
                $_COOKIE[$key] = $value;
            }
        }

        if (!empty($customRequest->server)) {
            foreach ($customRequest->server as $key => $value) {
                $_SERVER[strtoupper($key)] = $value;
            }

            //hack for getting the swoole server header
            if (!empty($customRequest->header["host"])) {
                $_SERVER["HTTP_HOST"] = $customRequest->header["host"];
            }
        }

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
            $this->data = json_decode($rawRequest, false, 512);
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
        return json_encode($this->data);
    }

    public function asArray()
    {
        return json_decode(json_encode($this->data), true, 512);
    }
}
