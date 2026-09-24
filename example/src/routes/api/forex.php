<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/api/forex/rates", function ($request, $response) {
    $rates = getForexRates("USD");
    $currencies = getCurrencyInfo();
    return $response(["base" => "USD", "rates" => $rates, "currencies" => $currencies]);
})->noAuth();

\Tina4\Router::get("/api/forex/convert/{price}/{currency}", function ($request, $response) {
    try {
        $price = (float) $request->params['price'];
        $currency = strtoupper($request->params['currency']);
        $rates = getForexRates("USD");
        $rate = $rates[$currency] ?? null;
        if ($rate === null) {
            return $response(["error" => "Unsupported currency: {$currency}"], 400);
        }
        $converted = forexConvert($price, $rate);
        $currencies = getCurrencyInfo();
        $symbol = $currencies[$currency]['symbol'] ?? '';
        return $response([
            "original" => $price,
            "currency" => $currency,
            "rate" => $rate,
            "converted" => $converted,
            "formatted" => $symbol . number_format($converted, 2),
        ]);
    } catch (\Throwable $e) {
        return $response(["error" => "Invalid price"], 400);
    }
})->noAuth();
