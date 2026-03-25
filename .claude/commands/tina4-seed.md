# Generate Fake Data with Tina4 Seeder

Create seeders to populate tables with realistic fake data for development and testing.

## Instructions

1. Create a seeder file in `src/seeds/`
2. Use `FakeData` for realistic values
3. Run with `composer tina4 seed`

## Seeder File (`src/seeds/SeedProducts.php`)

```php
<?php

use Tina4\Seeder;
use Tina4\FakeData;

class SeedProducts extends Seeder
{
    public function run(): void
    {
        $fake = new FakeData(42);  // Reproducible data

        for ($i = 0; $i < 50; $i++) {
            $this->db->insert("products", [
                "name" => $fake->sentence(3),
                "price" => $fake->numeric(1.0, 999.99, 2),
                "category" => $fake->choice(["Electronics", "Books", "Clothing", "Food"]),
                "active" => $fake->choice([0, 1]),
            ]);
        }
    }
}
```

## ORM-Based Seeding (Simpler)

```php
<?php

use Tina4\Seeder;

// Auto-generates 50 products using field types
$count = Seeder::seedOrm(new Product(), 50, [
    "category" => function ($fake) { return $fake->choice(["A", "B", "C"]); },
    "active" => 1,
]);
```

## Table-Based Seeding

```php
<?php

use Tina4\Seeder;

$count = Seeder::seedTable($db, "products", 50, [
    "name" => "sentence",
    "price" => "numeric",
    "category" => "word",
], [
    "active" => 1,
]);
```

## FakeData Reference

```php
<?php

use Tina4\FakeData;

$fake = new FakeData(42);

// Text
$fake->name();                             // "Alice Johnson"
$fake->email();                            // "alice.johnson@example.com"
$fake->phone();                            // "+1-555-0142"
$fake->word();                             // "quantum"
$fake->sentence(6);                        // "The quick brown fox jumps over"
$fake->paragraph(3);                       // Multi-sentence text

// Numbers
$fake->integer(0, 100);                    // 42
$fake->numeric(0, 1000, 2);               // 123.45

// Dates
$fake->dateTime();                         // DateTime object
$fake->date();                             // Date string

// Utility
$fake->choice(["a", "b", "c"]);           // Random pick from array
$fake->boolean();                          // true/false
$fake->uuid();                             // UUID string
```

## Running Seeders

```bash
composer tina4 seed                 # Run all seeders in src/seeds/
```

## Key Rules

- Use `seed` parameter for reproducible test data
- Seeder files are auto-discovered from `src/seeds/`
- Use `seedOrm()` when you have an ORM model — it auto-detects field types
- Use `seedTable()` for tables without ORM models
- Use overrides for specific values (foreign keys, statuses, etc.)
