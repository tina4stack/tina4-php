<?php
/*
Copyright (c) 2026 Code Infinity
SPDX-License-Identifier: MPL-2.0
This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
*/

require dirname(__DIR__, 2) . '/vendor/autoload.php';
header('Content-Type: text/plain');
echo \Tina4\Request::fromGlobals()->url;
