a:14:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:46:"Map my ORM object to a table with another name";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:47:" Map my ORM object to a table with another name";s:7:"context";N;}i:2;i:3;i:3;s:48:" Map my ORM object to a table with another name ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:55;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:55;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:141:"If you require to extend or map your ORM object to a database field which doesn't follow the Tina4 pattern, take a look at the example below:";}i:2;i:62;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:203;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:205;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:298:"
<?php

class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $tableName="my_weird_database_table"; //will make sure this object gets data to and from this table    
    public $id; //primary key because it is first
    public $name; //some additional data
}   
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:298:"
<?php

class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $tableName="my_weird_database_table"; //will make sure this object gets data to and from this table    
    public $id; //primary key because it is first
    public $name; //some additional data
}   
";}i:2;i:215;}i:10;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:513;}i:11;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:520;}i:12;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:13;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}