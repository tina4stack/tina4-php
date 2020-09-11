# How do I?

This is a quick reference for doing things in the Tina4 environment which will hopefully make your journey easier.
If you come up with something cool that you want to add here, please contribute.
## Install IDE tool
There are various IDE tools you can use to write code for your software or website,such as PHPStorm or Visual Studio Code. Depending on your Operating Software, you will need to install the correct installer or files for your OS. Follow these instructions to install an IDE tool on your system.

* Step 1 >

   For PHPStorm navigate to : https://www.jetbrains.com/phpstorm/download

   OR

   For Visual Studio Code navigate to : https://code.visualstudio.com/download

* Step 2  > Choose the correct installation for your Operating System (eg. Windows, Mac or Linux) and download the installer or installation files

* Step 3 > After the installation file download is complete, run the installation file and follow the prompts


The installation window will inform you once the installation is complete.

Please note, if you want to install the IDE tools via command line, the commands will be available on the download pages also.
## Install PHP 

Follow these instructions to install PHP on your system. 

* Step 1 > Navigate to : https://www.php.net/downloads.php

        Download the current stable version for your  Operating System. Download the zip files or content.

* Step 2 > Unpack the zip file or content into a directory of your choice.

        eg. C:/php
        
* Step 3 > Configure "php.ini" file: 
 
        Go to the folder where you extracted PHP. 
        Delete file named "php.ini". 
        Rename file named "php.ini-development" to "php-ini".
        Open php.ini file by opening it with Notepad or IDE tool 
            Find ';extension_dir = "ext"' and remove the semicolon infront of the word extension
            Find ";extension=curl" and remove the semicolon infront of the word extension
            Find ";extension=fileinfo" and remove the semicolon infront of the word extension
            Find ";extension=gd2" and remove the semicolon infront of the word extension
            Find ";extension=intl" and remove the semicolon infront of the word extension
            Find ";extension=mbstring" and remove the semicolon infront of the word extension
            Find ";extension=openssl" and remove the semicolon infront of the word extension
            Find ";extension=pdo_mysql" and remove the semicolon infront of the word extension
            Find ";extension=shmop" and remove the semicolon infront of the word extension
            Find ";extension=soap" and remove the semicolon infront of the word extension
            Find ";extension=sqlite3" and remove the semicolon infront of the word extension
            Find ";extension=tidy" and remove the semicolon infront of the word extension
            Find ";extension=xmlrpc" and remove the semicolon infront of the word extension
            Find ";extension=xsl" and remove the semicolon infront of the word extension
        Press Ctrl+S or save changes you just made to "php.ini" file. 

* Step 4 > Change path environment variable.
    
        Open Control Panel
        Go to System
        Go to Advanced system settings
        Go to Environment variable 
        Look for "Path" in the "Variable" column and click Edit.
        Click on Browse, locate the PHP folder you uxtracted. 
        Click OK and then click Ok again to close the "Edit environment variable" window.

* Step 5 > Complete Installation.

       To ensure your changes take effect you may restart your device. After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -v" and pushing Enter. 
       The PHP version and details will appear in the terminal.    
        
## Install Xdebug

Follow these instructions to install Xdebug on your system.

* Step 1 > Navigate to : https://xdebug.org/download
            
    Download the correct version for your  Operating System (e.g. Windows 10 32bit or 64bit, Mac or Linux). Download the installer or content. 
    
* Step 2 > After the download is complete, copy the file into the "ext" folder found in your PHP directory. (e.g. C:/php/ext) 
    
    It is recommended you rename the file (eg. "php_xdebug-2.9.6-7.4-vc15-x86_64.dll" ) to "php_xdebug.dll".

* Step 3 > Configure "php.ini" file:

        Go to your PHP folder and open "php.ini" with Notepad or your IDE tool. 
        Add the following line "zend_extension="E:\php\ext\php_xdebug.dll" in the extensions directory list (HINT: its above Module Settings) . 
            *Please ensure that the "php_xdebug.dll" file location is correct* 
        Add the following line "xdebug.remote_enable = 1" in the extensions directory list.
        Add the following line "xdebug.remote_autostart = 1 " in the extensions directory list.
        Press Ctrl+S or save changes you just made to "php.ini" file.
             
* Step 4 > Complete Installation.
           
            After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -m" and pushing Enter. 
            This will list all modules and you will see Xdebug listed in "PHP Modules" and "Zend Modules".
            
## Install Composer

Follow these instructions to install Composer on your system. 

* Step 1 > Navigate to : https://getcomposer.org/download/

* Step 2 > Download the latest stable version (can be found under Manual Download)
     
* Step 3 > After the installation file download is complete, run the installation file and follow the prompts

The installation window will inform you once the installation is complete.

Please note, if you want to install the IDE tools via command line, the commands will be available on the download pages also.

## Install OpenSSL


## Connect to a database

There are 3 database engines currently supported, we are happy to add more if required but for now they are:

* Firebird
* MySQL
* SQLite

The database connection is established in a global variable called `$DBA` in the index.php file for convenience, you could put it anywhere as long as it is global and required before any database functionality is required.

### Examples:

Notice the convention of using the `hostname:[database|database-path]` except when it is SQLite which then becomes the local path.

```php
   global $DBA;
   //MySQL
   $DBA = new \Tina4\DataMySQL("localhost:database", "admin", "cool1234");
   
   //Firebird 
   $DBA = new \Tina4\DataFirebird("localhost:/home/database/FIREBIRD.DB", "sysdba", "masterkey");

   //SQLite
   $DBA = new \Tina4\DataSQLite3("/home/someplace/my.db");
 
```
## Build a class with database access

By default we have a global ``$DBA`` variable and it will get passed into your class if you extend ``\Tina4\Data``.
It is advised to store these classes in the app folder.

### Example:

```php
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

The ORM in Tina4 tries to be as light as possible on coding, the basic form uses the object name to map to the table and assumes the first public variable you declare is the primary key.
It is required to extend the ``\Tina4\ORM`` class to make the magic happen.

### Examples:

```php
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


## Annotate my REST for swagger

## Secure a REST end point

## Add a filter to process records when they are extracted from the database

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
  

