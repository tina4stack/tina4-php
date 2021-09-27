a:29:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"header";i:1;a:3:{i:0;s:22:"Create an API Endpoint";i:1;i:1;i:2;i:1;}i:2;i:1;}i:2;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:3;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:1;}i:4;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:7:"Inside ";}i:2;i:40;}i:5;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:47;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:12:"/src/routes/";}i:2;i:49;}i:7;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:61;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:37:" we begin by creating a file for our ";}i:2;i:63;}i:9;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:3:"API";}i:2;i:100;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:9:" endpoint";}i:2;i:103;}i:11;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:112;}i:12;a:3:{i:0;s:4:"code";i:1;a:3:{i:0;s:244:"
<?php

/**
*
* @description My new api end point
* @tags items, testing
*
**/

\Tina4\Get::add("/api/items", function(\Tina4\Response $response) {

  $items = ["one", "two", "three"];

  return $response($items, HTTP_OK, APPLICATION_JSON)
});
";i:1;s:3:"php";i:2;s:25:"/src/routes/someroute.php";}i:2;i:119;}i:13;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:403;}i:14;a:3:{i:0;s:6:"header";i:1;a:3:{i:0;s:22:"Accessing our Endpoint";i:1;i:2;i:2;i:403;}i:2;i:403;}i:15;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:2;}i:2;i:403;}i:16;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:403;}i:17;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:81:"To access our enpoint we will navigate to the relevant path in our browser. E.g. ";}i:2;i:437;}i:18;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:518;}i:19;a:3:{i:0;s:12:"externallink";i:1;a:2:{i:0;s:31:"http://localhost:7145/api/items";i:1;N;}i:2;i:520;}i:20;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:551;}i:21;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:70:"
Also the swagger UI for all your annotated endpoints can be found at ";}i:2;i:553;}i:22;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:623;}i:23;a:3:{i:0;s:12:"externallink";i:1;a:2:{i:0;s:29:"http://localhost:7145/swagger";i:1;N;}i:2;i:625;}i:24;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:654;}i:25;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:0:"";}i:2;i:656;}i:26;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:656;}i:27;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:656;}i:28;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;i:656;}}