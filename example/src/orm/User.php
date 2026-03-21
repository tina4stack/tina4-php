<?php

class User extends \Tina4\ORM
{
    public $tableName = "user";
    public $primaryKey = "id";

    public $id;
    public $firstName;
    public $lastName;
    public $email;
    public $createdAt;
}
