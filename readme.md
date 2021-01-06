## Tina4 - This is Not A Framework ##

Tina4 is a light-weight routing and twig based templating system which allows you to write websites and API applications very quickly.

The premise of the project is to make you the developer and PHP, the heroes!

**Beta Testing**

[Join our Slack Channel to participate and receive all the latest builds](https://docs.google.com/forms/d/e/1FAIpQLSdrapVxI-19DapgKKuhtlLyPc99SLg8Re2Lpn3PS_K0M2Rc7w/viewform)

**News**

*December 28, 2020* - We are getting close to a release point, there are still a number of bugs to be fixed though and things to be documented. PHP 8.0 is not in a good place for database use from what we've tested.

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

- Install PHP7.1 > make sure the php_fileinfo extension and mbstring extension are enabled.
- Install composer
- Create a project folder where you want to work
- In your project folder terminal / console
```bash
composer require andrevanzuydam/tina4php
```
- Windows
```bash
php vendor\bin\tina4
```
- Mac/Linux
```bash
vendor/bin/tina4
```

```bash
====================================================================================================
TINA4 - MENU 
====================================================================================================
1.) Create index.php
2.) Run Tests
3.) Create database connection
Choose menu option or type "quit" to Exit:
```

- Choose option 1 and press Enter, then type quit to exit, press Enter.


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
     * scss - style sheet templates  
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
2020-12-28 MySQL fixes on error debugging
2020-12-25 Added named param binding for SQLite3
2020-12-19 Added Annotations for Unit Testing
2020-12-14 Fixes for MySQL not handling saving of nulls in bind_params
2020-12-08 Fixes for MySQL & ORM saving
2020-12-08 Fixes for isBinary under Utilities
```
