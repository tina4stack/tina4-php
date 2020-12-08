<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# Create a REST API

## Introduction

Follow the steps below if you need to create an API end point to define the interactions between multiple software. 

*Please Note* : After installing Tina4, you may delete the test.php file located inside the api folder. 

The REST end point is easily added, with an anonymous method to handle the request, the anonymous method should have the response variable.
The request exposes more information which comes from the browser, in the case of parameters passed to it. You should always return the ```$response``` object!;

### Step 1 - Create API file & subdirectories
   
All your API's must be stored in the api directory. For this example we will make the files and subdirectories for "cars" and "characters". 

Create 2 directories inside the api folder and name it "cars" and "characters". Now create php files inside those subdirectories (eg. name the file cars and the other characters).

### Step 2 - Define routes

Now that the API files has been created, we must define our Routes. There are various methods which can be used.  

#### Get method

In the cars.php file you created inside the cars subdirectory, add the following to the script:

```php
<?php

\Tina4\Get::add("/api/cars", function (\Tina4\Response $response) {

    $cars = ["Rolls Royce", "Aston Martin", "Bugatti"];

return $response ($cars, HTTP_OK, APPLICATION_JSON);
});
```

#### Post Method

```php
<?php

\Tina4\Post::add("/api/characters", function (\Tina4\Response $response) {
    $characters = ["Clancy Gilroy", "Peter Griffin", "Broden Kelly"];
return $response ($characters, HTTP_OK, APPLICATION_JSON);
});
```

#### Other Methods

```php
<?php


//Other methods you can test
\Tina4\Post::add(...);

\Tina4\Patch::add(...);

\Tina4\Put::add(...);

\Tina4\Delete::add(...);

//You guessed it - It takes every method - GET, POST, DELETE, PUT, PATCH, OPTIONS
\Tina4\Any::add(...);
```

Ensure that the paths are correct: 

<div align="center" alt="API Routes">
    <img src="images/api.png">
</div>


### Step 3 - Annotate API

Now we will need to annotate the end point we created. This will be a description of the end point which will be used in Swagger. Its as easy as adding the following lines above the end point you created:

```php
<?php

/**
* @description My first API
* @summary Returns list of cars
* @tags Cars
*/
```

Your script should look like this: 

<div align="center" alt="API Routes + Annotation">
    <img src="images/api1.png">
</div>

### Step 4 - Test API 

Now we can test to check if our API is working. Spin up a webserver by running "php -S localhost:7145 index.php" in your IDE terminal or command line.
                                                
```php
php -S localhost:7145 index.php
```

<div align="center" alt="Spin up WebServer">
    <img src="images/webserver.png">
</div>

Now that the webserver is running, add "/swagger" to the URL:

<div align="center" alt="Swagger">
    <img src="images/api2.png">
</div>

A Swagger page will load and you will see the following which will include the data and annotations within the script created: 

<div align="center" alt="Swagger Page">
    <img src="images/api3.png">
</div>

Next click on the API and then click on "Try It Out" button...

<div align="center" alt="Swagger Page">
    <img src="images/api4.png">
</div>
 
Thereafter click on the execute button and you will see the following responses in the browser...  

<div align="center" alt="Swagger Page">
    <img src="images/api5.png">
</div>

<div align="center" alt="Swagger Page">
    <img src="images/api6.png">
</div>

## Conclusion

You just created a basic API using Tina4. You are on your way to becoming a serious Code Ninja or Kunoichi. Please reach out to the developers if you have any questions or suggestions. 

For more information on API's please look at [FreeCodeCamp](https://www.freecodecamp.org/news/what-is-an-api-in-english-please-b880a3214a82/) or [How To Geek](https://www.howtogeek.com/343877/what-is-an-api/).

Please checkout the Tina4 Basic API video tutorial:

<iframe width="100%" height="600px" src="https://www.youtube.com/embed/LP5hVFh2lDQ" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>

Continue to [secure API](/tutorials/secureapi.md) or go back to [create a custom webpage - landing page](/tutorials/customwebsite.md).