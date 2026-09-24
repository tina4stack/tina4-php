<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MongoBson — the minimal BSON codec behind the zero-dependency MongoDB wire
 * clients (Session\MongoSessionHandler, Cache\MongoBackend).
 *
 * It was two private copies, one per client; a decoder bug then had to be
 * found and fixed twice. One codec, two callers.
 *
 * Encodes: null, bool, int (int32/int64), float, string, list (array),
 * associative array (document). Anything else is encoded as its string form.
 */

namespace Tina4;

final class MongoBson
{
    public static function encode(array $document): string
    {
        $body = '';
        foreach ($document as $key => $value) {
            $body .= self::encodeElement((string)$key, $value);
        }
        $body .= "\x00";
        return pack('V', strlen($body) + 4) . $body;
    }

    public static function decode(string $data): array
    {
        $position = 0;
        return self::decodeDocument($data, $position);
    }

    private static function encodeElement(string $key, mixed $value): string
    {
        $ckey = $key . "\x00";
        if ($value === null) {
            return "\x0A" . $ckey;
        }
        if (is_bool($value)) {
            return "\x08" . $ckey . ($value ? "\x01" : "\x00");
        }
        if (is_int($value)) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return "\x10" . $ckey . pack('V', $value);
            }
            return "\x12" . $ckey . pack('P', $value);
        }
        if (is_float($value)) {
            return "\x01" . $ckey . pack('e', $value);
        }
        if (is_string($value)) {
            return "\x02" . $ckey . pack('V', strlen($value) + 1) . $value . "\x00";
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $indexed = [];
                foreach ($value as $index => $item) {
                    $indexed[(string)$index] = $item;
                }
                return "\x04" . $ckey . self::encode($indexed);
            }
            return "\x03" . $ckey . self::encode($value);
        }
        $string = (string)$value;
        return "\x02" . $ckey . pack('V', strlen($string) + 1) . $string . "\x00";
    }

    private static function decodeDocument(string $data, int &$position): array
    {
        $documentLength = unpack('V', substr($data, $position, 4))[1];
        $position += 4;
        $end = $position + $documentLength - 5; // -4 length, -1 terminator
        $document = [];
        while ($position < $end) {
            $type = ord($data[$position]);
            $position++;
            $keyEnd = strpos($data, "\x00", $position);
            $key = substr($data, $position, $keyEnd - $position);
            $position = $keyEnd + 1;
            $document[$key] = self::decodeValue($data, $position, $type);
        }
        $position++; // terminator
        return $document;
    }

    private static function decodeValue(string $data, int &$position, int $type): mixed
    {
        switch ($type) {
            case 0x01: // double
                $value = unpack('e', substr($data, $position, 8))[1];
                $position += 8;
                return $value;
            case 0x02: // string
                $length = unpack('V', substr($data, $position, 4))[1];
                $position += 4;
                $value = substr($data, $position, $length - 1);
                $position += $length;
                return $value;
            case 0x03: // document
                return self::decodeDocument($data, $position);
            case 0x04: // array
                return array_values(self::decodeDocument($data, $position));
            case 0x08: // boolean
                $value = ord($data[$position]) !== 0;
                $position++;
                return $value;
            case 0x09: // UTC datetime (int64 ms)
                $value = unpack('P', substr($data, $position, 8))[1];
                $position += 8;
                return $value;
            case 0x0A: // null
                return null;
            case 0x10: // int32
                $value = unpack('V', substr($data, $position, 4))[1];
                $position += 4;
                if ($value >= 2147483648) {
                    $value -= 4294967296;
                }
                return $value;
            case 0x12: // int64
                $value = unpack('P', substr($data, $position, 8))[1];
                $position += 8;
                return $value;
            default:
                return null;
        }
    }
}
