<?php
require_once "vendor/autoload.php";


$lis = [];

$lis[] = _li("One");
$lis[] = _li("Two");

$ul = _ul ($lis);

$html = _shape(
    _doctype("html"),
    _html(["lang" => "en"],
    _head(
        _title("Testing")
    ),
    _body(
        _h1(["id" => "someId"],"Hello World! H1"),
        _h2("Hello World! H2"),
        $a = _h3("Hello World! H3"),
        _h4("Hello World! H4"),
        _h5("Hello World! H5"),
        $ul
    )
));

$a->html(_b(["style" => "color: red"],"Hello"));

$html->byId("someId")->html("=====");

echo $html;