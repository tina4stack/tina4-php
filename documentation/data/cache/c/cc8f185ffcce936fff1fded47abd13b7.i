a:21:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:20:"Add a REST end point";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:21:" Add a REST end point";s:7:"context";N;}i:2;i:3;i:3;s:22:" Add a REST end point ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:29;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:29;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:139:"The REST end point is easily added, with an anonymous method to handle the request, the anonymous method should have the response variable.";}i:2;i:36;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:175;}i:8;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:175;}i:9;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:136:"The request exposes more information which comes from the browser, in the case of parameters passed to it. You should always return the ";}i:2;i:177;}i:10;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:313;}i:11;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:9:"$response";}i:2;i:315;}i:12;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:324;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:8:" object:";}i:2;i:326;}i:14;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:334;}i:15;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:336;}i:16;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:600:"
<?php
//Standard
\Tina4\Get::add("/hello/world", function(\Tina4\Response $response, \Tina4\Request $request) {  
    return $response("Hello World", HTTP_OK, TEXT_HTML);
});

//Inline Params
\Tina4\Get::add("/hello/world/{id}", function($id, \Tina4\Response $response, \Tina4\Request $request){
    return $response("Hello World {$id}", HTTP_OK, TEXT_HTML);
});

//Other methods you can test
\Tina4\Post::add(...);

\Tina4\Patch::add(...);

\Tina4\Put::add(...);

\Tina4\Delete::add(...);

//You guessed it - It takes every method - GET, POST, DELETE, PUT, PATCH, OPTIONS
\Tina4\Any::add(...);
}  
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:600:"
<?php
//Standard
\Tina4\Get::add("/hello/world", function(\Tina4\Response $response, \Tina4\Request $request) {  
    return $response("Hello World", HTTP_OK, TEXT_HTML);
});

//Inline Params
\Tina4\Get::add("/hello/world/{id}", function($id, \Tina4\Response $response, \Tina4\Request $request){
    return $response("Hello World {$id}", HTTP_OK, TEXT_HTML);
});

//Other methods you can test
\Tina4\Post::add(...);

\Tina4\Patch::add(...);

\Tina4\Put::add(...);

\Tina4\Delete::add(...);

//You guessed it - It takes every method - GET, POST, DELETE, PUT, PATCH, OPTIONS
\Tina4\Any::add(...);
}  
";}i:2;i:346;}i:17;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:946;}i:18;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:953;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:2;s:6:"strong";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:20;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}