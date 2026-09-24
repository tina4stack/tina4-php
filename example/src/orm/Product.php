<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


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
