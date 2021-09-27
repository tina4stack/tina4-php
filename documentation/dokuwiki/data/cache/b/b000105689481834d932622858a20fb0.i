a:25:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:27:"Save a record using the ORM";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:28:" Save a record using the ORM";s:7:"context";N;}i:2;i:3;i:3;s:29:" Save a record using the ORM ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:6:"======";}i:2;i:36;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:36;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:4:"The ";}i:2;i:43;}i:7;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:55:"https://en.wikipedia.org/wiki/Object-relational_mapping";}s:7:"context";s:0:"";s:7:"linkTag";s:1:"a";}i:2;i:1;i:3;s:57:"[[https://en.wikipedia.org/wiki/Object-relational_mapping";}i:2;i:47;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:3:"ORM";s:7:"context";N;}i:2;i:3;i:3;s:4:"|ORM";}i:2;i:104;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:5:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:55:"https://en.wikipedia.org/wiki/Object-relational_mapping";}s:7:"payload";s:0:"";s:7:"context";s:0:"";s:7:"linkTag";s:1:"a";}i:2;i:4;i:3;s:2:"]]";}i:2;i:108;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:179:" in Tina4 tries to be as light as possible on coding, the basic form uses the object name to map to the table and assumes the first public variable you declare is the primary key.";}i:2;i:110;}i:11;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:289;}i:12;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:289;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:29:"It is required to extend the ";}i:2;i:291;}i:14;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:320;}i:15;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:10:"\Tina4\ORM";}i:2;i:322;}i:16;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:332;}i:17;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:32:" class to make the magic happen.";}i:2;i:334;}i:18;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:366;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:368;}i:20;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:1064:"
<?php

//we need a class extending the ORM
class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
}   

$user = (new User());
$user->name = "Test Save";
$user->save();

//We want the table to be made for us
class NewTable extends \Tina4\ORM { //will be created as newtable in the database
    /**
    *  @var id integer auto_increment  
    **/
    public $id; //primary key because it is first
    /**
    *  @var varchar(100) default 'Default Name'
    **/
    public $name; //some additional data
} 


$newTable = (new NewTable());
$newTable->name = "Test Save";
$user->save();

//How about some thing else

$newTable = (new NewTable('{"name":"TEST"}'));
$newTable->save();

//Or something else

$fields = ["name" => "Testing"];
$newTable = (new NewTable($fields));
$newTable->save();

//Or something else - request variable should obviously contain fields that match the class declared
$newTable = (new NewTable($_REQUEST));
$newTable->save();
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:1064:"
<?php

//we need a class extending the ORM
class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $id; //primary key because it is first
    public $name; //some additional data
}   

$user = (new User());
$user->name = "Test Save";
$user->save();

//We want the table to be made for us
class NewTable extends \Tina4\ORM { //will be created as newtable in the database
    /**
    *  @var id integer auto_increment  
    **/
    public $id; //primary key because it is first
    /**
    *  @var varchar(100) default 'Default Name'
    **/
    public $name; //some additional data
} 


$newTable = (new NewTable());
$newTable->name = "Test Save";
$user->save();

//How about some thing else

$newTable = (new NewTable('{"name":"TEST"}'));
$newTable->save();

//Or something else

$fields = ["name" => "Testing"];
$newTable = (new NewTable($fields));
$newTable->save();

//Or something else - request variable should obviously contain fields that match the class declared
$newTable = (new NewTable($_REQUEST));
$newTable->save();
";}i:2;i:378;}i:21;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:1442;}i:22;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:1449;}i:23;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:6:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:2;s:10:"combo_link";i:1;s:6:"strong";i:1;s:10:"combo_code";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:24;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}