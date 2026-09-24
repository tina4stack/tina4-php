<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


use PHPUnit\Framework\TestCase;

class AutoCrudFlagTest extends TestCase
{
    public function testAutoCrudFlagDefaultsFalse(): void
    {
        // Create a test model without autoCrud
        $model = new class extends \Tina4\ORM {
            public string $tableName = 'test_no_crud';
            public string $primaryKey = 'id';
        };
        $this->assertFalse($model->autoCrud);
    }

    public function testAutoCrudFlagCanBeSetTrue(): void
    {
        $model = new class extends \Tina4\ORM {
            public string $tableName = 'test_crud';
            public string $primaryKey = 'id';
            public bool $autoCrud = true;
        };
        $this->assertTrue($model->autoCrud);
    }
}
