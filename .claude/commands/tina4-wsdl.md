# Create a Tina4 SOAP/WSDL Service

Create a SOAP web service with auto-generated WSDL from PHP type hints.

## Instructions

1. Create a WSDL service class in `src/app/`
2. Define operations with type hints
3. Create a route to serve the WSDL and handle SOAP requests

## Service (`src/app/CalculatorService.php`)

```php
<?php

use Tina4\WSDL;
use Tina4\WsdlOperation;

class CalculatorService extends WSDL
{
    public function __construct()
    {
        parent::__construct(
            "CalculatorService",
            "http://example.com/calculator",
            "http://localhost:7145/soap/calculator"
        );
    }

    #[WsdlOperation]
    public function add(float $a, float $b): float
    {
        return $a + $b;
    }

    #[WsdlOperation]
    public function multiply(float $a, float $b): float
    {
        return $a * $b;
    }

    #[WsdlOperation]
    public function divide(float $a, float $b): float
    {
        if ($b == 0) {
            throw new \InvalidArgumentException("Division by zero");
        }
        return $a / $b;
    }
}
```

## Route (`src/routes/soap.php`)

```php
<?php

use Tina4\Router;

$calculator = new CalculatorService();

Router::get("/soap/calculator", function ($request, $response) use ($calculator) {
    $wsdlXml = $calculator->generate();
    return $response->xml($wsdlXml);
})->noAuth();

Router::post("/soap/calculator", function ($request, $response) use ($calculator) {
    $result = $calculator->handle($request);
    return $response->xml($result);
})->noAuth();
```

## Type Mapping

| PHP Type | XSD Type |
|----------|----------|
| `string` | `xsd:string` |
| `int` | `xsd:int` |
| `float` | `xsd:double` |
| `bool` | `xsd:boolean` |

## Lifecycle Hooks

```php
<?php

class MyService extends WSDL
{
    public function onRequest(string $operation, array $params): array
    {
        // Called before operation executes — validate, log, transform input
        error_log("Calling {$operation} with " . json_encode($params));
        return $params;  // Return modified params or original
    }

    public function onResult(string $operation, $result)
    {
        // Called after operation executes — transform output
        return $result;
    }
}
```

## SOAP Client Request Example

```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:calc="http://example.com/calculator">
    <soapenv:Body>
        <calc:add>
            <a>10</a>
            <b>20</b>
        </calc:add>
    </soapenv:Body>
</soapenv:Envelope>
```

## Key Rules

- Service classes go in `src/app/`, routes in `src/routes/`
- Use PHP type hints — WSDL is auto-generated from them
- GET returns the WSDL definition, POST processes SOAP requests
- Use lifecycle hooks for logging, validation, and transformation
- Use PHP 8 attributes (`#[WsdlOperation]`) for operation marking
