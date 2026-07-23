<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * One MQTT 3.1.1 application message as delivered by the broker.
 *
 * Shaped like Tina4\Job (the Queue's unit of work): it carries the payload plus
 * the delivery metadata a consumer needs, and it knows how to acknowledge itself
 * back to the client it came from. Mirrors tina4_python.mqtt.MqttMessage and
 * Tina4::MqttMessage (Ruby).
 *
 * The two flags matter for correctness, not decoration:
 *
 *   retained  — the broker replayed the topic's last known value to us because
 *               we subscribed AFTER it was published. It is current state, not a
 *               fresh event.
 *   duplicate — the DUP flag. The broker is REDELIVERING a QoS 1 message it never
 *               saw acknowledged. QoS 1 is at-least-once, so a duplicate is
 *               guaranteed eventually; a consumer that treats a DUP delivery as a
 *               new sample double-counts energy or mileage. Key the ingest on
 *               (device_id, device_timestamp) and it is harmless.
 *
 * PHP strings are byte strings, so $payload holds the raw bytes verbatim and
 * text() returns them as-is (no decode step is needed the way Python/Ruby need one).
 */
final class MqttMessage
{
    /** @var bool Set once the QoS 1 delivery has been PUBACKed back to the broker. */
    private bool $acknowledgedFlag = false;

    /**
     * @param string   $topic     the topic the message was published to
     * @param string   $payload   the raw payload bytes (a PHP byte string)
     * @param int      $qos       delivery QoS (0 or 1)
     * @param bool     $retained  true when this is the topic's retained (last known) value
     * @param bool     $duplicate true when the broker set the DUP flag (a redelivery)
     * @param int|null $packetId  the packet identifier (present only for QoS > 0)
     * @param Mqtt|null $client   the client this arrived on, used to acknowledge()
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $payload,
        public readonly int $qos = 0,
        public readonly bool $retained = false,
        public readonly bool $duplicate = false,
        public readonly ?int $packetId = null,
        private readonly ?Mqtt $client = null
    ) {
    }

    /**
     * True when the broker replayed this as the topic's retained (last known) value.
     *
     * @return bool
     */
    public function isRetained(): bool
    {
        return $this->retained;
    }

    /**
     * True when the broker set the DUP flag — a REDELIVERY of a QoS 1 message we
     * never acknowledged, not a new sample.
     *
     * @return bool
     */
    public function isDuplicate(): bool
    {
        return $this->duplicate;
    }

    /**
     * PUBACK a QoS 1 delivery so the broker stops redelivering it.
     *
     * A QoS 0 message needs no acknowledgement, and a second call is a no-op, so
     * this is always safe to call once processing succeeded.
     *
     * @return bool true if a PUBACK was actually sent, false when there was nothing to ack
     */
    public function acknowledge(): bool
    {
        if ($this->acknowledgedFlag || $this->qos === 0 || $this->packetId === null || $this->client === null) {
            return false;
        }
        $this->client->acknowledge($this->packetId);
        $this->acknowledgedFlag = true;
        return true;
    }

    /**
     * True once this message has been acknowledged back to the broker.
     *
     * @return bool
     */
    public function isAcknowledged(): bool
    {
        return $this->acknowledgedFlag;
    }

    /**
     * The payload as text (for JSON / string payloads). PHP strings are already
     * byte strings, so this returns the payload verbatim.
     *
     * @return string
     */
    public function text(): string
    {
        return $this->payload;
    }

    /**
     * The message as a plain associative array.
     *
     * @return array{topic: string, payload: string, qos: int, retained: bool, duplicate: bool, packetId: int|null}
     */
    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'payload' => $this->payload,
            'qos' => $this->qos,
            'retained' => $this->retained,
            'duplicate' => $this->duplicate,
            'packetId' => $this->packetId,
        ];
    }

    /**
     * String form is the payload text (parity with Python __str__ / Ruby to_s).
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->payload;
    }
}
