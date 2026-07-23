<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The broker did not answer inside the timeout.
 *
 * Mirrors tina4_python.mqtt.MqttTimeoutError and Tina4::MqttTimeoutError (Ruby).
 */
class MqttTimeoutError extends MqttError
{
}
