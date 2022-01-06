<?php

class Person extends \Tina4\ORM
{
    public $primaryKey="id";
    public $genPrimaryKey = true;
    public $softDelete = true; //adds is_deleted & date_deleted to table, adds is_deleted = 0 to load statement
    /**
     * @var integer default 0 not null
     */
    public $id;
    /**
     * @var varchar(100)
     */
    public $firstName;
    /**
     * @var varchar(100)
     */
    public $lastName;
    /**
     * @var varchar(255)
     */
    public $email;
    /**
     * @var integer
     */
    public $age;
}