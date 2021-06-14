<?php


namespace Tina4;


class RouterResponse
{
    public $content;
    public $httpCode;
    public $headers;

    public function __construct(?string $content, ?int $httpCode=HTTP_NOT_FOUND, $headers=[]) {
        $this->content = $content;
        $this->httpCode = $httpCode;
        $this->headers = $headers;
    }
}