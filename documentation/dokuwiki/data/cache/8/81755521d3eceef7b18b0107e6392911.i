a:24:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:22:"Save Dates to Database";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:23:" Save Dates to Database";s:7:"context";N;}i:2;i:3;i:3;s:24:" Save Dates to Database ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:31;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:31;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:5:"Your ";}i:2;i:38;}i:7;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:14:"tina4:database";}s:7:"context";s:0:"";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:16:"[[tina4:database";}i:2;i:43;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:11:"Date Format";s:7:"context";N;}i:2;i:3;i:3;s:12:"|Date Format";}i:2;i:59;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:14:"tina4:database";}s:7:"payload";s:0:"";s:7:"context";s:0:"";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:71;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:48:" should be set correctly in your index.php file.";}i:2;i:73;}i:11;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:121;}i:12;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:121;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:58:"From here you can save dates into an ORM object like this:";}i:2;i:123;}i:14;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:182;}i:15;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:182;}i:16;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:76:"
<?php

$article->publishedDate = date($article->DBA->dateFormat." H:i:s");
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:76:"
<?php

$article->publishedDate = date($article->DBA->dateFormat." H:i:s");
";}i:2;i:192;}i:17;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:268;}i:18;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:268;}i:19;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:108:"Any dates that you return from the ORM will follow your localization date format set in index.php I.e. d/m/Y";}i:2;i:277;}i:20;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:385;}i:21;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:385;}i:22;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:3;s:10:"combo_link";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:23;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}