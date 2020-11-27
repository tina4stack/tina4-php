<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->

# How do I?

This is a guide for doing things in the Tina4 environment which will hopefully make your journey easier.

## Create a basic landing page



## Connect to a database

There are 3 database engines currently supported, we are happy to add more if required but for now they are:

* Firebird
* MySQL
* SQLite

The database connection is established in a global variable called `$DBA` in the index.php file for convenience, you could put it anywhere as long as it is global and required before any database functionality is required.
For testing purpose, you can use the SQLite database type below.

### Examples:

Notice the convention of using the `hostname:[database|database-path]` except when it is SQLite which then becomes the local path.

For MySQL database type:
```php

<?php

global $DBA;

//MySQL
$DBA = new \Tina4\DataMySQL("localhost:somedatabase", DB_USERNAME, DB_PASSWORD, "d/m/Y");



```
For Firebird database type:
```php

<?php

global $DBA;

//Firebird 
$DBA = new \Tina4\DataFirebird("localhost:/home/database/FIREBIRD.DB", "sysdba", "masterkey");
     
```
For SQLite database type:
```php

<?php

global $DBA;

//SQLite
$DBA = new \Tina4\DataSQLite3("/home/someplace/my.db");


```

## Create a table/migration

You want to create a table for the database? Before you continue,please ensure you have a "migration" folder e.g. C:/yourprojectfolder/migration

### Example
```sh
Spin up a web server to view your porject on your web broser. Do this by running "php -S localhost:7145 index.php" in command terminal.
Once running you must add /migrate/create into your browser URL.
Create your table and click "Create Table".
You will be shown that the migration was created and a SQL file with the table you just created will be in the migration folder.  
```
![alt text](../assets/images/migrate.png)

  

## Build a class with database access

By default we have a global ``$DBA`` variable and it will get passed into your class if you extend ``\Tina4\Data``.
It is advised to store these classes in the app folder.

### Example:

```php

<?php

class MyDBActiveObject extends \Tina4\Data
{
    /**
    * Return all the fields in the test table
    **/
    function queryTheDB () {
        return $this->DBA->fetch('select * from table')->AsArray();
    }   

}

```

## Save a record using the ORM

The [ORM](https://en.wikipedia.org/wiki/Object-relational_mapping) in Tina4 tries to be as light as possible on coding, the basic form uses the object name to map to the table and assumes the first public variable you declare is the primary key.
It is required to extend the ``\Tina4\ORM`` class to make the magic happen.

### Examples:

```php

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

``` 

## Map my ORM object to a table with another name 

Well we did expect you would ask this question

### Example:

```php

class User extends \Tina4\ORM { //assumes we have a table user in the database
    public $tableName="my_weird_database_table"; //will make sure this object gets data to and from this table    
    public $id; //primary key because it is first
    public $name; //some additional data
}   

```

## Map my ORM field to a database field which doesn't follow the Tina4 pattern

### Example:

```php

<?php

public $fieldMapping = [
    "id" => "ID",
    "code" => "CODE",       
    "name" => "NAME"];

```
## Annotate my REST for swagger

Its good to explain how one component is dependant on another.

### Example:

```php
<?php

/**
 * @description A swagger annotation
 * @param $fields
 * 
 */

```
## Secure a REST end point

As easy as adding @secure in your annotation

### Example:

```php
<?php

/**
 * @description A swagger annotation
 * @param $fields
 * @secure
 */

```

## Add a filter to process records when they are extracted from the database

Lets say you want to filter the database for all the entries with the first name "Peter":

##### Example:
```php
<?php

$sql = "SELECT *   
          FROM MyGuests
         WHERE first_name='Peter'";

```


## Exclude some fields from being displayed on REST result or query

### Example:

```php

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

```

## Query the database for some records

```php
<?php

$sql = "SELECT *   
          FROM MyGuests
         WHERE first_name='Peter'";
$result = $conn->query($sql);
```

## Get an array of ORM objects

Given that you have an object extending \Tina4\ORM it will get "magical powers", namely the ability to create, load & save

## Add a REST end point

The REST end point is easily added, with an anonymous method to handle the request, the anonymous method should have the response variable.
The request exposes more information which comes from the browser, in the case of parameters passed to it. You should always return the ```$response``` object!;

### Examples
```php
//Standard
\Tina4\Get::add("/hello/world", function(\Tina4\Response $response, \Tina4\Request $request){  
    return $response("Hello World", HTTP_OK, TEXT_HTML);
});

//Inline Params
\Tina4\Get::add("/hello/world/{id}", function($id, \Tina4\Response $response, \Tina4\Request $request){
    return $response("Hello World {$id}", HTTP_OK, TEXT_HTML);
});

//Other methods you can test
\Tina4\Post::add(...);

\Tina4\Patch::add(...);

\Tina4\Put::add(...);

\Tina4\Delete::add(...);

//You guessed it - It takes every method - GET, POST, DELETE, PUT, PATCH, OPTIONS
\Tina4\Any::add(...);

```

## Use the same REST end point with two different names

We really hate to write too much code, a ``|`` will separate the different end points

### Example

```php
//Standard
\Tina4\Get::add("/hello/world|/hello/wrld", function(\Tina4\Response $response, \Tina4\Request $request){  
    return $response("Hello World", HTTP_OK, TEXT_HTML);
});
```

## Add my own filters to twig

If you need to add your own filters in Twig you use the config passed to Tina4Php on running Tina4 in the index file

### Examples
```php
$config = \Tina4\Config();

$config->addFilter("myFilter", function ($name) {
    return str_shuffle($name);
});

echo (new \Tina4\Tina4Php($config));
```
Somewhere in Twig, in a template, far far away ...
```twig
<label>{{ "NAME" | myFilter  }}</label>
```
  
## Add localized date format

If you want Tina to get the Users time and date information correct this is how

### Example
```php
<label for="startDate">Start Date</label>
<input class="form-control" type="datetime-local" name="startDate" placeholder="Start Date" value="{{ data.startDate | dateValue}}">
```


Go back to [how to make a webpage - landing page ](/tutorials/website.md) or return to [Tina4 - This is Not A Framework](/).