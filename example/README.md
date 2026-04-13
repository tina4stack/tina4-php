# Tina4 Store — PHP Demo

A fully functional e-commerce app demonstrating every Tina4 feature.

## Quick Start

### macOS / Linux

```bash
cd example
bash setup.sh
php index.php
```

### Windows

```cmd
cd example
setup.bat
php index.php
```

### Docker (zero setup)

```bash
cd example
docker build -t tina4-store-php .
docker run -p 7145:7145 tina4-store-php
```

Open http://localhost:7145

## What You Get

| URL | What |
|-----|------|
| http://localhost:7145 | Customer storefront |
| http://localhost:7145/admin | Admin panel |
| http://localhost:7145/swagger | REST API docs |
| http://localhost:7145/__dev | Dev dashboard |

**Demo credentials:**

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@tina4store.com | admin123 |
| Customer | alice@example.com | customer123 |

## Requirements

- PHP 8.2+
- ext-sqlite3 (usually included)
- Composer (setup.sh installs it for you if missing)

## Environment

Copy `.env.example` to `.env` to customise. Defaults work out of the box.

```
TINA4_DEBUG=true          # Dev toolbar + hot reload
SECRET=change-me          # JWT signing key
```

## What It Demonstrates

- **ORM** — Product, Category, Customer, Order, CartItem models with relationships
- **Frond Templates** — Twig-compatible SSR with template inheritance
- **Auth** — JWT login, password hashing, admin middleware
- **Queue** — Background order processing via `$app->background()`
- **I18n** — English/French language toggle
- **REST API** — Full CRUD for products, categories, orders, cart
- **WebSocket** — Live helpdesk chat with rooms
- **SSE** — Real-time sales feed on admin dashboard
- **Migrations** — Database schema versioning
- **Seeds** — Demo data auto-populated on first run
