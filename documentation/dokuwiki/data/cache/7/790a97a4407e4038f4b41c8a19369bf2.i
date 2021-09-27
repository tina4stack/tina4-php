a:35:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:29:"Let my class use the database";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:30:" Let my class use the database";s:7:"context";N;}i:2;i:3;i:3;s:31:" Let my class use the database ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:38;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:38;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:15:"You will build ";}i:2;i:45;}i:7;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:60;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:7:"classes";}i:2;i:62;}i:9;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:69;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:140:" to define your objects, routes, variables etc. A class will have your functions and variables in it. We call these functions and variables ";}i:2;i:71;}i:11;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:211;}i:12;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:7:"objects";}i:2;i:213;}i:13;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:220;}i:14;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:2:". ";}i:2;i:222;}i:15;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:224;}i:16;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:224;}i:17;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:170:"A class can be a collection of objects which has different behaviors and properties. For example, you may want to extend your class so that it connects to your database. ";}i:2;i:226;}i:18;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:396;}i:19;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:396;}i:20;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:23:"Extend your class with ";}i:2;i:398;}i:21;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:421;}i:22;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:11:"\Tina4\Data";}i:2;i:423;}i:23;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:434;}i:24;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:1:":";}i:2;i:436;}i:25;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:438;}i:26;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:438;}i:27;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:200:"
<?php

   class MyClass extends \Tina4\Data {
      
      function doSomething() {
         $records = $this->DBA->select ()->from("someTable")->asArray();
         return $records;
      }  
   }
 ";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:200:"
<?php

   class MyClass extends \Tina4\Data {
      
      function doSomething() {
         $records = $this->DBA->select ()->from("someTable")->asArray();
         return $records;
      }  
   }
 ";}i:2;i:448;}i:28;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:648;}i:29;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:648;}i:30;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:154:"Above you will see an example of building a class where you link to the database, specify the required fields and make it return the values as an array.  ";}i:2;i:657;}i:31;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:657;}i:32;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:811;}i:33;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:4;s:6:"strong";i:3;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:34;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}