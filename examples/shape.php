<?php
require_once "../vendor/autoload.php";

$template = _dom (_doctype(["html"]), _html (
    _head (
        _title ("A web page example")
    ),
    _body (["style" => "background: red"],
        _br(), _p("I am a paragraph!")
    )
));

echo $template;