## Setting up a Development Environment (IDE)

So if you read the PHP vs Tina4 PHP article, you will realise I am still quite new to all this. I have finally figured out that the **Postman**, was actually delivering the correct **package** from the **Composer** to **Tina**. There was actually nothing wrong with her **Windows**, just had to **push** and **pull** a bit, some **Git** installed it badly. 

Previously most of my development was in a grown up text editor, so an Integrated Development Environment (IDE) was a new concept to me. If like me, you are a little lost, and IDE focuses on the 'integrated'. A place where you can see all your development files for a project, which automatically looks after version control, sharing development with others, debugging, a terminal window for command line executions, code autocomplete, and code analysis. As I said I am new to this, so I am sure there are some other gems that I am missing, but have seen enough to understand this is really worth it. It also has allows me to write this documentation in **Markdown**. 

It is important to note that there are a number of IDE Software packages, different places you can host your Git repositories and different PHP debugging engines. This choice is absolutely no endorsement of the software, it was merely what was suggested to me. So this is how to setup **PhpStorm** on **Windows**, with **XDebug** and **Git** integration. 

### Installation

Firstly head over to JetBrains to download a [30 day trial of PhpStorm](https://www.jetbrains.com/phpstorm/download/#section=windows). Just follow the install prompts like normal software. Easy enough.

Git is a two part installation. Installing the Git software on your machine and then finding a place to host your code repositories. [Download the 64bit Windows version of Git](https://git-scm.com/download/win) to install the software. Then head over to GitHub and [sign up a free account with them](https://github.com/), which should be enough to get developing and hosting your repositories.

[Download the Xdebug Windows compiled binary](https://xdebug.org/download) and copy the .dll file into your php/ext folder and modify your php.ini file by including the following text.

```angular2
[xdebug]
zend_extension = c:\php\ext\php_xdebug-2.9.3-7.4-vc15-x86_64.dll
xdebug.remote_enable=1
xdebug.remote_port="9000"
```

Save the php.ini file, restart PhpStorm, and Xdebug is running. To check it is working type ```php -v``` in the terminal command line. You should get something like this back, showing the Xdebug library is installed.

```
PHP 7.4.3 (cli) (built: Feb 18 2020 17:29:46) ( ZTS Visual C++ 2017 x64 )
   Copyright (c) The PHP Group
   Zend Engine v3.4.0, Copyright (c) Zend Technologies
       with Xdebug v2.9.3, Copyright (c) 2002-2020, by Derick Rethans
```

### Configuration

So that is all the software installed. So when starting your first project you will need to integrate both Git and Xdebug into your project. Luckily this can all be found in the PhpStorm menu system. 

File>New Project>Select a Folder> will create a new project in the folder of your choice.

VCS>Import into Version Control>Create Git Repository>Select folder for respository> I always use the root of the folder. A version control Tab will now be available at the bottom of your PhpStorm window. 

File>Settings>Languages and Frameworks>PHP>Choose the Language version of your PHP> This might be project specific, but best to use the correct version of PHP that you are running.

File>Settings>Languages and Frameworks>PHP>Choose the CLI Interpreter> For the first time that you are doing this, there are no recognised interpreters. Click the . . . next to the dropdown. Click + to add a new interpreter, and hopefully it has already found the php engine ```c:\php\php.exe``` presuming that is where you installed your PHP.Select all the OK's and you should be good to go.

You use the Listen / Don't Listen button that looks like a phone to turn debugging on or off when running code.

Your IDE is now setup for developing, using Git Version control and with a debugger available to assist with tracing your coding errors.

### Optimization

Each time you push a commit to your GitHub repository you might not want to send all of it. For example if you are using Tina4, you do not want to commit that to your repository, as you should not be working on that code, and it will update from time to time. This will apply to any other libraries that you have included. 
Right click the folder you want to exclude from Git pushes>Git>Add to .gitignore> It might create the .gitignore file if it does not exist in your project. Any folders greyed out in PhpStorm will not committed to your repository. 

Setting the CLI interpreter should probably be set as a default to get this working on each new project that you create. Again using the menus
File>Other Settings>Settings for new projects>Languages and Frameworks>PHP> and set it up as above. The difference is that for each new project this will now be set as default.

