## Tina4 - This is Not Another Framework ##

Tina4 is a light weight routing and templating system which allows you to write websites and API applications very quickly.

**Features**

- Auto templating
- Auto inclusions & project structure
- Annotations for quick Swagger documentation & security

### Installing ###

- Install PHP7.1 >
- Install composer
- In your project folder terminal / console
    ```bash
    composer require andrevanzuydam/tina4php
    ```
- Create an index.php file in your folder and add this code
    ```bash
    <?php
    require "vendor/andrevanzuydam/tina4php/engine.php";
    ``` 
- Spin up a web server with PHP
    ```bash
    php -S localhost:8080 index.php
    ```
- Hit up http://localhost:8080 in your browser, you should see the 404 error

### Hello World - Web page ###

Tina4 has twig built in and it's own basic templating engine. 

### index.html ###

Follow these steps to creating a hello world index.html, this is the default file served if you hit up the site.
Once you have mastered these basics you should be able to create an simple static site.

#### Step 1 - Hello World ####
- Create a templates folder
- Define where your templates will exist, you will need to create the folders under your project folder.
  ```php
  <?php
  //Define some folders where we want templates
  define ("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
  require "vendor/andrevanzuydam/tina4php/engine.php";
  ```
- In your templates folder create a file index.html and insert the following code
    ```html
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1, user-scalable=yes">
    
        <title>Hello World!</title>
    </head>
    <body>
    <h1> Tina4 - Hello World! </h1>
    </body>
    </html>
    ```
- Hit up http://localhost:8080 and you should see the hello world message

#### Step 2 - Include a Snippet ####
- Create a snippets folder under the existing templates folder
- Create a file called footer.html in the snippets folder with this code
    ```html
    <footer>
      Hello world footer include!
    </footer>
    ```
- Modify the index.html file under templates to look like this
    ```html
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1, user-scalable=yes">
    
        <title>Hello World!</title>
    </head>
    <body>
    <h1> Tina4 - Hello World! </h1>
    {{include:snippets/footer}}
    </body>
    </html>
    ```
- Hit up http://localhost:8080 and you should see the footer included at the bottom of the file   

#### Advanced ####

##### Calling built in PHP #####
Make use of PHP inside the HTML with the following **{{call:[method],[param1,param2,...]}}**:

```html
  <!DOCTYPE html>
  <html lang="en">
  <head>
      <meta charset="UTF-8">
      <title>Test calling a PHP function</title>
  </head>
  <body>
  Substr of Testing {{call:substr?"Testing",1,2}}
  </body>
  </html>
```

##### Calling methods on a PHP Class #####

- Create a folder called app in your project folder
- Tell Tina4 about it by defining it
 ```php
  <?php
  //Define some folders where we want to have tina4 include automatically
  define("TINA4_INCLUDE_LOCATIONS"  , ["app","objects"]);
  require "vendor/andrevanzuydam/tina4php/engine.php";
  ```
- Make an Example.php file in the app folder with the following code
    ```php
    <?php
    
    class Example
    {
        function renderSomething ($text) {
            return "<h1>Example: {$text}</h1>";
        }
    }
    ```
- Add the following to your index.html file
    ```html
    {{Example:renderSomething?"Nice"}}
    ```
- Hit up http://localhost:8080, you should see the result from the renderSomething method.

### index.twig ###

Follow these steps to creating a hello world index.twig, this is the default file served if you hit up the site.
Once you have mastered these basics you should be able to create an simple static site using the twig templating engine.

#### Step 1 - Hello World ####
- Create a templates folder
- Define where your templates will exist, you will need to create the folders under your project folder.
  ```php
  <?php
  //Define some folders where we want templates
  define ("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
  require "vendor/andrevanzuydam/tina4php/engine.php";
  ```
- In your templates folder create a file index.twig and insert the following code
    ```html
    <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport"
                  content="width=device-width, initial-scale=1, user-scalable=yes">
        
            <title>Hello World!</title>
        </head>
        <body>
        <h1> Tina4 TWIG - Hello World! </h1>
        </body>
        </html>
    ```
