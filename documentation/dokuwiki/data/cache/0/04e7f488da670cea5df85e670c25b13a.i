a:111:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:0;}i:2;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:1;}i:3;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:173:"Create Post Route using Tina4*
Your form will need to be secure by using the POST ROUTE which looks as follows and will need a FORM KEY token to validate

\Tina4\Post::add (";}i:2;i:3;}i:4;a:3:{i:0;s:18:"doublequoteopening";i:1;a:0:{}i:2;i:176;}i:5;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:10:"/test/post";}i:2;i:177;}i:6;a:3:{i:0;s:18:"doublequoteclosing";i:1;a:0:{}i:2;i:187;}i:7;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:65:", function(\Tina4\Response $response, 
\Tina4\Request $request) {";}i:2;i:188;}i:8;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:253;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:253;}i:10;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:60:"return $response ("Hello {$request->param["someInput"]}!");
";}i:2;i:4;i:3;s:1:"
";}i:2;i:315;}i:11;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:315;}i:12;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:152:"});  

====== The Route of It ======

Tina4 supports 2 types of routes, those created in code and those that are assumed based on files you drop in the ";}i:2;i:316;}i:13;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:468;}i:14;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:13:"src/templates";}i:2;i:470;}i:15;a:3:{i:0;s:11:"strong_open";i:1;a:1:{i:1;N;}i:2;i:483;}i:16;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:184:" folder.

===== Dynamic Routing Using Files in src/templates =====

Consider the following folder structure and the routes you can hit up in the browser.

  * src/templates/index.html ";}i:2;i:485;}i:17;a:3:{i:0;s:6:"entity";i:1;a:1:{i:0;s:2:"->";}i:2;i:669;}i:18;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:2:"  ";}i:2;i:671;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:22:"http://localhost:7145/";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:24:"[[http://localhost:7145/";}i:2;i:673;}i:20;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:22:"http://localhost:7145/";}s:7:"payload";s:22:"http://localhost:7145/";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:697;}i:21;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:4:" or ";}i:2;i:699;}i:22;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:27:"http://localhost:7145/index";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:29:"[[http://localhost:7145/index";}i:2;i:703;}i:23;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:27:"http://localhost:7145/index";}s:7:"payload";s:27:"http://localhost:7145/index";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:732;}i:24;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:36:"
  * src/templates/store/index.html ";}i:2;i:734;}i:25;a:3:{i:0;s:6:"entity";i:1;a:1:{i:0;s:2:"->";}i:2;i:770;}i:26;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:1:" ";}i:2;i:772;}i:27;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:27:"http://localhost:7145/store";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:29:"[[http://localhost:7145/store";}i:2;i:773;}i:28;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:27:"http://localhost:7145/store";}s:7:"payload";s:27:"http://localhost:7145/store";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:802;}i:29;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:4:" or ";}i:2;i:804;}i:30;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:33:"http://localhost:7145/store/index";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:35:"[[http://localhost:7145/store/index";}i:2;i:808;}i:31;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:33:"http://localhost:7145/store/index";}s:7:"payload";s:33:"http://localhost:7145/store/index";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:843;}i:32;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:35:"
  * src/templates/store/shop.html ";}i:2;i:845;}i:33;a:3:{i:0;s:6:"entity";i:1;a:1:{i:0;s:2:"->";}i:2;i:880;}i:34;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:1:" ";}i:2;i:882;}i:35;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:32:"http://localhost:7145/store/shop";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:34:"[[http://localhost:7145/store/shop";}i:2;i:883;}i:36;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:32:"http://localhost:7145/store/shop";}s:7:"payload";s:32:"http://localhost:7145/store/shop";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:917;}i:37;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:0:"";}i:2;i:919;}i:38;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:921;}i:39;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:1;i:1;s:17:"center round info";}i:2;i:1;i:3;s:24:"<WRAP center round info>";}i:2;i:921;}i:40;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:921;}i:41;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:122:"You can use either .twig or .html extensions. The html extension will cause the built in Tina4 template engine to be used.";}i:2;i:946;}i:42;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:1069;}i:43;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:4;i:1;s:0:"";}i:2;i:4;i:3;s:7:"</WRAP>";}i:2;i:1069;}i:44;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:1069;}i:45;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:352:"

===== Coded Routing in src/routes with subfolders =====

The folders are simply suggested folders where you can place php files to manage your routing.  Any php file place in the routing folder and sub folders will automatically be parsed and run when Tina4 is hit up.

