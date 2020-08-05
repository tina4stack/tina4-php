# Recommendations

## Introduction
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

* Visual Studio Code
* PHPStorm

## Debugging

The most popular PHP debugging extension is [Xdebug](https://xdebug.org/docs/install) and we recommend that you get this installed as soon as you have your PHP up 
and running, there is a lot of documentation out there that will get you up and running quickly.

Most important is to enable remote debugging in your php.ini file 

```sh
[XDebug]
xdebug.remote_enable = 1
xdebug.remote_autostart = 1
```
The default port that debugging is enabled on for xdebug is 9000 and because we are not really remote there are some recommended ways to run Tina4 from
the commandline to make debugging work.

```sh
XDEBUG_CONFIG="remote_host=127.0.0.1" php -S localhost:7145 index.php
```
On windows you can put the following in the built in PHP web server environment variables
```sh
XDEBUG_CONFIG="remote_host=127.0.0.1"
```

