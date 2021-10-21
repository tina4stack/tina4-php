a:65:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:24:"Connecting to a database";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:25:" Connecting to a database";s:7:"context";N;}i:2;i:3;i:3;s:26:" Connecting to a database ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:33;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:33;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:52:"Tina4 currently supports 3 database types which are:";}i:2;i:40;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:92;}i:8;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:92;}i:9;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:11:"* Firebird
";}i:2;i:94;}i:10;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:105;}i:11;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:9:"
* MySQL
";}i:2;i:107;}i:12;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:116;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:9:"
* SQLite";}i:2;i:118;}i:14;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:127;}i:15;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:127;}i:16;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:67:"The database connection is established in a global variable called ";}i:2;i:129;}i:17;a:3:{i:0;s:18:"doublequoteopening";i:1;a:0:{}i:2;i:196;}i:18;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:4:"$DBA";}i:2;i:197;}i:19;a:3:{i:0;s:18:"doublequoteclosing";i:1;a:0:{}i:2;i:201;}i:20;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:149:" in the index.php file for convenience, you could put it anywhere as long as it is global and required before any database functionality is required.";}i:2;i:202;}i:21;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:351;}i:22;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:351;}i:23;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:353;}i:24;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:21:"Connecting to SQLite3";}i:2;i:355;}i:25;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:376;}i:26;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:378;}i:27;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:378;}i:28;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:40:"The simplest connection to configure is ";}i:2;i:380;}i:29;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:420;}i:30;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:7:"SQLite3";}i:2;i:422;}i:31;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:429;}i:32;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:15:" database type:";}i:2;i:431;}i:33;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:446;}i:34;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:448;}i:35;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:74:"
<?php 

global $DBA;

//SQLite
$DBA = new \Tina4\DataSQLite3("test.db");
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:74:"
<?php 

global $DBA;

//SQLite
$DBA = new \Tina4\DataSQLite3("test.db");
";}i:2;i:458;}i:36;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:532;}i:37;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:532;}i:38;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:541;}i:39;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:19:"Connecting to MySQL";}i:2;i:543;}i:40;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:562;}i:41;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:564;}i:42;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:564;}i:43;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:56:"This is how to configure your database connection using ";}i:2;i:566;}i:44;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:622;}i:45;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:5:"MySQL";}i:2;i:624;}i:46;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:629;}i:47;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:16:" database type: ";}i:2;i:631;}i:48;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:647;}i:49;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:649;}i:50;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:120:"
<?php

global $DBA;

//MySQL
$DBA = new \Tina4\DataMySQL("localhost:somedatabase", DB_USERNAME, DB_PASSWORD, "d/m/Y");
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:120:"
<?php

global $DBA;

//MySQL
$DBA = new \Tina4\DataMySQL("localhost:somedatabase", DB_USERNAME, DB_PASSWORD, "d/m/Y");
";}i:2;i:659;}i:51;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:779;}i:52;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:779;}i:53;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:56:"This is how to configure your database connection using ";}i:2;i:788;}i:54;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:844;}i:55;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:8:"FireBird";}i:2;i:846;}i:56;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:854;}i:57;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:16:" database type: ";}i:2;i:856;}i:58;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:872;}i:59;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:874;}i:60;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:129:"
<?php

global $DBA;

//Firebird 
$DBA = new \Tina4\DataFirebird("localhost:/home/database/FIREBIRD.DB", "sysdba", "masterkey");
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:129:"
<?php

global $DBA;

//Firebird 
$DBA = new \Tina4\DataFirebird("localhost:/home/database/FIREBIRD.DB", "sysdba", "masterkey");
";}i:2;i:884;}i:61;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:1013;}i:62;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:1020;}i:63;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:8;s:6:"strong";i:5;s:10:"combo_code";i:3;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:64;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}