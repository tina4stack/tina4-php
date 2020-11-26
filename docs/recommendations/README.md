<!--
// Tina4 : This Is Not Another Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andre@codeinfinity.co.za
-->
# Recommendations
## What to consider ?

If you already have a good work flow up and running then you can skip this bit, 
however if you do not have an IDE which can do step by step debugging then I'd pause for a while and consider some of 
the thoughts here.

I'm not sure if you ever find yourself writing a whole bunch of echo statements in your code to "see" what's happening.
If you are taking a long time to get to the bottom of a problem you probably should be making use of a debugger in your
editor.  If your editor doesn't support debugging then you should probably consider using one that does.

## IDE vs Text Editor
An IDE is an editor which allows you to do debugging and version control and code refinement due to good hints and code completion.
A text editor is just that, something to edit text and it doesn't give any guidance as to whether one is making mistakes.

Two popular IDEs which support PHP debugging are:

* [Visual Studio Code](https://code.visualstudio.com/download)
* [PHPStorm](https://www.jetbrains.com/phpstorm/download)

The most popular PHP debugging extension is [Xdebug](https://xdebug.org/docs/install) and we recommend that you get this installed as soon as you have your PHP up 
and running.

## Install Xdebug

Follow these instructions to install Xdebug on your system.

### Step 1 - Download & Install
 
Head to the Xdebug page and download the corresponding version for PHP on your Operating System. For example, in this case the PHP version used is VC15 x64bit Thread Safe 7.4.1.
            
Here's the link: [Xdebug](https://xdebug.org/download)

<div align="center" alt="Xdebug Website">
    <img src="images/xdebug.png">
</div>

After the download is complete, extract/unpack or copy the file into the "ext" folder found in your PHP directory. (e.g. C:/php/ext) 
    
```
It is recommended you rename the file (eg. "php_xdebug-2.9.6-7.4-vc15-x86_64.dll" ) to "php_xdebug.dll".
```

<div align="center" alt="Installing Xdebug">
    <img src="images/xdebug1.png">
</div>

### Step 2 - Configure php.ini 

Once the file have been extracted, you will need to enable remote debugging by editing the configuration settings file. 

Configure "php.ini" file:

```
Go to your PHP folder and open "php.ini" with Notepad or your IDE tool. 
    *Please ensure that the "php_xdebug.dll" file location is correct* 
Add the following in the extensions directory list (HINT: its above Module Settings):
    [XDebug]
    zend_extension="C:\php\ext\php_xdebug.dll
    xdebug.remote_enable = 1
    xdebug.remote_autostart = 1
Press Ctrl+S or save changes you just made to "php.ini" file.
```

<div align="center" alt="Configure php.ini file for Xdebug">
    <img src="images/xdebug2.png">
</div>

### Step 3 - Confirm Installation
 
Complete the installation by restarting your system so that changes may take effect.After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -m" and pushing Enter. 
This will display all modules loaded in your PHP. At the bottom of the list you should see the "Zend Modules" heading and "Xdebug" will be listed underneath. 

<div align="center" alt="Confirm Successful Xdebug Installation">
    <img src="images/xdebug3.png">
</div>

## Debugging

The default port that debugging is enabled on for xdebug is 9000 and because we are not really remote there are some recommended ways to run Tina4 from
the commandline to make debugging work.

```php
XDEBUG_CONFIG="remote_host=127.0.0.1" php -S localhost:7145 index.php
```
On windows you can put the following in the built in PHP web server environment variables
```shell script
XDEBUG_CONFIG="remote_host=127.0.0.1"
```

Continue to [install php](/installation/install-php.md) or go back to [install tina4](/installation/install-tina4.md).
