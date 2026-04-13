<?php

class Category extends \Tina4\ORM
{
    public string $tableName = "categories";
    public string $primaryKey = "id";

    public $id;
    public $name;
    public $slug;
    public $description;
}
