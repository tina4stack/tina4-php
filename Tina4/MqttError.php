<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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
