# How do I?

This is a quick reference for doing things in the Tina4 environment which will hopefully make your journey easier.

## Connect to a database

There are 3 database engines currently supported, we are happy to add more if required but for now they are:

* Firebird
* MySQL
* SQLite

The database connection is established in a global variable called `$DBA` in the index.php file for convinience, you could put it anywhere as long as it is global and required before any database functionlity is required.

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

## Query the database for some records


## Get an array of ORM objects

Given that you have an object extending \Tina4\ORM it will get "magical powers", namely the ability to create, load & save

## Add a REST end point

The REST end point is easily added with an anonymous method to handle the request, the anonymous method should have the response variable.
The request exposes more information which comes from the browser, in the case of parameters.

```php
//Standard
\Tina4\Get::add("/utilities/import/subscription-types", function(\Tina4\Response $response, \Tina4\Request $request){

});

//Inline Params
\Tina4\Get::add("/utilities/import/subscription-types/{id}", function($id, \Tina4\Response $response, \Tina4\Request $request){
    
});


```

