<?php
include "../Tina4/HTMLElement.php";

echo $dom ( $doctype(["html"]), $html (
    $head (
        $title ("A web page example")
    ),
    $body (
        $br(), $p("I am a paragraph!")
    )
) );