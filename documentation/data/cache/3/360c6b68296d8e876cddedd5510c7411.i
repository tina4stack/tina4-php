a:21:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:73:"Add a filter to process records when they are extracted from the database";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:74:" Add a filter to process records when they are extracted from the database";s:7:"context";N;}i:2;i:3;i:3;s:75:" Add a filter to process records when they are extracted from the database ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:82;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:82;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:81:"Lets say you want to filter the database for all the entries with the first name ";}i:2;i:89;}i:7;a:3:{i:0;s:18:"doublequoteopening";i:1;a:0:{}i:2;i:170;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:5:"Peter";}i:2;i:171;}i:9;a:3:{i:0;s:18:"doublequoteclosing";i:1;a:0:{}i:2;i:176;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:101:". In your SQL tool that you are using, you can query a record or it can be done in the IDE Terminal. ";}i:2;i:177;}i:11;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:278;}i:12;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:278;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:18:"Here's an example:";}i:2;i:280;}i:14;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:298;}i:15;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:300;}i:16;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:446:"
<?php

\Tina4\Get::add("/player/names", function ($names, \Tina4\Response $response, \Tina4\Request $request) {
$names = (new UserName())->select("*") //The table you want to select. It has to be is accordance with the Helper.
                         ->where (names = Peter) //The filter you want 
                         ->asArray;
 return $response (\Tina4\renderTemplate("yourtemplate.twig", ["names" => $names, ), HTTP_OK, TEXT_HTML);
});
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:446:"
<?php

\Tina4\Get::add("/player/names", function ($names, \Tina4\Response $response, \Tina4\Request $request) {
$names = (new UserName())->select("*") //The table you want to select. It has to be is accordance with the Helper.
                         ->where (names = Peter) //The filter you want 
                         ->asArray;
 return $response (\Tina4\renderTemplate("yourtemplate.twig", ["names" => $names, ), HTTP_OK, TEXT_HTML);
});
";}i:2;i:310;}i:17;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:756;}i:18;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:763;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:2;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:20;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}