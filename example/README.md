# Tina4 PHP Example Application

A minimal Tina4 PHP application demonstrating routes, ORM, templates, and JSON APIs.

## Requirements

- PHP >= 8.0
- Composer dependencies installed (from the project root)

## Running

From the `example/` directory:

```bash
php -S localhost:7146 index.php
```

Then open http://localhost:7146 in your browser.

## Structure

```
example/
  index.php              — Entry point (bootstraps Tina4 with SQLite)
  .env                   — Environment config
  src/
    routes/
      index.php          — GET / (renders welcome template)
      api.php            — GET /api/hello, GET /api/users, POST /api/users
    orm/
      User.php           — User ORM model
    templates/
      index.twig         — Welcome page template
```

## API Endpoints

| Method | Path        | Description          |
|--------|-------------|----------------------|
| GET    | /           | Welcome page (HTML)  |
| GET    | /api/hello  | JSON greeting        |
| GET    | /api/users  | List all users       |
| POST   | /api/users  | Create a user (JSON) |

### Create a user

```bash
curl -X POST http://localhost:7146/api/users \
  -H "Content-Type: application/json" \
  -d '{"firstName": "Alice", "lastName": "Smith", "email": "alice@example.com"}'
```

For full documentation visit https://tina4.com
