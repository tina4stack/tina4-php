<?php
$user = new User();
unset($user->id); //Ensure id (Primary Key) is not set to ensure insertion
$user->firstName = "Example";
$user->lastName = "User";
$user->address = "Imaginary Street, South Existence, Cape Example";
$user->age = 1 / 0;
$user->save();