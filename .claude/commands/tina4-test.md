# Write Tina4 Tests

Write PHPUnit tests for a Tina4 feature.

## Instructions

1. Create test file in `tests/` matching the module name
2. Use PHPUnit with `TestCase` base class
3. Test both happy path and error cases

## Test Structure

```php
<?php

use PHPUnit\Framework\TestCase;

class FeatureNameTest extends TestCase
{
    public function testHappyPath(): void
    {
        $result = doSomething();
        $this->assertEquals($expected, $result);
    }

    public function testEdgeCase(): void
    {
        // Test boundary conditions
    }

    public function testErrorHandling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        doSomethingBad();
    }
}
```

## Testing ORM Models

```php
<?php

use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function testCreateFromArray(): void
    {
        $product = new Product(["name" => "Widget", "price" => 9.99]);
        $this->assertEquals("Widget", $product->name);
        $this->assertEquals(9.99, $product->price);
    }

    public function testAsArray(): void
    {
        $product = new Product(["name" => "Widget", "price" => 9.99]);
        $data = $product->asArray();
        $this->assertEquals("Widget", $data["name"]);
    }

    public function testDefaults(): void
    {
        $product = new Product();
        $this->assertEquals(1, $product->active);
    }
}
```

## Testing Routes (with mock request/response)

```php
<?php

use PHPUnit\Framework\TestCase;

class ProductRoutesTest extends TestCase
{
    public function testListProducts(): void
    {
        $request = new \stdClass();
        $request->params = ["page" => "1", "limit" => "10"];

        $responses = [];
        $response = new class($responses) {
            private array &$responses;
            public function __construct(array &$responses) { $this->responses = &$responses; }
            public function json($data, $code = 200) { $this->responses[] = [$data, $code]; }
        };

        // Call route handler directly
        $handler = getRouteHandler("/api/products", "GET");
        $handler($request, $response);
        $this->assertCount(1, $responses);
    }

    public function testCreateProduct(): void
    {
        $request = new \stdClass();
        $request->body = ["name" => "Test", "price" => 5.0];

        $responses = [];
        $response = new class($responses) {
            private array &$responses;
            public function __construct(array &$responses) { $this->responses = &$responses; }
            public function json($data, $code = 200) { $this->responses[] = [$data, $code]; }
        };

        $handler = getRouteHandler("/api/products", "POST");
        $handler($request, $response);
        $this->assertEquals(201, $responses[0][1]);
    }
}
```

## Testing Services

```php
<?php

use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    public function testChargeSuccess(): void
    {
        $service = new PaymentService();

        $mockApi = $this->createMock(\Tina4\Api::class);
        $mockApi->method("post")->willReturn([
            "httpCode" => 200,
            "body" => ["id" => "ch_1"],
            "error" => null,
        ]);

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty("api");
        $prop->setAccessible(true);
        $prop->setValue($service, $mockApi);

        $result = $service->charge(1000);
        $this->assertTrue($result["success"]);
    }

    public function testChargeFailure(): void
    {
        $service = new PaymentService();

        $mockApi = $this->createMock(\Tina4\Api::class);
        $mockApi->method("post")->willReturn([
            "httpCode" => 400,
            "body" => null,
            "error" => "Card declined",
        ]);

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty("api");
        $prop->setAccessible(true);
        $prop->setValue($service, $mockApi);

        $result = $service->charge(1000);
        $this->assertFalse($result["success"]);
    }
}
```

## Running Tests

```bash
# All tests
vendor/bin/phpunit tests/

# Single file
vendor/bin/phpunit tests/ProductTest.php

# Single test
vendor/bin/phpunit tests/ProductTest.php --filter testCreate

# With coverage
vendor/bin/phpunit tests/ --coverage-text

# Verbose
vendor/bin/phpunit tests/ -v
```

## Key Rules

- Test file names: `<Feature>Test.php`
- Test class names: `FeatureNameTest`
- Test method names: `test<WhatItTests>`
- Mock external dependencies, not internal framework code
- Test behavior, not implementation details
- Aim for >95% coverage on new code
