## Tina4 - This is Not A Framework ##

Tina4 is a light-weight routing and twig based templating system which allows you to write websites and API applications
very quickly.

The premise of the project is to make you the developer and PHP, the heroes!

**News**

*February 1, 2022* - Added docker support for Postgres & MySQL

*December 26,2021* - Fixes for Swagger Examples using new DataField & HTTP Swoole example

*December 21,2021* - Added Openswoole to the docker image and some examples of using TCP service

*December 6, 2021* - Breaking updates, you need to include the database drivers as you require them now.
The ORM and database modules are all extracted into their own packagist modules.
The ORM and database metadata work now using a more uniform mechanism. The service module now
is created under bin and tina4service and tina4 bin executables are replaced when their checksums change.

Database support table

| Database      | Composer Command |
| ----------- | ----------- |
| Sqlite3      |  ```composer require tina4stack/tina4php-sqlite3```       |
| ODBC   | ```composer require tina4stack/tina4php-odbc```        |
| MySQL   | ```composer require tina4stack/tina4php-mysql ```        |
| Firebird   | ```composer require tina4stack/tina4php-firebird```        |
| MongoDB   | ```composer require tina4stack/tina4php-mongodb```        |
| PostgreSQL   | ```composer require tina4stack/tina4php-postgresql```        |


*June 13, 2021* - Adding docker support

*May 27, 2021* - Some fixes on caching, introduced TINA4_CACHED_ROUTES

*March 21, 2021* - This marks the release of a major update to the routing, it has been fully refactored and optimized.
Also updates to the debugging and modules make things much better for development.

*February 15, 2021* - Routing in large projects seems to be really messy and finding stuff is a pain. To this end you
can now direct your routing to class methods, they still behave the same as the anonymous methods but now make more
sense for grouping functionality. Also added back in, the ability to generate ORM objects directly from your database
using the command line tool.

*December 28, 2020* - We are getting close to a release point, there are still a number of bugs to be fixed though and
things to be documented. PHP 8.0 is not in a good place for database use from what we've tested.

**Features**

- Auto templating
- Auto inclusions & project structure
- Annotations for quick Swagger documentation & security
- Annotations for tests, write unit tests as you code
- Simple ORM
- Object Orientated HTML
- Service Runner
- Modular Programming

### Installing ###

*PHP 8.0 is not a stable candidate yet, for example some database functionlity is not completely supported*

- Install PHP7.3 >  make sure the following extensions are enabled php_fileinfo, mbstring, curl.
- Install Composer * Windows users must install openssl so that the JWT keys will be generated correctly
- Create a project folder where you want to work
- In your project folder terminal / console

```bash
composer require tina4stack/tina4php
```

#### Begin your Tina4 project using

```bash
composer exec tina4 initialize:run
```

#### Spin up a web server with PHP in your terminal in the project folder

```bash
composer start
````

Hit up http://localhost:7145 in your browser, you should see the 404 error

If you want to run the webservice on a specific port

```
composer start 8080
```

#### Run tests

```bash
composer test
```

#### Start service

```bash
composer start-service
```

#### Tina4 menu

```bash
composer tina4
```

*Note* The above command only seems to run on Linux and Mac

On Windows do the following:

```
php bin\tina4
```

### Working with Docker ###

This requires you to have your docker environment already running

We assume /app is the internal path for the current project
*Installing*

```
docker run -v $(pwd):/app tina4stack/php:latest composer require tina4stack/tina4php
```

```
docker run -v $(pwd):/app tina4stack/php:latest composer exec tina4 initialise:run
```

*Upgrading*

```
docker run -v $(pwd):/app -p7145:7145 tina4stack/php:latest composer upgrade 
```

*Running*

```
docker run -v $(pwd):/app -p7145:7145 tina4stack/php:latest composer start 
```

On a different port like 8080 for example

```
docker run -v $(pwd):/app --p8080:8080 tina4stack/php:latest composer start 8080
```

### Quick Reference ###

The folder layout is as follows and can be overridden by defining PHP constants for ```TINA4_TEMPLATE_LOCATIONS```
, ```TINA4_ROUTE_LOCATIONS``` & ```TINA4_INCLUDE_LOCATIONS```:

* src
    * app (helpers, PHP classes)
    * public (system twig files, images, css, js)
    * orm (ORM objects - extend \Tina4\ORM)
    * routes (routing)
    * scss - style sheet templates
    * services (service processes - extend \Tina4\Process)
    * templates (app twig files)

#### .Env Configuration

Tina4 uses a .env file to setup project constants, a .env will be created for you when the system runs for the first
time. If you specify an environment variable on your OS called ENVIRONMENT then .env.ENVIRONMENT will be loaded instead.

```bash
[Section]           <-- Group section
MY_VAR=Test         <-- Example declaration, no quotes required or escaping, quotes will be treated as part of the variable
# A commment        <-- This is a comment
[Another Section]
VERSION=1.0.0
```

Do not include your .env files with your project if they contain sensitive information like password, instead create an
example of how it should look.

### Example of Routing

Creating API end points and routers in Tina4 is simple as indicated below. If you are adding swagger annotations, simply
hitup the /swagger end point to see the OpenApi rendering.

```php
/**
* @description Swagger Description
* @tags Example,Route
*/
\Tina4\Get::add("/hello-world", function(\Tina4\Response $response){
    return $response ("Hello World!");
});
```

Routes can also be mapped to class methods, static methods are preferred for routing, but you can mix and match for
example if you want to keep all functionality neatly together.

```php
/**
 * Example of route calling class , method
 * Note the swagger annotations will go in the class
 */
