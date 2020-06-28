# Getting Started
## What's Tina about ?

Tina uses the following methods to make development quicker

* PHP built in web server
* Twig Templating
* Annotated Routes for Swagger UI
* Simple code layout and structure

## Requires
* PHP 7.1 >
* Composer

## Who should use Tina4 ?

* Anyone who wants to build a website quickly using the Twig template engine
* React or Angular developers who want a way to create quick APIs in PHP
* Old School PHP devs who don't want to learn a frame work but want some structure

## I want to get straight to it!

You can do this if your composer and PHP are already available to you on your command line or terminal:

```sh
mkdir tina4example
cd tina4example
echo '<?php require "vendor/autoload.php"; echo new \Tina4\Tina4Php();' > index.php
composer require andrevanzuydam/tina4php 
php -S localhost:7145 index.php 
```

Browse to http://localhost:7145 in your favourite browser

## What did I just do?

* I created a tina4example folder
* I made and index.php with the bare minimum for Tina4 to run
* I installed the composer dependencies for tina4php
* I started up the inbuilt web server

Continue to [recommendations](recommendations.md) or [installation](installation.md)