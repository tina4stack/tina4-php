<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


class Category extends \Tina4\ORM
{
    public string $tableName = "categories";
    public string $primaryKey = "id";

    public $id;
    public $name;
    public $slug;
    public $description;
}
