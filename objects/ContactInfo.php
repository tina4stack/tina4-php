<?php

class ContactInfo  extends \Tina4\ORM
{
    public $id; //id
    public $email;
    public $mobileNo;
    public $personId;

    function belongsTo () {
        return ["Person" => ["id" => "personId"]]; //select * from person where id = {$this->personId}
    }
}