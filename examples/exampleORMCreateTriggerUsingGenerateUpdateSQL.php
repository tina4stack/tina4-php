<?php
$user = new User();
unset($user->id); //Ensure id (Primary Key) is not set to ensure insertion
$user->firstName = "Example2";
$user->lastName = "User2";
$user->address = "Imaginary Street, South Existence, Cape Example";
$user->age = 2 / 0;
$user->save();

//After inserting a new row the object's id field is filled in; allowing for creation of triggers

if ($user->id < 10) {
    $user->founderStatus = 1;
    $user->save();
}