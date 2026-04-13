<?php

class Customer extends \Tina4\ORM
{
    public string $tableName = "customers";
    public string $primaryKey = "id";
    public bool $softDelete = true;

    public $id;
    public $name;
    public $email;
    public $passwordHash;
    public $role;
}