\Tina4\Get::add("/test/class", ["Example", "route"]);

```

Example.php

```php

class Example
{
    public function someThing() {
        return "Yes!";
    }
    
    /**
     * @param \Tina4\Response $response
     * @return array|false|string
     * @description Hello Normal -> see Example.php route
     */
    public function route (\Tina4\Response $response) {
        return $response ("OK!");
    }

}
```

### Example of a database connection to SQLite3

You can add lines like this by using the tina4 tool or by pasting the example below into your index.php file.

```php

global $DBA;
$DBA = new \Tina4\DataSQLite3("test.db");
  
```

### Example of ORM Objects in relationship

```php
class Address extends \Tina4\ORM
{
    public $id;
    public $address;
    public $customerId;

    //Link up customerId => Customer object
    public $hasOne = [["Customer" => "customerId"]];
}

class Customer extends \Tina4\ORM
{
    public $primaryKey = "id";
    public $id;
    public $name;

    //Primary key id maps to customerId on Address table
    public $hasMany = [["Address" => "customerId"]];
}


````

And some code using the above objects

```php

$customer = (new Customer());
$customer->id = 1;
$customer->name = "Test";
$customer->save();

$address = (new Address());
$address->address = "1 Street";
$address->customerId = 1;
$address->save();

$customer = (new Customer());
$customer->addresses[0]->address = "Another Address";
$customer->addresses[0]->address->save(); //Save the address
$customer->load("id = 1");

$address = new Address();
$address->load("id = 1");
$address->address = "New Street Address";
$address->customer->name = "New Name for customer"
$address->customer->save(); //save the customer
$address->save();

```

### Run tests from the command line

Give this a try and see what happens

```commandline
composer test
```

Writing unit tests is easy and can be done as an annotation in your code comments

```php

/**
 * Some function to add numbers
 * @tests
 *   assert (1,1) === 2, "1 + 1 = 2"
 *   assert is_integer(1,1) === true, "This should be an integer"
 */
function add ($a,$b) {
    return $a+$b;
}

```

### Change Log

```
2021-12-26 Fixes for swagger & http openswoole example
2021-12-21 Added openswoole to the docker image & example of use
2021-12-06 Version 2.0.0 released with database modules and orm separated out for better support
2021-06-13 Added docker support and better logging
2021-03-21 Refactored routing, added better debugging, release candidate now in action
2021-03-05 Added foreign table support to ORM, minor fixes and improvements to testing & annotations, auto migrations on objects
2021-02-21 Added ability to configure database connections via vendor/tina4/bin
2021-02-15 New! Routes can now be directed to Class methods, ORM generation available in tina4
2021-02-13 Fixes for Firebird database engine released
2021-01-10 SCSS building added
2020-12-28 MySQL fixes on error debugging
2020-12-25 Added named param binding for SQLite3
2020-12-19 Added Annotations for Unit Testing
2020-12-14 Fixes for MySQL not handling saving of nulls in bind_params
2020-12-08 Fixes for MySQL & ORM saving
2020-12-08 Fixes for isBinary under Utilities
```
### PhpDocs

```
docker run --rm -v %cd%:/data phpdoc/phpdoc:3 -d Tina4
```

### Building the docker

```
docker build . -t tina4stack/php
```

### Deploy the docker

```
 docker push tina4stack/php
```
### Jquery validate cheat sheet
https://gist.github.com/rhacker/3550309