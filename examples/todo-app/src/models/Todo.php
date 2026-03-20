<?php

class Todo extends \Tina4\ORM
{
    public $tableName = "todo";
    public $primaryKey = "id";

    public $id;
    public $title;
    public $completed = 0;
    public $createdAt;
}
