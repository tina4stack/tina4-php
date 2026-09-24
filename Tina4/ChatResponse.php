<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


namespace Tina4;

final class ChatResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly array $usage,
        public readonly ?string $finish_reason,
        public readonly array $raw,
    ) {
    }
}
