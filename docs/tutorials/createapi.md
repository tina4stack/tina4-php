<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# Create an API

## Introduction

Follow the steps below if you need to create an API end point to define the interactions between multiple software. 

*Please Note* : After installing Tina4, you may delete the test.php file located inside the api folder. 

### Step 1 - Create API file & subdirectories
   
All your API's must be stored in the api directory. For this example we will make a list of cars. 

Create a directory inside the api folder and name it "cars". Now create a php file inside the "cars" subdirectory (eg. name the file cars).

### Step 2 - Define routes

Now that the API file has been created, we must define Routes. In the cars.php file you created inside the cars subdirectory, add the following to the script:

```php
<?php

\Tina4\Get::add("/api/cars", function (\Tina4\Response $response) {

    $cars = ["Rolls Royce", "Aston Martin", "Bugatti"];

return $response ($cars, HTTP_OK, APPLICATION_JSON);
});
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

Continue to [how do i](/tutorials/howdoi.md) or go back to [create a custom webpage - landing page](/tutorials/customwebsite.md).