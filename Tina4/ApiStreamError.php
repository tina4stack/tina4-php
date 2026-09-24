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
 * Base error raised by the streaming primitives on Api (streamBytes /
 * streamLines / streamSse). All streaming-transport failures inherit from
 * this class so a caller can catch the whole family with one `catch`.
 *
 * The AI client translates these to AITimeoutError / AIHTTPError for its
 * pre-stream retry policy; the streaming primitive itself only reports
 * transport-level facts, never provider semantics.
 */
class ApiStreamError extends \RuntimeException
{
}
