a:30:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:34:"Build a class with database access";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:35:" Build a class with database access";s:7:"context";N;}i:2;i:3;i:3;s:36:" Build a class with database access ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:7:"======
";}i:2;i:43;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:43;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:23:"Any class that extends ";}i:2;i:51;}i:7;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:74;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:11:"\Tina4\Data";}i:2;i:76;}i:9;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:87;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:31:" will have a database variable ";}i:2;i:89;}i:11;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:120;}i:12;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:5:"$this";}i:2;i:122;}i:13;a:3:{i:0;s:6:"entity";i:1;a:1:{i:0;s:2:"->";}i:2;i:127;}i:14;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:3:"DBA";}i:2;i:129;}i:15;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:132;}i:16;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:29:" to access the database from.";}i:2;i:134;}i:17;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:163;}i:18;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:165;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:217:"
<?php

class MyDbObject extends \Tina4\Data
{
    /**
    * Return all the fields in the test table
    **/
    function queryTheDB () {
        return $this->DBA->fetch('select * from table')->AsArray();
    }   
}
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:217:"
<?php

class MyDbObject extends \Tina4\Data
{
    /**
    * Return all the fields in the test table
    **/
    function queryTheDB () {
        return $this->DBA->fetch('select * from table')->AsArray();
    }   
}
";}i:2;i:175;}i:20;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:392;}i:21;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:392;}i:22;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:31:"Or you can try this convention:";}i:2;i:401;}i:23;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:432;}i:24;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:434;}i:25;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:224:"
<?php

class MyDbObject extends \Tina4\Data
{
    /**
    * Return all the fields in the test table
    **/
    function getSomethingFromDb() {
        return $this->DBA-->select ("*")->from("table")->asArray();
    }   
}
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:224:"
<?php

class MyDbObject extends \Tina4\Data
{
    /**
    * Return all the fields in the test table
    **/
    function getSomethingFromDb() {
        return $this->DBA-->select ("*")->from("table")->asArray();
    }   
}
";}i:2;i:444;}i:26;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:668;}i:27;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:675;}i:28;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:5:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:2;s:6:"strong";i:2;s:10:"combo_code";i:2;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:29;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}