<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# How to make a webpage - landing page

## Introduction

A website is made up of web pages, which is a collection of information and relevant content which is displayed to a user on a web browser. Tina4 uses Twig template engine as it is fast, flexible and secure.

Go to the [Twig documentation](https://twig.symfony.com/doc/3.x/) page if you would like to familiarize yourself with Twig. It is important to note that when developing, it is recommended to break down your project into small components. This is done for flexibility for any changes required in future. 

After you have installed [Tina4](/installation/install-tina4.md), the configuration for Twig is passed by the Tina4php function running on the index file. 

Tina4 uses a base file located in the assets directory, which is used to render your landing page. This then extends to the template folder where templates are included. 
 
All your web pages must be stored in the src/templates directory.

<div align="center" alt="Web page location">
  <img src="images/website.png">
</div>

As an example we will be making a basic landing page with a navigation bar, body and footer.

Follow the instructions below to create a web page - landing page ...  

### Step 1 - Create index file 

In your project folder, you will need to create an index.twig file in the src / templates directory . We will extend this to the "base.twig" file and input all our webpage content.  

You can create this file in your IDE tool by going to the templates directory and right clicking on the folder, select new and then select File. 

<div align="center" alt="Create index Twig file">
  <img src="images/createindex.png">
</div>

Name the file index.twig 

<div align="center" alt="Name file">
  <img src="images/createindex1.png">
</div>

Please note that there are different ways you can create this file, but the main concern is that the name of the file must be "index" and the format ".twig".

### Step 2 - Extend index to base

Next, we will extend "index.twig" to "base.twig". This is done by adding the following line to "index.twig": 

```html
{% extends 'base.twig' %}
```
### Step 2 - Add title, icon & description

Now that "index.twig" is extended to "base.twig", you can set your page title, web icon and description. Below is an example of this information set in "index.twig":

```html
{% set title = 'My Test Site' %} 
{% set image = 'images/logo.png' %} 
{% set description = 'Tina4 helped me create this' %} 
```

### Step 3 - Add Content

We will now add the page content. Below is an example of content inserted into "index.twig":

```html
{% block navigation  %}
    <nav>
        <a href="Hello">Hello World</a>
        <h1>Welcome...</h1>
        <h2>This is my website</h2>
    </nav>
{% endblock %}

{% block content  %}
    <h3>Content</h3>
    <h3>Some More Content</h3>
{% endblock %}

{% block footer  %}
    <footer class="fixed bottom">
        <div class="col bg-dark text-white fixed-bottom text-center">
            <p class="foot">Copyright ©
                <script>document.write(new Date().getFullYear())</script>  <!-- gets current year -->
                My Test Site. All Rights Reserved
            </p>
        </div>
    </footer>
{% endblock %}
```
Your index.twig file will look like this:

<div align="center" alt="index.twig file">
  <img src="images/website2.png">
</div>

### Step 4 - Check out your page

We can now check to see how the page looks by spinning up a web server.

Spin up a webserver by running "php -S localhost:7145 index.php" in your IDE terminal or command line.

```php
php -S localhost:7145 index.php
```  
<div align="center" alt="Spin up WebServer">
  <img src="images/webserver.png">
</div>

Once you ran the command, go to your browser and type "localhost:7145" in your URL address bar and hit enter (or click the address in the terminal). 

You should see your website appear in the browser:

<div align="center" alt="First Tina4 Page">
  <img src="images/website3.png">
</div>

## Conclusion

You have just created a landing page for your website using Tina4. Please feel free to reach out to the developers if you have any questions or suggestions. 

Continue to [how to make a custom webpage - landing page](/tutorials/customwebsite.md) or go back to  [install ide tool](/installation/install-ide.md).