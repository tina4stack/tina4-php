<?php

class Order extends \Tina4\ORM
{
    public string $tableName = "orders";
    public string $primaryKey = "id";
    public array $foreignKeys = ['customer_id' => 'Customer'];

    public $id;
    public $customerId;
    public $status;
    public $total;
    public $createdAt;
    public $updatedAt;
}
