a:34:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:39:"Errors while connecting to the Database";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:40:" Errors while connecting to the Database";s:7:"context";N;}i:2;i:3;i:3;s:41:" Errors while connecting to the Database ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:7:"======
";}i:2;i:48;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:48;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:85:"You may have seen that in Tina4, if we require a Database, which is included via the ";}i:2;i:56;}i:7;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:141;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:9:"index.php";}i:2;i:143;}i:9;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:152;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:34:" located in project root via the :";}i:2;i:154;}i:11;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:188;}i:12;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:190;}i:13;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:264:"
<?php

require "vendor/autoload.php";

global $DBA;

//MySQL
$DBA = new \Tina4\DataMySQL(
   https://tina4.com/documentation/doku.php?id=playground:playground "localhost:somedatabase",
    DB_USERNAME, DB_PASSWORD,
    "d/m/Y");
    
 echo new \Tina4\Tina4Php();
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:264:"
<?php

require "vendor/autoload.php";

global $DBA;

//MySQL
$DBA = new \Tina4\DataMySQL(
   https://tina4.com/documentation/doku.php?id=playground:playground "localhost:somedatabase",
    DB_USERNAME, DB_PASSWORD,
    "d/m/Y");
    
 echo new \Tina4\Tina4Php();
";}i:2;i:200;}i:14;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:464;}i:15;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:478;}i:16;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:3;s:12:"heading_text";s:27:"What errors you may expect?";}s:7:"context";s:7:"outline";s:8:"position";i:474;}i:2;i:1;i:3;s:4:"====";}i:2;i:474;}i:17;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:28:" What errors you may expect?";s:7:"context";N;}i:2;i:3;i:3;s:29:" What errors you may expect? ";}i:2;i:478;}i:18;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:3;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:5:"====
";}i:2;i:507;}i:19;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:3;}i:2;i:474;}i:20;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:507;}i:21;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:91:"If you experience any trouble connecting to the DB you can try troubleshoot the following:
";}i:2;i:513;}i:22;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:604;}i:23;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:74:"
1. Ensure that you have the Database native app installed on your device
";}i:2;i:606;}i:24;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:680;}i:25;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:81:"
2. Ensure the correct Database type is being used for your project requirements
";}i:2;i:682;}i:26;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:763;}i:27;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:78:"
3. Ensure the Database version is compatible with SQL, IDE and other tools. 
";}i:2;i:765;}i:28;a:3:{i:0;s:9:"linebreak";i:1;a:0:{}i:2;i:843;}i:29;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:67:"
4. Ensure you have all the Tina4 prerequisites installed correctly";}i:2;i:845;}i:30;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:912;}i:31;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:912;}i:32;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:2;s:7:"section";i:2;s:1:"p";i:2;s:6:"strong";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:33;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}