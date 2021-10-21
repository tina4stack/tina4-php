a:17:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:26:"Add my own filters to twig";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:27:" Add my own filters to twig";s:7:"context";N;}i:2;i:3;i:3;s:28:" Add my own filters to twig ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:35;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:35;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:118:"If you need to add your own filters in Twig you use the config passed to Tina4Php on running Tina4 in the index file. ";}i:2;i:42;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:160;}i:8;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:160;}i:9;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:18:"Here's an example:";}i:2;i:162;}i:10;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:180;}i:11;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:182;}i:12;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:194:"
<?php
require "vendor/autoload.php";

$config = \Tina4\Config();

$config->addFilter("myFilter", function ($name) {
    return str_shuffle($name);
});

echo (new \Tina4\Tina4Php($config));
```
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:194:"
<?php
require "vendor/autoload.php";

$config = \Tina4\Config();

$config->addFilter("myFilter", function ($name) {
    return str_shuffle($name);
});

echo (new \Tina4\Tina4Php($config));
```
";}i:2;i:192;}i:13;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:386;}i:14;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:393;}i:15;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:2;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:16;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}