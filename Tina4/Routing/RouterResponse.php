<?php


namespace Tina4;


class RouterResponse
{
    public string $content;
    public int $httpCode;
    public array $headers;

    public function __construct(?string $content, ?int $httpCode=HTTP_NOT_FOUND, $headers=[]) {
        $this->content = $content;
        $this->httpCode = $httpCode;
        $this->headers = $headers;
    }
}