<!--
// Tina4 : This Is Not Another Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andre@codeinfinity.co.za
-->
# Install PHP

## Introduction

PHP is an Open Source general-purpose scripting language used in software/web development. You need to have PHP7.1 or greater installed on the command line. Follow these instructions to install PHP on your system. 

### Step 1  - Download PHP

Head to the PHP page and download the current stable version for your Operating System. 

Here's the link : [PHP](https://www.php.net/downloads.php)

<div align="center" alt="PHP Website">
    <img src="images/phppage.png">
</div>
        
### Step 2 - Copy and configure PHP 

Unpack/Extract the zip file or content into a file named "php" on the main drive on your system. 

```
eg. C:/php
```

Once the files have been extracted, you will need to go into the folder and edit the configuration settings file. 

Configure "php.ini" file: 

```
Go to the folder where you extracted PHP. 
Delete file named "php.ini". 
Rename file named "php.ini-development" to "php.ini".
Open php.ini file by opening it with Notepad or IDE tool 
```

<div align="center" alt="Configure php.ini file 1">
    <img src="images/config1.png">
</div>

```
Find the following extensions and remove the semicolon infront of the word extension:
    ;extension_dir = "ext"
    ;extension=curl
    ;extension=fileinfo
    ;extension=gd2
    ;extension=intl
    ;extension=mbstring
    ;extension=openssl
    ;extension=pdo_mysql
    ;extension=shmop
    ;extension=soap
    ;extension=sqlite3
    ;extension=tidy
    ;extension=xmlrpc
    ;extension=xsl
Press Ctrl+S or save changes you just made to "php.ini" file.
```
 
        
<div align="center" alt="Configure php.ini file 2">
    <img src="images/config2.png">
</div>

### Step 3 - Edit environment path variable

After you have made changes to the configuration file, you must add the PHP folder in your systems environment variable table.

Add path environment variable:
    
```
Open Control Panel
Go to System
Go to Advanced system settings
```    
        
<div align="center" alt="Add Path Environment Variable 1">
    <img src="images/enviro1.png">
</div>

```
Go to Environment variable 
Look for "Path" in the "Variable" column and click Edit.
```
       
<div align="center" alt="Add Path Environment Variable 2">
    <img src="images/enviro2.png">
</div>
        
```
Click on Browse, locate the PHP folder you uxtracted. 
Click OK and then click Ok again to close the "Edit environment variable" window.
```
        
<div align="center" alt="Add Path Environment Variable 3">
    <img src="images/enviro3.png">
</div>

### Step 4 - Confirm installation 
 
Complete the installation by restarting your system so that changes may take effect. After this you can confirm if the installation successful by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -v" and pushing Enter. The PHP version and details will appear in the terminal.    

<div align="center" alt="Successful Installation Confirmation">
    <img src="images/confirm.png">
</div>

## Conclusion

Great, you now have PHP 7.1 or higher installed on your system. 


Continue to [install composer](/installation/install-composer.md) or go back to [recommendations](/recommendations/).