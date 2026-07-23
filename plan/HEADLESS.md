# Headless Mode — Lightweight API-Only Delivery

## Status: DECIDED 2026-07-21 (owner) - do the TINA4_HEADLESS env-var flag only.
## The runtime flag (skip loading dev tooling: dev admin, toolbar, error overlay, SCSS,
## static serving, Frond, dev mailbox, hot reload, metrics, browser-open, tina4-css) is
## APPROVED as a normal release item, all 4 frameworks, one env var, no new repo.
## PARKED: the downstream `tina4-headless` repo and the four published `-headless`
## packages. Gate that on a real measured install-size delta first - core is already
## zero-dependency, so the download win is unproven and 4 more registry packages is a
## permanent maintenance liability.

## Concept

`TINA4_HEADLESS=true` or `pip install tina4-python[headless]` delivers a smaller codebase — just the API core without browser-facing tooling. Pairs with tina4-js for frontend.

## What's included (headless)

- Router (GET/POST/PUT/PATCH/DELETE)
- ORM + Database adapters
- Auth (JWT, password hashing, API keys)
- Sessions
- Queue
- WebSocket
- GraphQL
- API client
- Events
- Container/DI
- Middleware
- Migrations
- i18n
- Messenger (SMTP/IMAP)
- Logging

## What's excluded (headless)

- Dev admin dashboard
- Dev toolbar
- Error overlay (plain JSON errors instead)
- SCSS compiler
- Static file serving
- Frond template engine
- Dev mailbox
- Hot reload
- Code metrics / bubble chart
- Browser auto-open
- tina4-css

## Cross-framework approach

One env var: `TINA4_HEADLESS=true`

| Language | Full install | Headless |
|----------|-------------|----------|
| Python | `pip install tina4-python` | `pip install tina4-python[headless]` or env var |
| PHP | `composer require tina4stack/tina4php` | Env var |
| Ruby | `gem install tina4ruby` | Env var |
| Node.js | `npm install tina4-nodejs` | Just `@tina4/core` + `@tina4/orm` |

## Build Architecture

Single repo: `tina4-headless` — watches for releases on all 4 framework repos.

**Flow:**
1. Release `tina4-python v3.10.44` on GitHub
2. GitHub Action in `tina4-headless` triggers (via `workflow_dispatch` or `repository_dispatch`)
3. Pulls tina4-python source at tagged version
4. Strips excluded modules (dev_admin, frond, scss, metrics, error_overlay, tina4-css)
5. Builds and publishes `tina4-python-headless` to PyPI
6. Same pipeline for PHP → Packagist, Ruby → RubyGems, Node → npm

**Packages published:**
- `tina4-python-headless` (PyPI)
- `tina4stack/tina4php-headless` (Packagist)
- `tina4ruby-headless` (RubyGems)
- `tina4-nodejs-headless` (npm)

**Benefits:**
- Framework repos never change — headless is a downstream build artifact
- One CI pipeline manages all 4 headless builds
- Version numbers stay in sync automatically

## Why

- Smaller deployment footprint for APIs and microservices
- Clean separation when tina4-js handles the frontend
- Faster startup (no dev tooling loaded)
- Reduced attack surface in production
