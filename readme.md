## Tina4 - This is Not A Framework ##

Tina4 is a light-weight routing and twig based templating system which allows you to write websites and API applications very quickly.

The premise of the project is to make you the developer and PHP, the heroes!

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

- Install PHP7.1 > make sure the php_fileinfo extension and mbstring extension are enabled.
- Install composer
- Create a project folder where you want to work
- In your project folder terminal / console
```bash
composer require andrevanzuydam/tina4php
```
- Create an index.php file in your project folder and add this code
```php
<?php
 require "./vendor/autoload.php"; 
 echo new \Tina4\Tina4Php();
``` 
- Spin up a web server with PHP in your terminal in the project folder
```bash
php -S localhost:8080 index.php
```
- Hit up http://localhost:8080 in your browser, you should see the 404 error

### Quick Reference ###

The folder layout is as follows and can be overridden by defining PHP constants for ```TINA4_TEMPLATE_LOCATIONS```, ```TINA4_ROUTE_LOCATIONS``` & ```TINA4_INCLUDE_LOCATIONS```:

  * src
     * api (routing)
     * app (helpers, PHP classes)
     * assets (system twig files, images, css, js)
     * objects (ORM objects - extend \Tina4\ORM)
     * routes (app routing)
     * services (service processes - extend \Tina4\Process)
     * templates (app twig files)
     
### .Env Configuration

Tina4 uses a .env file to setup project constants, a .env will be created for you when the system runs for the first time.
If you specify an environment variable on your OS called ENVIRONMENT then .env.ENVIRONMENT will be loaded instead.

```bash
[Section]           <-- Group section
MY_VAR=Test         <-- Example declaration, no quotes required or escaping, quotes will be treated as part of the variable
# A commment        <-- This is a comment
[Another Section]
VERSION=1.0.0
```
Do not include your .env files with your project if they contain sensitive information like password, instead create an example of how it should look.

### Change Log
```
2020-12-19 Added Annotations for Unit Testing
2020-12-14 Fixes for MySQL not handling saving of nulls in bind_params
2020-12-08 Fixes for MySQL & ORM saving
2020-12-08 Fixes for isBinary under Utilities
```
