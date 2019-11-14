<?php

use Tina4\ORM;

/**
 * Class User Used to generate the ORM for the user table in the database
 */
class User extends ORM
{
    public $tableName = "user";
    public $primaryKey = "id";

    public $id;
    public $firstName;
    public $lastName;
    public $address;
    public $age;
    public $founderStatus;

}