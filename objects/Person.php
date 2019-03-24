<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:54
 */
use Tina4\Tina4Object;

class Person extends Tina4Object //must extend a Tina4Object to work with Swagger
{
    public $firstName;
    public $lastName;
    public $email;
}
