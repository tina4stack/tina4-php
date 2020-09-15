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

*PLEASE NOTE:* If you want to install the IDE tools via command line, the commands will be available on the download pages also.
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
           
            After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "openssl version" and pushing Enter. 
            This will display the OpenSSL version if installed correctly. If an error is returned, the installation was unsuccessful. 
            In this case, uninstall OpenSSL and follow the steps again. 
            
## Install Composer

Follow these instructions to install Composer on your system. 

* Step 1 > Navigate to : https://getcomposer.org/download/

* Step 2 > Download the latest stable version (can be found under Manual Download)
     
* Step 3 > After the installation file download is complete, run the installation file and follow the prompts

The installation window will inform you once the installation is complete.

*PLEASE NOTE:* If you want to install Composer via command line, the commands will be available on the download pages also.

## Install OpenSSL

Follow these instructions to install OpenSSL on your system. 

* Step 1 > For Windows > Navigate to :https://slproweb.com/products/Win32OpenSSL.html
           For Mac & Linux > Navigate to :https://www.openssl.org/source/

* Step 2 > Download version v1.1.1 Light 

   Download the correct version for your  Operating System (e.g. Windows 10 32bit or 64bit, Mac or Linux). Download the installer or content.
     
* Step 3 > After the installation file download is complete, run the installation file and follow the prompts.

   The installation window will inform you once the installation is complete.

* Step 4 > Change path environment variable.
    
        Open Control Panel
        Go to System
        Go to Advanced system settings
        Go to Environment variable 
        Look for "Path" in the "Variable" column and click Edit.
        Click on Browse, locate the "bin" folder directory where you installed OpenSSL (e.g. C:\Program Files\OpenSSL-Win64\bin). 
        Click OK and then click Ok again to close the "Edit environment variable" window.  

*PLEASE NOTE:* If you want to install OpenSSL via command visit:

For Mac:
https://franz.com/support/openssl-mac.lhtml OR https://stackoverflow.com/questions/15185661/update-openssl-on-os-x-with-homebrew

For Unix:
https://cloudwafer.com/blog/installing-openssl-on-ubuntu-16-04-18-04/
OR
https://www.howtoforge.com/tutorial/how-to-install-openssl-from-source-on-linux/

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

