<?php


class Address extends \Tina4\ORM
{
    public $id;
    public $name;
    public $street;
    public $city;
    public $state;
    public $zipCode;
    public $customerId;

    //Link up customerId => Customer object
    public $hasOne = [["Customer" => "customerId"]];
}