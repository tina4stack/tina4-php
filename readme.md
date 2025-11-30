<img src="logo.svg" width="300">

Tina4 is a light-weight routing and twig based templating system which allows you to write websites and API applications
very quickly. Currently, the full deployment is under 8mb in size, and we are aiming at being the PHP framework with the smallest carbon footprint.
Due to the nature of the code being very compact and all functionality engineered from the ground up we trust you will find it a pleasant experience.

Join us on [Discord](https://discord.gg/UUkRq7sgSU) to be part of the journey.

The premise of the project is to make you the developer and PHP, the heroes!

[![PHP Composer](https://github.com/tina4stack/tina4-php/actions/workflows/php.yml/badge.svg)](https://github.com/tina4stack/tina4-php/actions/workflows/php.yml)

### Installing ###

We are currently testing on latest PHP 8.2, please report any issues you may find.

- Install PHP7.4 >  make sure the following extensions are enabled: fileinfo, mbstring, curl, gd, xml.
- Install Composer * Windows users must install openssl so that the JWT keys will be generated correctly
- Create a project folder where you want to work
- In your project folder terminal / console

#### Install with composer from terminal
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

Hit up http://localhost:7145 in your browser, you should see the documentation page

If you want to run the webservice on a specific port

```
composer start 8080
```

### Database support

The ORM and database modules are all extracted into their own packagist modules.
The ORM and database metadata work now using a more uniform mechanism. The service module now
is created under bin and tina4 service and tina4 bin executables are replaced when their checksums change.

Database support table

| Database   | Composer Command                                      |
|------------|-------------------------------------------------------|
| Sqlite3    | ```composer require tina4stack/tina4php-sqlite3```    |
| ODBC       | ```composer require tina4stack/tina4php-odbc```       |
| MySQL      | ```composer require tina4stack/tina4php-mysql```      |
| Firebird   | ```composer require tina4stack/tina4php-firebird```   |
| MongoDB    | ```composer require tina4stack/tina4php-mongodb```    |
| PostgreSQL | ```composer require tina4stack/tina4php-postgresql``` |
| MSSQL      | ```composer require tina4stack/tina4php-mssql```      |
| PDO        | ```composer require tina4stack/tina4php-pdo```        |

**Features**

- Auto templating with TWIG
- Auto inclusions & project structure
- Open API Annotations for quick Swagger documentation & security
- Annotation driven testing, write unit tests as you code
- Tina4 ORM
- Service Runner
- Async triggers and events
- Out of the box support for Swoole
- Modular programming, each project is a potential module.


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
docker run -v $(pwd):/app -p8080:8080 tina4stack/php:latest composer start 8080
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

Tina4 uses a .env file to set up project constants, a .env will be created for you when the system runs for the first
time. If you specify an environment variable on your OS called ENVIRONMENT then .env.ENVIRONMENT will be loaded instead.

```bash
[Section]           <-- Group section
MY_VAR=Test         <-- Example declaration, no quotes required or escaping, quotes will be treated as part of the variable
# A comment        <-- This is a comment
[Another Section]
VERSION=1.0.0
```

Do not include your .env files with your project if they contain sensitive information like password, instead create an
example of how it should look.

### Example of Routing

Creating API end points and routers in Tina4 is simple as indicated below. If you are adding swagger annotations, simply
hit up the /swagger end point to see the OpenApi rendering.

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

### Triggers and Events

Tina4Php supports a very limited threading or triggering of events using popen to execute and "thread" out triggered code.
There are some caveats as the code cannot have comments in and only simple variables can be used. Other than that almost anything can be accomplished.

#### Example of a trigger and it firing:

```php
//Example of the triggered event, notice the sleep timer which should shut down most code on windows or linux making PHP wait for the result.

\Tina4\Thread::addTrigger("me", static function($name, $sleep=1, $hello="OK"){
    $iCount = 0;
    while ($iCount < 10) {
        file_put_contents("./log/event.log", "Hello {$name} {$hello}!\n", FILE_APPEND);
        sleep($sleep);
        $iCount++;
    }
});
```

Here the trigger is fired on 2 routes, hit each one up in your browser to see the output in the event.log

```php
\Tina4\Get::add("/test", function(\Tina4\Response $response){
    
    \Tina4\Thread::trigger("me", ["Again", 1, "Moo!"]);

    return $response("OK!");
});

\Tina4\Get::add("/test/slow", function(\Tina4\Response $response){

    \Tina4\Thread::trigger("me", ["Hello", 3]);

    return $response("OK!");
});
```

The output to the event.log file should happen asynchronously whilst the routes return back immediately to the user browsing.

### Triggering deployments using git web hooks

There is a built-in path that will trigger a deployment from a github webhook on your system

```
https://<site-name>/git/deploy
```

This requires the following to be in your .env to work; and you will need to generate a secret to be shared between the systems.
Additionally, you can specify directories from your repository to be included in your deployment with ```GIT_DEPLOYMENT_DIRS```
Make sure you give permissions to git on the system you deploy to if you work with a private repository.
```
[DEPLOYMENT]
GIT_TINA4_PROJECT_ROOT=.
GIT_BRANCH=master
GIT_REPOSITORY=https://github.com/tina4stack/tina4-php.git
GIT_SECRET=0123456789
GIT_DEPLOYMENT_STAGING=..\staging
GIT_DEPLOYMENT_PATH=deploy-test
GIT_DEPLOYMENT_DIRS=["branding", "bin"]
SLACK_NOTIFICATION_CHANNEL="general"
```



### PhpDocs

```
docker run --rm -v %cd%:/data phpdoc/phpdoc:3 -d Tina4
```

### Building the docker

```
docker build . -t tina4stack/php:7.4
```

### Deploy the docker

```
 docker push tina4stack/php:7.4
```
### Jquery validate cheat sheet
https://gist.github.com/rhacker/3550309

### Todo
- Add health check
- Add GUID for each request, flow through to the rest of the code

### If homebrew breaks after running a pecl install ext for some reason - zsh: killed     php 
```
sudo chown -R "$(id -un)":"$(id -gn)" /opt/homebrew
```

### PHP info

Example of a PHP info route if needed.

```PHP
Route::get("/phpinfo", function(Response $response){
  ob_start();
  phpinfo();
  $data = ob_get_contents();
  ob_clean();
  return $response($data, HTTP_OK, TEXT_HTML);
});
```

## MacOS extensions for PHP

Example installing IMAP extension
```
brew tap kabel/php-ext
brew install php-imap
```