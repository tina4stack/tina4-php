<?php

include "./Tina4/HTMLElement.php";

echo $dom ( $doctype(["html"]), $html (
        $br(), $p("I am a paragraph!")

) );

exit;