Try placing the following code in a php file of your choice in the routes folder";}i:2;i:1076;}i:46;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:1429;}i:47;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:1429;}i:48;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1437;}i:49;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1440;}i:50;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1512;}i:51;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1551;}i:52;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:117:"<?php
\Tina4\Get::add ("/test/route", function(\Tina4\Response $response) {
  return $response ("Hello World!");
});
";}i:2;i:4;i:3;s:1:"
";}i:2;i:1557;}i:53;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:1557;}i:54;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:23:"
Test it by hitting up ";}i:2;i:1558;}i:55;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:32:"http://localhost:7145/test/route";}s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:34:"[[http://localhost:7145/test/route";}i:2;i:1581;}i:56;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:32:"http://localhost:7145/test/route";}s:7:"payload";s:32:"http://localhost:7145/test/route";s:7:"context";s:6:"strong";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:1615;}i:57;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:61:"  

A route with inline parameters can be composed as follows";}i:2;i:1617;}i:58;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:1678;}i:59;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:1678;}i:60;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1681;}i:61;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1723;}i:62;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1770;}i:63;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1811;}i:64;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:128:"\Tina4\Get::add ("/test/route/{name}", 
function($name, \Tina4\Response $response) {
  return $response ("Hello {$name}!");
});
";}i:2;i:4;i:3;s:1:"
";}i:2;i:1817;}i:65;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:1817;}i:66;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:1:"
";}i:2;i:1818;}i:67;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:1819;}i:68;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:72:"A POST ROUTE looks as follows and will need a FORM KEY token to validate";}i:2;i:1821;}i:69;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:1893;}i:70;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:0:"";}i:2;i:1895;}i:71;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:1896;}i:72;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:1896;}i:73;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1904;}i:74;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1907;}i:75;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:1978;}i:76;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2007;}i:77;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2071;}i:78;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:168:"<?php
\Tina4\Post::add ("/test/post", function(\Tina4\Response $response, 
\Tina4\Request $request) {
  return $response ("Hello {$request->param["someInput"]}!");
});
";}i:2;i:4;i:3;s:1:"
";}i:2;i:2077;}i:79;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:2077;}i:80;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:2:"

";}i:2;i:2078;}i:81;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:2080;}i:82;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:28:" Routing directly to a class";}i:2;i:2082;}i:83;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:2111;}i:84;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:2111;}i:85;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2169;}i:86;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2173;}i:87;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2192;}i:88;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2197;}i:89;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2260;}i:90;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2294;}i:91;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2331;}i:92;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2340;}i:93;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:216:" \Tina4\Get::add("/test", ["TestClass", "someRouter"]);
 
 class TestClass
 {
     public function someRouter (\Tina4\Response $response, 
     \Tina4\Request $request) {
        return $response("Hello");
     }
 }
";}i:2;i:4;i:3;s:1:"
";}i:2;i:2345;}i:94;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:1;i:1;s:17:"center round info";}i:2;i:1;i:3;s:24:"<WRAP center round info>";}i:2;i:2347;}i:95;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:2347;}i:96;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:97:"On twig templates you can add this simple filter to include a formToken for you as a hidden input";}i:2;i:2372;}i:97;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:2469;}i:98;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:2469;}i:99;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:1:{s:5:"state";i:2;}i:2;i:2;i:3;s:3:"
  ";}i:2;i:2514;}i:100;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:43:"{{ "reason for token" | formToken | raw }}
";}i:2;i:4;i:3;s:1:"
";}i:2;i:2517;}i:101;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:2517;}i:102;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:51:"Alternatively  you can use the twig global variable";}i:2;i:2518;}i:103;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:2570;}i:104;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:0:{}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:2570;}i:105;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:16:"{{formToken}}  
";}i:2;i:4;i:3;s:1:"
";}i:2;i:2588;}i:106;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:4;i:1;s:0:"";}i:2;i:4;i:3;s:7:"</WRAP>";}i:2;i:2589;}i:107;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:15:"hasEmptyContent";b:1;}}i:2;i:1;i:3;s:3:"
  ";}i:2;i:2596;}i:108;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:18:"combo_preformatted";i:1;a:2:{s:5:"state";i:4;s:7:"payload";s:0:"";}i:2;i:4;i:3;s:1:"
";}i:2;i:2599;}i:109;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:1:"p";i:9;s:6:"strong";i:3;s:18:"combo_preformatted";i:8;s:10:"combo_link";i:6;s:12:"wrap_divwrap";i:2;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:110;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}