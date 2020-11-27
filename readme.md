## Tina4 - This is Not Another Framework ##

Tina4 is a light weight routing and templating system which allows you to write websites and API applications very quickly.

**Features**

- Auto templating
- Auto inclusions & project structure
- Annotations for quick Swagger documentation & security

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

