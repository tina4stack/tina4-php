<!--
// Tina4 : This Is Not Another Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andre@codeinfinity.co.za
-->

# Installation

To make the magic of Tina4 possible, there are various tools you need to install first. Below you will find a list of the tools you need and how to install them.

# Required

* IDE development tool
* PHP 7.1 or greater
* Xdebug
* Composer 
* OpenSSL 

## Install IDE tool
You must have a code editor or tool which you will use to develop your website and use to debug. 
There are various IDE tools you can use to write code for your software or website,such as PHPStorm or Visual Studio Code. Depending on your Operating Software, you will need to install the correct installer or files for your OS. Follow these instructions to install an IDE tool on your system.

##### Step 1 

We recommend using one these IDE tools. Choose your weapon :

[PHPStorm](https://www.jetbrains.com/phpstorm/download) OR [Visual Studio Code](https://code.visualstudio.com/download)

##### Step 2 

Choose the correct installation for your Operating System (eg. Windows, Mac or Linux) and download the installer or installation files
![alt text](../assets/images/idetools.png)

##### Step 3 
 
After the download is complete, run the installation file and follow the prompts carefully. The installation window will inform you once the installation is complete.

*PLEASE NOTE:* If you want to install the IDE tools via command line, the commands will be available on the download page. 

## Install PHP 

You need to have PHP7.1 or greater installed on the command line.Follow these instructions to install PHP on your system. 

##### Step 1  

Head to the PHP page and download the current stable version for your Operating System. 

Here's the link : [PHP](https://www.php.net/downloads.php)

![alt text](../assets/images/phppage.png)

##### Step 2 

Unpack/Extract the zip file or content into a file named "php" on the main drive on your system. 

        eg. C:/php
        
##### Step 3 

Once the files have been extracted, you will need to go into the folder and edit the configuration settings file. 

Configure "php.ini" file: 
 
        Go to the folder where you extracted PHP. 
        Delete file named "php.ini". 
        Rename file named "php.ini-development" to "php.ini".
        Open php.ini file by opening it with Notepad or IDE tool 
        
![alt text](../assets/images/config1.png)

            Find ';extension_dir = "ext"' and remove the semicolon infront of the word extension
            Find ";extension=curl" and remove the semicolon infront of the word extension
            Find ";extension=fileinfo" and remove the semicolon infront of the word extension
            Find ";extension=gd2" and ...
            Find ";extension=intl" and ...
            Find ";extension=mbstring" and ...
            Find ";extension=openssl" and ...
            Find ";extension=pdo_mysql" and ...
            Find ";extension=shmop" and ...
            Find ";extension=soap" and ...
            Find ";extension=sqlite3" and ...
            Find ";extension=tidy" and ...
            Find ";extension=xmlrpc" and ...
            Find ";extension=xsl" and ...
        Press Ctrl+S or save changes you just made to "php.ini" file. 
        
![alt text](../assets/images/config2.png)

##### Step 4 

After you have made changes to the configuration file, you must add the PHP folder in your systems environment variable table.

Add path environment variable:
    
        Open Control Panel
        Go to System
        Go to Advanced system settings
        
![alt text](../assets/images/enviro1.png)

        Go to Environment variable 
        Look for "Path" in the "Variable" column and click Edit.
       
![alt text](../assets/images/enviro2.png)        
        
        Click on Browse, locate the PHP folder you uxtracted. 
        Click OK and then click Ok again to close the "Edit environment variable" window.
        
![alt text](../assets/images/enviro3.png)   

##### Step 5 
 
Complete the installation by restarting your system so that changes may take effect. After this you can confirm if the installation successful by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -v" and pushing Enter. The PHP version and details will appear in the terminal.    

![alt text](../assets/images/confirm.png)  

## Install Xdebug

Follow these instructions to install Xdebug on your system.

* Step 1 > Navigate to the [Xdebug](https://xdebug.org/download) website
            
    Download the correct version for your  Operating System (e.g. Windows 10 32bit or 64bit, Mac or Linux). Download the installer or content. 
    
* Step 2 > After the download is complete, copy the file into the "ext" folder found in your PHP directory. (e.g. C:/php/ext) 
    
    It is recommended you rename the file (eg. "php_xdebug-2.9.6-7.4-vc15-x86_64.dll" ) to "php_xdebug.dll".

* Step 3 > Configure "php.ini" file:

        Go to your PHP folder and open "php.ini" with Notepad or your IDE tool. 
        Add the following line "zend_extension="E:\php\ext\php_xdebug.dll" in the extensions directory list (HINT: its above Module Settings) . 
            *Please ensure that the "php_xdebug.dll" file location is correct* 
        Add the following line "xdebug.remote_enable = 1" in the extensions directory list.
        Add the following line "xdebug.remote_autostart = 1 " in the extensions directory list.
        Press Ctrl+S or save changes you just made to "php.ini" file.
           
To ensure your changes take effect you may restart your device or IDE tool.After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "php -m" and pushing Enter. 
This will display all modules loaded in your PHP. At the bottom of the list you should see the "Zend Modules" heading and "Xdebug" will be listed underneath. 
            
## Install Composer

Follow these instructions to install Composer on your system. 

#### For Windows

* Step 1 > Download the latest stable version of [Composer](https://getcomposer.org/Composer-Setup.exe)
     
* Step 3 > After the download is complete, run the installation file and follow the prompts

The installation window will inform you once the installation is complete. 

To ensure your changes take effect you may restart your device or IDE tool. After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "composer -V" and pushing Enter. 

The Composer version and details will appear in the terminal.

*PLEASE NOTE:* If you want to install Composer via command line, the commands will be available on the download page.

#### For Linux/Unix/macOS

* Step 1 > Download the latest stable version of [Composer](https://getcomposer.org/installer)

* Step 2 > There are two install options for Composer namely: Locally (as part of your project) and Globally (system wide executable). 
Go to the [Composer installer](https://getcomposer.org/installer) page to view instructions on how to install Composer for your OS

To ensure your changes take effect you may restart your device or IDE tool. After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "composer -V" and pushing Enter. 

The Composer version and details will appear in the terminal.

## Install OpenSSL

Follow these instructions to install OpenSSL on your system. 

##### For Windows

* Step 1 > Navigate to [Shining Light Productions](https://slproweb.com/products/Win32OpenSSL.html) 

* Step 2 > Download version v1.1.1 Light. Download the correct version for your Operating System (e.g. Windows 10 32bit or 64bit)
     
* Step 3 > After the download is complete, run the installation file and follow the prompts. The installation window will inform you once the installation is complete

* Step 4 > Change path environment variable
    
        Open Control Panel
        Go to System
        Go to Advanced system settings
        Go to Environment variable 
        Look for "Path" in the "Variable" column and click Edit.
        Click on Browse, locate the "bin" folder directory where you installed OpenSSL (e.g. C:\Program Files\OpenSSL-Win64\bin). 
        Click OK and then click Ok again to close the "Edit environment variable" window.  

To ensure your changes take effect you may restart your device or IDE tool. After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "openssl version" and pushing Enter. 
This will display the OpenSSL version if installed correctly. If you receive an error, the installation was unsuccessful. In this case, uninstall OpenSSL and follow the steps again.

##### For Linux/Unix/macOS

* Step 1 > Navigate to the [OpenSSL](https://www.openssl.org/source/) website

* Step 2 > Download version v1.1.1 Light

* Step 3 > Run the installer and follow the instructions in the links below to complete the installation process:

    For Mac:
    Visit [franz.com](https://franz.com/support/openssl-mac.lhtml) || [stackoverflow.com](https://stackoverflow.com/questions/15185661/update-openssl-on-os-x-with-homebrew)
    
    For Unix:
    Visit [cloudwafer.com](https://cloudwafer.com/blog/installing-openssl-on-ubuntu-16-04-18-04/) || [howtoforge.com](https://www.howtoforge.com/tutorial/how-to-install-openssl-from-source-on-linux/)

 
## Install Tina4
Once the prerequisites have been installed you can create a project folder and proceed to run the Tina4 installation.

Running this in your IDE terminal or command line then install Tina4 will start installing and create all the dependencies between your libraries and components.

##### If you have project folder

* Step 1 > Ensure your command terminal is pointed to the right directory by running this command in your terminal:
    
      cd your_project_folder

* Step 2 > Run "composer require andrevanzuydam/tina4php" in your command terminal

##### If you dont have project folder

* Step 1 > Make your project directory by running this command in your terminal:

      mkdir tina4example

* Step 2 > Ensure your command terminal is pointed to the right directory by running this command in your terminal:
       
       cd your_project_folder

* Step 3 > Run "composer require andrevanzuydam/tina4php" in your command terminal


aaaaaaaaaand Voila! Tina4 is installed and now you have everything you need to start bringing your ideas to life. 

![alt text](../icons/ms-icon-310x310.png)
