<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


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
