<?php

class Product extends \Tina4\ORM
{
    public string $tableName = "products";
    public string $primaryKey = "id";
    public array $foreignKeys = ['category_id' => 'Category'];

    public $id;
    public $categoryId;
    public $name;
    public $slug;
    public $description;
    public $price;
    public $stock;
    public $imageUrl;
    public $isActive;
}
