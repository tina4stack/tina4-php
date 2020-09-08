# Getting Started
## What's Tina about?

This is not another framework. Keep things simple and efficient. Tina4 is perfect if you want to build a website, create APIs or if you want to learn a structured PHP framework.

## How Tina can help you about?

Tina uses the following methods to make development quicker

* PHP built in web server
* Twig Templating
* Annotated Routes for Swagger UI
* Simple code layout and structure

## Requires
* IDE development tool > Tool used when writing code, debugging or version control Eg. PHPStorm; Visual Studio Code
* PHP 7.1 >  Open source general-purpose scripting language used in software/web development
* Composer > PHP package manager used to manage dependence between your libraries and PHP software/website 
* OpenSSl > A toolkit used for Security Layer protocols between a computer network and Internet servers

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
echo .>.env 
php -S localhost:7145 index.php 

```

Browse to http://localhost:7145 in your favourite browser

## What did I just do?

* I created a tina4example folder
* I made an index.php with the bare minimum for Tina4 to run
* I installed the composer dependencies for tina4php
* I created env file
* I started up the inbuilt web server

Continue to [recommendations](recommendations.md) or [installation](installation.md)