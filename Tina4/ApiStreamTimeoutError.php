<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


namespace Tina4;

/**
 * Raised when Api streaming exceeds TINA4_API_TIMEOUT (total) or
 * TINA4_API_CONNECT_TIMEOUT (connection establishment). Iterator ends and
 * the underlying socket is closed by the streaming finally block.
 */
class ApiStreamTimeoutError extends ApiStreamError
{
}
