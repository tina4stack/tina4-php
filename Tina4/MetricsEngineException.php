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
 * The native metrics engine could not produce a payload.
 *
 * Thrown instead of falling back to a second implementation: two engines is
 * exactly the condition that made the four frameworks' numbers incomparable, so
 * a missing, failing or stale `tina4` CLI fails loudly and names the fix rather
 * than quietly serving different arithmetic.
 */
class MetricsEngineException extends \RuntimeException
{
}
