<?php

/**
 * Forex service — fetches live exchange rates using Tina4 Api client.
 * Uses the free frankfurter.app API (no key required).
 */

const CURRENCIES = [
    "USD" => ["symbol" => "$", "name" => "US Dollar"],
    "EUR" => ["symbol" => "€", "name" => "Euro"],
    "GBP" => ["symbol" => "£", "name" => "British Pound"],
    "ZAR" => ["symbol" => "R", "name" => "South African Rand"],
    "JPY" => ["symbol" => "¥", "name" => "Japanese Yen"],
];

$GLOBALS['_forexCache'] = ["rates" => null, "fetched_at" => 0];
const FOREX_CACHE_TTL = 3600; // 1 hour

function getForexRates(string $base = "USD"): array
{
    $now = time();
    if ($GLOBALS['_forexCache']["rates"] && ($now - $GLOBALS['_forexCache']["fetched_at"]) < FOREX_CACHE_TTL) {
        return $GLOBALS['_forexCache']["rates"];
    }

    try {
        $api = new \Tina4\Api("https://api.frankfurter.app", "", false, 5);
        $targets = implode(",", array_filter(array_keys(CURRENCIES), fn($c) => $c !== $base));
        $result = $api->get("/latest?from={$base}&to={$targets}");

        if (($result['http_code'] ?? 0) === 200 && !empty($result['body'])) {
            $body = $result['body'];
            if (is_string($body)) {
                $body = json_decode($body, true);
            }
            $rates = $body['rates'] ?? [];
            $rates[$base] = 1.0;
            $GLOBALS['_forexCache']["rates"] = $rates;
            $GLOBALS['_forexCache']["fetched_at"] = $now;
            return $rates;
        }
    } catch (\Exception $e) {
        // Fall through to fallback
    }

    return fallbackRates($base);
}

function fallbackRates(string $base = "USD"): array
{
    return ["USD" => 1.0, "EUR" => 0.92, "GBP" => 0.79, "ZAR" => 18.12, "JPY" => 149.5];
}

function forexConvert(float $price, float $rate): float
{
    return round($price * $rate, 2);
}

function getCurrencyInfo(): array
{
    return CURRENCIES;
}
