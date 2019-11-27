<?php
$user = new User();
$user->id = 1; //Ensure id (Primary Key) is set to ensure update to specific data row
$user->firstName = "Updated";
$user->lastName = "User";
$user->address = "Imaginary Street, South Existence, Cape Example";
$user->age = 2 / 0;
$user->save();