The [ORM](https://en.wikipedia.org/wiki/Object-relational_mapping) in Tina4 tries to be as light as possible on coding, the basic form uses the object name to map to the table and assumes the first public variable you declare is the primary key.
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

ORM gives you the ability to write queries using [Object Oriented Programming](https://en.wikipedia.org/wiki/Object-oriented_programming) in your preferred programming language. Click the link for a list of [ORM software](https://en.wikipedia.org/wiki/List_of_object-relational_mapping_software) used for various programming languages. ORM allows you to interact with your database without using SQL.

### Example:

```php
<?php
class MySQLDatabase { //create class and name for your database

    /**
     * ORM constructor.
     * @param null $request A JSON input to populate the object\
     * @param boolean $fromDB True or false - is data from the database
     * @param string $tableName The name of the table that this object maps in the database
     * @param string $fieldMapping Mapping for fields in the array form ["field" => "table_field"]
     * @param string $primaryKey The primary key of the table
     * @param string $tableFilter A filter to limit the records when the data is fetched into the class
     * @param DataBase $DBA A relevant database connection to fetch information from
     * @throws Exception Error on failure
     * @example examples/exampleORMObject.php Create class object extending ORM
     */

```

## Annotate my REST for swagger

[Annotation](https://en.wikipedia.org/wiki/Annotation#Software_and_engineering) is adding extra information in your script associated with a particular function or statement. You can apply it to constructors, declarations, fields, methods, parameters and variables. 
Tina4 uses [Swagger](https://en.wikipedia.org/wiki/OpenAPI_Specification) which is an Open Source [REST API](https://en.wikipedia.org/wiki/Representational_state_transfer). Below you will see how to annotate your REST API with Swagger. Here you can explain how one component is dependant on another.

### Example:

```php
<?php
class MySQLDatabase { //create class and name for your database
/**
 * @description A swagger annotation
 * @tags Testing AUTH
 * @example AUTH
 * @secure
 */
}
```

## Secure a REST end point
An end point is also known as an Application Programming Interface (API), which allows your systems components to interact or communicate with each other.  It includes your website's URL and uses various methods to perform tasks (e.g GET, POST, PUT, PATCH). 
##### 8 Design Principles 
Saltzer and Schroeder wrote a paper called "[The Protection of Information in Computer Systems](http://web.mit.edu/Saltzer/www/publications/protection/)" which highlighted 8 design principles for securing your data and information:

* __Least Privilege__ : Only give a user the required permission they need to complete their task. Remove any access when no longer in use and more permission can be granted if required.
* __Economy of Mechanism__: Your design must be as simple as possible. Keep the components and interactions between everything (database, API's etc) simple to understand.
* __Fail-Safe Defaults__: The default access level to resources on your system for users should be "denied" unless explicit permission is granted.
* __Open Design__: This principle gives importance to building an open and transparent system. For example, the Open Source community where there are no confidential algorithms or secrets.
* __Complete Mediation__: Your system must validate user access rights and not rely on the cached permission matrix. If user access permission is revoked, but is not reflected in the permission matrix, then your system security is at risk. 
* __Least Common Mechanism__: This deals with the risk involved with sharing state between your systems components. If the shared state can be corrupted that means other components can be corrupted.   
* __Seperation of Privilege__: Granting a user permission should be based purely on a single (or combination of) condition, which is what type of permission is required for the type of task or resource.
* __Psychological Acceptability__: Simply put, your security should not make the user experience worse. Your mechanism should not make resources more difficult to access than if the mechanism were never present.  

##### Secure REST API Best Practice
Consider these factors or use it as a checklist for your system security when creating your REST API's 

* Think Simple : Make your system as secure as it needs to be, nothing more, nothing less. If you make it unnecessarily complex, there will be holes in your system which can be exploited or corrupted.  
* Use HTTPS :  By using a [SSL](https://www.ssl.com/faqs/faq-what-is-ssl/) handshake or protocol, the process of randomly generating access tokens or authentication credentials is simplified and makes your system secure.    

         For example, its safer to get a SSL certificate for your website.
         Your website URL will go from http://www.youtsite.com to https://www.yoursite.com

* Never expose information on URLs : API keys, session tokens, passwords and usernames must not appear in the URL as it is captured in server logs and makes your system exploitable. 

        This is an example of poor security as the username and security token is exposed in the URL:
        
        https://www.rickandmorty.com/authuser?user=ted&authz_token=1234&expire=1500000000
        
* Use OAuth : OAuth 2.0 authorization framework enables a third-party application to obtain limited access to an HTTP service, either on behalf of a resource owner by orchestrating an approval interaction between the resource owner and the HTTP service, or by allowing the third-party application to obtain access on its behalf.
* Use Password Hash : Increase the security of your system by keeping [passwords hashed](https://www.maketecheasier.com/what-is-password-hashing/). It transforms user passwords in your database into String and passwords cannot be retrieved if there is unauthorized access.
* Add Timestamps in Request : Add timestap parmeters to your API requests so that the server compares the timestamp of the request to the current timestamp. The request is only accepted if both timestamps are within reasonable timeframe. This safeguards your system from [brute force](https://en.wikipedia.org/wiki/Brute-force_attack) attacks or a [replay attack](https://en.wikipedia.org/wiki/Replay_attack).
* Input Validation : Use [validation](https://en.wikipedia.org/wiki/Data_validation) checks to make your system more secure. Any requests must be immediately rejected if validation fails. 

## Add a filter to process records when they are extracted from the database

We use the SELECT, FROM, WHERE, ORDER BY  and other statements when writing to or querying our database. You can add filters when processing records from your database.
Below is an example of how to add a filter when extracting or processing information from your database:

##### Example:
Lets assume a table in your database has this data stored:
```php
+----+------------+-----------+----------------------+
| id | first_name | last_name | email                |
+----+------------+-----------+----------------------+
|  1 | Peter      | Griffin   | peterg@mail.com      |
|  2 | Rick       | Sanchez   | ricks@mail.com       |
|  3 | Morty      | Smith     | mortys@mail.com      |
|  4 | Peter      | Parker    | perterp@mail.com     |
|  5 | Clancy     | Gilroy    | clancyg@mail.com     |
+----+------------+-----------+----------------------+
```
And the filter you want to add is for all the entries with the name "Peter":

```php
<?php
$servername = "localhost"; //input the credentials for each field, 
$username = "username";   //as it will give you access to the database.
$password = "password";
$dbname = "myDB";

// Create connection between the server and database
$conn = new sql($servername, $username, $password, $dbname);
// Check connection between the server and database, the unsuccessful the user gets error
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
// The asterisk below selects all the data in the table and the filter is added with the WHERE statement
$sql = "SELECT * 
          FROM MyGuests
         WHERE first_name='Peter'";
$result = $conn->query($sql);

if($result = query($conn, $sql)){
    if(num_rows($result) > 0){
        echo "<table>";
            echo "<tr>";
                echo "<th>id</th>";
                echo "<th>first_name</th>";
                echo "<th>last_name</th>";
                echo "<th>email</th>";
            echo "</tr>";
        while($row = fetch_array($result)){
            echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . $row['first_name'] . "</td>";
                echo "<td>" . $row['last_name'] . "</td>";
                echo "<td>" . $row['email'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        // Function to display result set or give error
        free_result($result);
    } else{
        echo "No records matching your query were found.";
    }
} else{
    echo "ERROR: Could not able to execute $sql. " . connect_error($conn);
}
$conn->close(); // close connection once query is handled
```
After your database has been filtered you will get a result with the following table:
```php
+----+------------+-----------+---------------------+
| id | first_name | last_name | email               |
+----+------------+-----------+---------------------+
|  1 | Peter      | Griffin   | peterg@mail.com     |
|  4 | Peter      | Parker    | peterp@mail.com     |
+----+------------+-----------+---------------------+
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
  

