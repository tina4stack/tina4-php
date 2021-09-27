a:14:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:27:"Filter Database for an etry";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:28:" Filter Database for an etry";s:7:"context";N;}i:2;i:3;i:3;s:29:" Filter Database for an etry ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:36;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:36;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:175:"Lets say you want to exclude a field from the database. In your SQL tool that you are using, you can exclude a record or it can be done in the IDE Terminal. Here's an example:";}i:2;i:43;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:218;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:220;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:458:"
<?php

class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
    public $excludeFields = "password,myId";
}   

class OtherUser extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
    public $excludeFields = ["password","myId"];
}   
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:458:"
<?php

class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
    public $excludeFields = "password,myId";
}   

class OtherUser extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
    public $excludeFields = ["password","myId"];
}   
";}i:2;i:230;}i:10;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:688;}i:11;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:695;}i:12;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:13;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}