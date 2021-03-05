<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# Secure API

## Introduction

You might want to secure your REST API to avoid exposing data and data breaches. After you have created your REST API, you can easliy secure it by adding @secure in your annotation.

### Step 1 - Create API

Please see [Create a REST API](/tutorials/createapi.md "Create a REST API").

### Step 2 - Annotate API

Add the @secure method above the end point you created:

```php
<?php

/**
* @description My first API
* @summary Returns list of cars
* @tags Cars
* @secure
*/
```
### Step 3 - Test API

Now we can test to check if our API is secure. Spin up a webserver by running "php -S localhost:7145 index.php" in your IDE terminal or command line.
                                                
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
    <img src="images/api7.png">
</div>


## Conclusion

You have just secured your API to keep private data safe and to avoid data breaches.  
