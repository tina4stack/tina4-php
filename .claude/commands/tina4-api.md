# Create a Tina4 API Integration

Set up an external API client using the built-in Api class. Never use raw `curl` or `file_get_contents`.

## Instructions

1. Create a service class in `src/app/` for the API client
2. Create route handlers that use the service
3. Use queues for slow API calls

## Service (`src/app/PaymentService.php`)

```php
<?php

use Tina4\Api;

class PaymentService
{
    private Api $api;

    public function __construct()
    {
        $this->api = new Api("https://api.stripe.com/v1");
        $this->api->setBearerToken("sk_live_xxx");
    }

    public function charge(int $amount, string $currency = "usd"): array
    {
        $result = $this->api->post("/charges", ["amount" => $amount, "currency" => $currency]);
        if ($result["error"]) {
            return ["success" => false, "error" => $result["error"]];
        }
        return ["success" => true, "charge" => $result["body"]];
    }

    public function getCustomer(string $customerId): array
    {
        return $this->api->get("/customers/{$customerId}");
    }
}
```

## Route (`src/routes/payments.php`)

```php
<?php

use Tina4\Router;

Router::post("/api/charge", function ($request, $response) {
    $payment = new PaymentService();
    $result = $payment->charge(
        $request->body["amount"],
        $request->body["currency"] ?? "usd"
    );
    if (!$result["success"]) {
        return $response->json($result, 502);
    }
    return $response->json($result);
})->description("Create a payment charge")->tags(["payments"]);
```

## Api Class Reference

```php
<?php

use Tina4\Api;

$api = new Api("https://api.example.com");

// Auth options
$api->setBearerToken("token123");
$api->setBasicAuth("username", "password");
$api->addHeaders(["X-API-Key" => "key123"]);

// HTTP methods — all return ["httpCode", "body", "headers", "error"]
$result = $api->get("/users");
$result = $api->post("/users", ["name" => "Alice"]);
$result = $api->put("/users/1", ["name" => "Bob"]);
$result = $api->patch("/users/1", ["name" => "Bob"]);
$result = $api->delete("/users/1");

// Response is auto-parsed: JSON -> array, otherwise raw text
if ($result["error"] === null) {
    $data = $result["body"];       // array if JSON, string if text
    $status = $result["httpCode"];
}
```

## Key Rules

- Always create a service class in `src/app/` — don't put API logic in routes
- Use singleton pattern for API clients
- For slow APIs (>1s), push to a Queue and process asynchronously
- The Api class auto-handles: JSON parsing, error wrapping, auth headers, SSL
