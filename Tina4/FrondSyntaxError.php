<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4;

/**
 * Raised when a Frond template is malformed and cannot be rendered safely
 * (ADR-0104) — an unclosed `{% block %}` or a cut-short `{% endblock %` whose
 * closing `%}` is missing. Failing loud surfaces the real cause to a developer
 * (or an AI agent) instead of a page served 200 with its body quietly dropped.
 *
 * Extends \RuntimeException so existing `catch (\RuntimeException)` /
 * `catch (\Throwable)` handlers around template rendering keep catching it.
 */
class FrondSyntaxError extends \RuntimeException
{
}
