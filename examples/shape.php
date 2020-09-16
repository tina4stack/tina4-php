<?php
include "../Tina4/HTMLElement.php";

$template = $dom ($doctype(["html"]), $html (
    $head (
        $title ("A web page example")
    ),
    $body (["style" => "background: red"],
        $br(), $p("I am a paragraph!")
    )
));