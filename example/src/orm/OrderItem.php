<?php

class OrderItem extends \Tina4\ORM
{
    public string $tableName = "order_items";
    public string $primaryKey = "id";
    public array $foreignKeys = [
        'order_id' => 'Order',
        'product_id' => 'Product',
    ];

    public $id;
    public $orderId;
    public $productId;
    public $quantity;
    public $unitPrice;
}
