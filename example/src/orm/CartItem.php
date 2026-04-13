<?php

class CartItem extends \Tina4\ORM
{
    public string $tableName = "cart_items";
    public string $primaryKey = "id";
    public array $foreignKeys = ['product_id' => 'Product'];

    public $id;
    public $sessionId;
    public $productId;
    public $quantity;
}
