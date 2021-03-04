<?php


class Customer extends \Tina4\ORM
{
    public $primaryKey = "id";
    public $id;
    public $name;

    //Primary key id maps to customerId on Address table
    public $hasMany = [["Address" => "customerId"]];
}