# FakeData

The `FakeData` class generates realistic fake data for testing and development. It provides instance methods for names, emails, addresses, companies, dates, UUIDs, and more — all zero-dependency. An optional seed parameter enables deterministic output for reproducible tests.

The seeder also includes a runner that discovers seed files from a directory and executes them.

## Creating an Instance

```php
use Tina4\FakeData;

// Unseeded (random each time)
$fake = new FakeData();

// Seeded (deterministic — same output every time)
$fake = new FakeData(42);
```

## Data Generators

### People

```php
$fake = new FakeData();

echo $fake->firstName();   // "Alice"
echo $fake->lastName();    // "Johnson"
echo $fake->fullName();    // "Bob Smith"
echo $fake->email();       // "alice.johnson@example.com"
echo $fake->phone();       // "+1 (415) 555-1234"
echo $fake->jobTitle();    // "Software Engineer"
```

### Addresses

```php
echo $fake->address();   // "742 Main St, New York"
echo $fake->city();      // "Tokyo"
echo $fake->country();   // "United States"
echo $fake->zipCode();   // "90210"
```

### Companies

```php
echo $fake->company();   // "Johnson Technologies"
```

### Text

```php
echo $fake->word();                    // "lorem"
echo $fake->sentence(words: 8);       // "The quick brown fox jumps over lazy dog."
echo $fake->paragraph(sentences: 3);  // Three random sentences
```

### Numbers and Booleans

```php
echo $fake->integer(min: 1, max: 100);   // 42
echo $fake->float(min: 0, max: 100, decimals: 2);  // 73.55
echo $fake->boolean();  // true
```

### Dates and UUIDs

```php
echo $fake->date('2024-01-01', '2026-12-31');  // "2025-07-15"
echo $fake->uuid();  // "a1b2c3d4-e5f6-4789-ab01-c2d3e4f5a6b7"
```

### Web and Network

```php
echo $fake->url();        // "https://example.com/lorem/ipsum"
echo $fake->ipAddress();  // "192.168.42.100"
```

### Colors

```php
echo $fake->color();     // "teal"
echo $fake->hexColor();  // "#3a7f2c"
```

### Finance

```php
echo $fake->currency();    // "USD"
echo $fake->creditCard();  // "4111234567890123" (test numbers only)
```

## Generating Multiple Records

Use `$fake->run()` to generate multiple rows from a callable.

```php
$fake = new FakeData();

$users = $fake->run(function () use ($fake) {
    return [
        'name' => $fake->fullName(),
        'email' => $fake->email(),
        'phone' => $fake->phone(),
        'city' => $fake->city(),
        'role' => ['admin', 'editor', 'user'][mt_rand(0, 2)],
        'created_at' => $fake->date('2024-01-01', '2026-03-20'),
    ];
}, count: 50);

// Returns 50 arrays with random user data
echo count($users); // 50
echo $users[0]['name']; // "Grace Miller"
```

## Seeding a Database

```php
use Tina4\Database\SQLite3Adapter;
use Tina4\FakeData;

$db = new SQLite3Adapter('app.db');
$fake = new FakeData();

$users = $fake->run(function () use ($fake) {
    return [
        'first_name' => $fake->firstName(),
        'last_name' => $fake->lastName(),
        'email' => $fake->email(),
        'role' => 'user',
    ];
}, count: 100);

$db->begin();
foreach ($users as $user) {
    $db->exec(
        "INSERT INTO users (first_name, last_name, email, role) VALUES (:first, :last, :email, :role)",
        [':first' => $user['first_name'], ':last' => $user['last_name'], ':email' => $user['email'], ':role' => $user['role']]
    );
}
$db->commit();

echo "Seeded 100 users\n";
```

## Seed Files

Place seed files in `src/seeds/`. Each file must return a callable that produces a single row.

```php
// src/seeds/users.php
$fake = new \Tina4\FakeData();
return function () use ($fake) {
    return [
        'first_name' => $fake->firstName(),
        'last_name' => $fake->lastName(),
        'email' => $fake->email(),
        'role' => 'user',
    ];
};
```

```php
// src/seeds/products.php
$fake = new \Tina4\FakeData();
return function () use ($fake) {
    return [
        'name' => ucfirst($fake->word()) . ' ' . ucfirst($fake->word()),
        'price' => $fake->float(1.99, 999.99, 2),
        'category' => ['electronics', 'clothing', 'food', 'books'][mt_rand(0, 3)],
        'stock' => $fake->integer(0, 500),
    ];
};
```

Discover and load seed files:

```php
$fake = new FakeData();
$results = $fake->seedDir('src/seeds');
// ['users.php' => 'loaded', 'products.php' => 'loaded']
```

## Using with ORM

```php
$db = new SQLite3Adapter('app.db');
$fake = new FakeData();

$rows = $fake->run(function () use ($fake) {
    return [
        'first_name' => $fake->firstName(),
        'last_name' => $fake->lastName(),
        'email' => $fake->email(),
    ];
}, count: 25);

foreach ($rows as $row) {
    $user = new User($db, $row);
    $user->save();
}
```

## Deterministic Output

Use a seed for reproducible test data:

```php
$fake = new FakeData(42);
echo $fake->fullName();  // Always the same name for seed 42
echo $fake->email();     // Always the same email for seed 42
```

## Tips

- Unseeded instances use PHP's default `mt_rand()` randomness.
- Seeded instances produce deterministic output — useful for reproducible tests.
- Email addresses use safe test domains (`example.com`, `test.org`, etc.) — they will not reach real inboxes.
- Credit card numbers are standard test numbers (e.g., `4111...`, `4242...`).
- Use `$fake->run()` for bulk generation — it returns an array of generated rows.
- Seed files are sorted alphabetically, so prefix with numbers for ordering: `01_users.php`, `02_products.php`.
- Combine seeding with migrations for a complete development setup: migrate, then seed.
