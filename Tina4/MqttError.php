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
 * Any MQTT protocol or connection failure.
 *
 * Mirrors tina4_python.mqtt.MqttError and Tina4::MqttError (Ruby). Argument
 * errors (an empty url, an unsupported scheme, a refused QoS 2) raise
 * \InvalidArgumentException instead — a caller mistake, not a broker failure.
 */
class MqttError extends \RuntimeException
{
}
