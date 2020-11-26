<!--
// Tina4 : This Is Not Another Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andre@codeinfinity.co.za
-->
# Install Tina4

## Introduction

Please follow the steps below to get Tina4 on your system. Make sure you have the prerequisites installed ([php](install-php.md), [composer](install-composer.md), [openssl](install-openssl.md) & [ide tool](install-ide.md)). Soon you will be able to bring your ideas to life. 

##### Step 1 - Create Project

First you will need a folder in which Tina4 will be installed. In this example the folder is named tina4-php. Make your project directory by running this command in your terminal:

```shell script
mkdir your_project_folder
```
   
<div align="center" alt="Create Directory">
    <img src="images/directory2.png">
</div>

Once your project folder has been created, you will need to direct the command line to go into the folder you just created. In this example the folder is named "tina4-php".
For more information on how to navigate directories with command line, please visit How To Geek by clicking this link: [How To Geek](https://www.howtogeek.com/659411/how-to-change-directories-in-command-prompt-on-windows-10/).
Ensure your command terminal is pointed to the right directory by running this command in your terminal:
    
```shell script
cd your_project_folder
```
      
<div align="center" alt="CMD Directory">
    <img src="images/directory.png">
</div>

##### Step 2 - Create index.php file 

Once you've created the project folder and went into it via the command line, you will need to create an "index.php" file which has the Tina4 methods and functions in it. Run the following command in your command terminal to create the required "index.php" file and contents:

###### For Windows 

```php
echo ^<?php require_once "vendor/autoload.php"; echo new \Tina4\Tina4Php(); ^ > index.php
```

<div align="center" alt="Create index file for Windows">
    <img src="images/indexfile.png">
</div>

###### For Linux & Mac 

```php
echo '<?php require "vendor/autoload.php"; echo new \Tina4\Tina4Php();' > index.php
```
    
<div align="center" alt="Create index file for Linux & Mac">
    <img src="images/indexfile1.png">
</div>

A PHP file will be created in the project folder you made and will look like this... 

<div align="center" alt="Created index file">
    <img src="images/indexfile2.png">
</div>

##### Step 3 - Install Tina4 

Great, now that the "index.php" file has been created, we will install all the Tina4 libraries and dependencies in the project folder. Run "composer require andrevanzuydam/tina4php" in your IDE terminal or command line then Tina4 will start installing and create all the dependencies between your libraries and components. The terminal will inform you once the installation is complete. 

<div align="center" alt="Composer Command">
    <img src="images/directory1.png">
</div>

Your project folder will now look like this... 

<div align="center" alt="Folder">
    <img src="images/folder.png">
</div>

##### Step 4 - Create .env file

Almost there... Now you must create an ".env" file in your project folder. This file will be used to setup constants in your project needed for Tina4. Run "echo .>.env" in your IDE terminal or command line.

```php
echo .>.env
```
    
<div align="center" alt="Env Command">
    <img src="images/envfile.png">
</div> 

Your project folder will now look like this... 

<div align="center" alt="Folder">
    <img src="images/folder1.png">
</div>

##### Step 5 - Confirm installation

Now that we created and installed everything we need to run Tina4, we can test to check if it was successful. Spin up a webserver by running "php -S localhost:7145 index.php" in your IDE terminal or command line.

```php
php -S localhost:7145 index.php
```

<div align="center" alt="Spin up WebServer">
    <img src="images/webserver.png">
</div>

Once you ran the command, go to your browser and type "localhost:7145" in your URL address bar and hit enter (or click the address in the terminal). 

If the Tina4 404 error page loads, that means everything is working perfectly...

<div align="center" alt="Error">
    <img src="images/tinaerror.png">
</div>

## Conclusion

aaaaaaaaaand Voila! Tina4 is now installed, so you have everything you need to start bringing your ideas to life.   

Continue to [recommendations](/recommendations/) or go back to [Tina4 - This is Not Another Framework](/).

<div align="center" alt="Tina4">
    <img src="images/ms-icon-310x310.png">
</div>