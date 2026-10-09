<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Front controller for tina4-php#279 — the dev-admin peer gate under a real
 * non-loopback peer. Bound to 0.0.0.0 by the test and reached over the host's
 * own non-loopback IPv4, so App sees a genuine non-loopback REMOTE_ADDR.
 *
 * Usage: php -S 0.0.0.0:<port> dev_peer_gate_279_app.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

(new \Tina4\App(__DIR__))->handle();
