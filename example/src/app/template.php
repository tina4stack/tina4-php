<?php

/**
 * Shared template rendering with session-aware context injection.
 */

$GLOBALS['DISPLAY_CURRENCIES'] = ["USD", "EUR", "GBP", "ZAR"];

// Session-aware I18n instance (matches Python's approach)
$GLOBALS['_storeI18n'] = new \Tina4\I18n('src/locales', 'en');

\Tina4\Response::getFrond()->addFilter("currency", function ($price) {
    try {
        $price = (float) ($price ?? 0);
    } catch (\Exception $e) {
        return (string) $price;
    }
    $rates = getForexRates("USD");
    $info = getCurrencyInfo();
    $parts = [];
    foreach ($GLOBALS['DISPLAY_CURRENCIES'] as $code) {
        $rate = $rates[$code] ?? 1;
        $sym = $info[$code]["symbol"] ?? "";
        $converted = forexConvert($price, $rate);
        if ($code === "JPY") {
            $parts[] = $sym . number_format($converted, 0);
        } else {
            $parts[] = $sym . number_format($converted, 2);
        }
    }
    return implode(" / ", $parts);
});

// Register t() global — will be overridden per-request in storeRender with session locale
\Tina4\Response::getFrond()->addGlobal("t", function (string $key) {
    return $GLOBALS['_storeI18n']->translate($key);
});

function storeRender(string $templateName, array $data = [], $request = null): string
{
    $context = [];
    if ($request && isset($request->session)) {
        $context["customer_name"] = $request->session->get("customer_name");
        $context["customer_id"] = $request->session->get("customer_id");
        $context["role"] = $request->session->get("role");
        $context["cart_count"] = cartCount($request->session);
        $context["cart_total"] = getCartTotal($request->session);

        $locale = $request->session->get("locale");
        if ($locale) {
            $context["locale"] = $locale;
        }
    }

    if (!isset($context["locale"])) {
        $context["locale"] = "en";
    }

    // Switch locale per request (matches Python: i18n.locale = locale)
    $GLOBALS['_storeI18n']->setLocale($context["locale"]);

    // Override t() in context with session-aware translation (matches Python)
    $i18n = $GLOBALS['_storeI18n'];
    $context["t"] = function (string $key) use ($i18n) {
        return $i18n->translate($key);
    };

    $context = array_merge($context, $data);
    return \Tina4\Response::getFrond()->render($templateName, $context);
}
