# Seeder

The `Seeder` class generates realistic fake data for testing and development. It provides static methods for names, emails, addresses, companies, dates, UUIDs, and more — all zero-dependency using PHP's `random_int()` for secure randomness.

The seeder also includes a runner that discovers seed files from a directory and executes them.

## Data Generators

### People

```php
use Tina4\Seeder;

echo Seeder::firstName();   // "Alice"
echo Seeder::lastName();    // "Johnson"
echo Seeder::fullName();    // "Bob Smith"
echo Seeder::email();       // "alice.johnson@example.com"
echo Seeder::phone();       // "+1 (415) 555-1234"
echo Seeder::jobTitle();    // "Software Engineer"
```

### Addresses

```php
echo Seeder::address();   // "742 Main St, New York"
echo Seeder::city();      // "Tokyo"
echo Seeder::country();   // "United States"
echo Seeder::zipCode();   // "90210"
```

### Companies

```php
echo Seeder::company();   // "Johnson Technologies"
```

### Text

```php
echo Seeder::word();                    // "lorem"
echo Seeder::sentence(words: 8);       // "The quick brown fox jumps over lazy dog."
echo Seeder::paragraph(sentences: 3);  // Three random sentences
```

### Numbers and Booleans

```php
echo Seeder::integer(min: 1, max: 100);   // 42
echo Seeder::float(min: 0, max: 100, decimals: 2);  // 73.55
echo Seeder::boolean();  // true
```

### Dates and UUIDs

```php
echo Seeder::date('2024-01-01', '2026-12-31');  // "2025-07-15"
echo Seeder::uuid();  // "a1b2c3d4-e5f6-4789-ab01-c2d3e4f5a6b7"
```

### Web and Network

```php
echo Seeder::url();        // "https://example.com/lorem/ipsum"
echo Seeder::ipAddress();  // "192.168.42.100"
```

### Colors

```php
echo Seeder::color();     // "teal"
echo Seeder::hexColor();  // "#3a7f2c"
```

### Finance

```php
echo Seeder::currency();    // "USD"
echo Seeder::creditCard();  // "4111234567890123" (test numbers only)
```

## Generating Multiple Records

Use `Seeder::run()` to generate multiple rows from a callable.

```php
$users = Seeder::run(function () {
    return [
        'name' => Seeder::fullName(),
        'email' => Seeder::email(),
        'phone' => Seeder::phone(),
        'city' => Seeder::city(),
        'role' => ['admin', 'editor', 'user'][random_int(0, 2)],
        'created_at' => Seeder::date('2024-01-01', '2026-03-20'),
    ];
}, count: 50);

// Returns 50 arrays with random user data
echo count($users); // 50
echo $users[0]['name']; // "Grace Miller"
```

## Seeding a Database

```php
use Tina4\Database\SQLite3Adapter;
use Tina4\Seeder;

$db = new SQLite3Adapter('app.db');

$users = Seeder::run(function () {
    return [
        'first_name' => Seeder::firstName(),
        'last_name' => Seeder::lastName(),
        'email' => Seeder::email(),
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
return function () {
    return [
        'first_name' => \Tina4\Seeder::firstName(),
        'last_name' => \Tina4\Seeder::lastName(),
        'email' => \Tina4\Seeder::email(),
        'role' => 'user',
    ];
};
```

```php
// src/seeds/products.php
return function () {
    return [
        'name' => ucfirst(\Tina4\Seeder::word()) . ' ' . ucfirst(\Tina4\Seeder::word()),
        'price' => \Tina4\Seeder::float(1.99, 999.99, 2),
        'category' => ['electronics', 'clothing', 'food', 'books'][random_int(0, 3)],
        'stock' => \Tina4\Seeder::integer(0, 500),
    ];
};
```

Discover and load seed files:

```php
$results = Seeder::seed('src/seeds');
// ['users.php' => 'loaded', 'products.php' => 'loaded']
```

## Using with ORM

```php
$db = new SQLite3Adapter('app.db');

$rows = Seeder::run(function () {
    return [
        'first_name' => Seeder::firstName(),
        'last_name' => Seeder::lastName(),
        'email' => Seeder::email(),
    ];
}, count: 25);

foreach ($rows as $row) {
    $user = new User($db, $row);
    $user->save();
}
```

## Tips

- All generators use `random_int()` for cryptographically secure randomness.
- Email addresses use safe test domains (`example.com`, `test.org`, etc.) — they will not reach real inboxes.
- Credit card numbers are standard test numbers (e.g., `4111...`, `4242...`).
- Use `Seeder::run()` for bulk generation — it returns an array of generated rows.
- Seed files are sorted alphabetically, so prefix with numbers for ordering: `01_users.php`, `02_products.php`.
- Combine seeding with migrations for a complete development setup: migrate, then seed.
