<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Gallery: Error Overlay — deliberately crash to demo the debug overlay.
 *
 * In debug mode (TINA4_DEBUG=true), you will see:
 * - Exception type and message
 * - Stack trace with syntax-highlighted source code
 * - The exact line that caused the error (highlighted)
 * - Request details (method, path, headers)
 * - Environment info (framework version, PHP version)
 */

\Tina4\Router::get('/api/gallery/crash', function (\Tina4\Request $request, \Tina4\Response $response) {
    // Deliberately throw an exception to demo the error overlay
    throw new \RuntimeException('This is a demo crash from the Tina4 gallery — the error overlay should display this message with a full stack trace, source context, and request details.');
});
