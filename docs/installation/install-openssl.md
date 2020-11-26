<!--
// Tina4 : This Is Not Another Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andre@codeinfinity.co.za
-->
# Install OpenSSL

## Introduction
 
A toolkit used for Security Layer protocols between a computer network and Internet servers. Follow these instructions to install OpenSSL on your system. 

### For Windows

#### Step 1 - Download & Install

Head to the Shining Light Productions page and download the current stable version for your Operating System. In this case we are using a x64bit Windows. 

Here's the link : [Shining Light Productions](https://slproweb.com/products/Win32OpenSSL.html) 

<div align="center" alt="Installation OpenSSL">
 <img src="images/openssl.png">
</div>

After the download is complete, run the installation file and follow the prompts carefully. The installation window will inform you once the installation is complete. 

#### Step 2 - Edit environment path variable 

After you have installed OpenSSL, you must add the OpenSSL bin folder in your systems environment variable table.

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
Click on Browse, locate the "bin" folder directory where you installed OpenSSL (e.g. C:\Program Files\OpenSSL-Win64\bin). 
Click OK and then click Ok again to close the "Edit environment variable" window. 
```

<div align="center" alt="Add Path Environment Variable 3">
 <img src="images/openssl1.png">
</div> 

#### Step 3 - Confirm installation        

Complete the installation by restarting your system so that changes may take effect.After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "openssl version" and pushing Enter. This will display the OpenSSL version if installed correctly. If you receive an error, the installation was unsuccessful. In this case, uninstall OpenSSL and follow the steps again.

<div align="center" alt="Confirm Successful OpenSSL Installation">
 <img src="images/openssl2.png">
</div>

### For Linux/Unix/macOS

#### Step 1 - Download & Install

Head to the OpenSSL website and download the Light v1.1.1 version.

Here's the link: [OpenSSL](https://www.openssl.org/source/)

<div align="center" alt="OpenSSL Website">
 <img src="images/openssl3.png">
</div>

Run the installer and follow the instructions in the links below to complete the installation process:

For Mac:
Visit [franz.com](https://franz.com/support/openssl-mac.lhtml) || [stackoverflow.com](https://stackoverflow.com/questions/15185661/update-openssl-on-os-x-with-homebrew)

For Unix:
Visit [cloudwafer.com](https://cloudwafer.com/blog/installing-openssl-on-ubuntu-16-04-18-04/) || [howtoforge.com](https://www.howtoforge.com/tutorial/how-to-install-openssl-from-source-on-linux/)

#### Step 3 - Confirm installation        

Complete the installation by restarting your system so that changes may take effect.After this you can confirm if the installation was a success by opening your command terminal (e.g Command Prompt or terminal in your IDE tool) and typing in "openssl version" and pushing Enter. This will display the OpenSSL version if installed correctly. If you receive an error, the installation was unsuccessful. In this case, uninstall OpenSSL and follow the steps again.

## Conclusion

You installed OpenSSL and now have all the requirements to make the magic of Tina4 happen. 

Continue to [install ide tool](/installation/install-ide.md) or go back to [install composer](/installation/install-composer.md).