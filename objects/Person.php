<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:54
 */

class Person extends \Tina4\ORM
{
    public $id; //id
    public $firstName; //first_name
    public $lastName; //last_name
    public $email; //email

    function hasMany () {
        //[ "table" => ["foreignKey" => "primaryKey"] ]
        return ["ContactInfo" => ["personId" => "id"]]; //select * from contactInfo where personId = {$this->id}
    }
}
