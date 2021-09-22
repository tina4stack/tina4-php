a:14:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:35:"Query the database for some records";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:36:" Query the database for some records";s:7:"context";N;}i:2;i:3;i:3;s:37:" Query the database for some records ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:44;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:44;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:171:"What if you wanted to query a record in your Database? In your SQL tool that you are using, you can querya record or it can be done in the IDE Terminal. Here's an example:";}i:2;i:51;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:222;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:224;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:500:"
<?php
\Tina4\Get::add("/player/names", function ($names, \Tina4\Response $response, \Tina4\Request $request) {
$names = (new UserName())->select("*") -->the table you want to select. It has to be is accordance with the Helper.
                         ->from("table_with_names") 
                         ->where (names = Peter) -->the filter you want 
                         ->asArray;
 return $response (\Tina4\renderTemplate("yourtemplate.twig", ["names" => $names, ), HTTP_OK, TEXT_HTML);
});
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:500:"
<?php
\Tina4\Get::add("/player/names", function ($names, \Tina4\Response $response, \Tina4\Request $request) {
$names = (new UserName())->select("*") -->the table you want to select. It has to be is accordance with the Helper.
                         ->from("table_with_names") 
                         ->where (names = Peter) -->the filter you want 
                         ->asArray;
 return $response (\Tina4\renderTemplate("yourtemplate.twig", ["names" => $names, ), HTTP_OK, TEXT_HTML);
});
";}i:2;i:234;}i:10;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:734;}i:11;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:741;}i:12;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:13;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}