- Hit up localhost:8080 and you should see the hello world message

#### Step 2 - Include a Snippet ####
- Create a snippets folder under the existing templates folder
- Create a file called footer.twig in the snippets folder with this code
    ```html
    <footer>
      Hello world footer twig include!
    </footer>
    ```
- Modify the index.twig file under templates to look like this
    ```html
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1, user-scalable=yes">
    
        <title>Hello World!</title>
    </head>
    <body>
    <h1> Tina4 TWIG - Hello World! </h1>
    {% include 'footer.twig' %}
    </body>
    </html>
    ```
- Hit up http://localhost:8080 and you should see the footer included at the bottom of the file   


### Hello World - API ###

There are a number of steps to making good API end points and the following steps will take you through the ways of getting this right.

#### Hello World ####

- Create an api folder to store your end point code
- Define the folder for Tina4 to use as an include path in your index.php
    ```php
    define("TINA4_INCLUDE_LOCATIONS"  , ["app","objects"]);
    ```

- Create a helloWorld.php file in the api folder, you can call the file any name and you can add as many files here as you want, the idea is to group your API end points logically.
- Add the following code to helloWorld.php
    ```php
    //Use Get::add, Post:add, Patch::add, Put::add, Delete:add
    Get::add("/hello-world",
      function ($response) {
          //response takes 3 params (text, http_code, content-type)
          return $response ("Hello World", 200);
      }
    );
    ```
- Hit up http://localhost:8080/hello-world

#### Hello World with inline params ####

- Add the following code to helloWorld.php
    ```php
    //Use Get::add, Post:add, Patch::add, Put::add, Delete:add
    Get::add("/hello-world/{testingString}",
        function ($testingString, $response) { //notice how the response variable moves to the end
            //response takes 3 params (text, http_code, content-type)
            return $response ("Hello World {$testingString}", 200);
        }
    );
    ```
- Hit up http://localhost:8080/hello-world/itworks

- Add the following code to helloWorld.php
    ```php
    //Use Get::add, Post:add, Patch::add, Put::add, Delete:add
    Get::add("/hello-world/{testingString}/{someMore}",
        function ($testingString, $someMore, $response) { //notice how the response variable moves to the end
            //response takes 3 params (text, http_code, content-type)
            return $response ("Hello World {$testingString} {$someMore}", 200);
        }
    );
    ```
- Hit up http://localhost:8080/hello-world/itworks/somemore

#### Hello World with annotations ####

End points can be annotated for documentation purposes, if you have worked with swagger you will probably find this process much simpler to use.

**Annotation Documentation reference**
```
 /**
 * Description of what the end point does for code purposes
 * @description Swagger description
 * @tags Header tag for grouping in Swagger
 * @summary Summary of what to expect
 * @example PHP Object - add this object to any of your TINA4_INCLUDE_LOCATIONS folders
 */
 ```
 
The following steps will establish the automated Swagger annotation

- Add the following code to one of your route files, modify the getSwagger method as required
    ```php
    //A swagger endpoint for annotating your API end points
    Get::add('/swagger/json.json', function($response) {
        return $response ( (new Routing())->getSwagger("Some Name for the Swagger","Some other description","1.0.0"));
    });

    ```
- Hit up http://localhost:8080/swagger/index, your should see a blank Swagger interface
- Annotate one of the previous examples or a new end point you have created
    ```php
    /**
     * Hello world get end point
     * @description Runs a Hello world test
     * @tags Hello World
     * @summary Get a hello world test
     */
    Get::add("/hello-world/{testingString}/{someMore}",
        function ($testingString, $someMore, $response) { //notice how the response variable moves to the end
            //response takes 3 params (text, http_code, content-type)
            return $response ("Hello World {$testingString} {$someMore}", 200, "html/text");
        }
    );

    ```
    **Notice the deliberate passback of the content-type for Swagger to work properly**
- Hit up http://localhost:8080/swagger/index and open up the newly annotated end point, you should be able to click try me and test the end point.
