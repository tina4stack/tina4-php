### Tina4 Routes
Simply create a PHP file of any name within this folder and create a router
```php

   \Tina4\Get::add("/test/route", function(\Tina4\Response $response){
      //Call some classes or helpers
      return $reponse("OK");
   }); 
```