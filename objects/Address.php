<?php


class Address extends \Tina4\ORM
{
    public $id;
    public $address;
    public $customerId;

    //Link up customerId => Customer object
    public $hasOne = [["Customer" => "customerId"]];
}