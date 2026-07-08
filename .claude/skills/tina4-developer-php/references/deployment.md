# Deployment Recipes (PHP)

## Docker Base Image

Tina4 provides an official Docker Hub base image for PHP. It's lean, Alpine-based, and SQLite-only.
Your app Dockerfile extends it and adds only what it needs.

| Framework | Base Image | Default Port | Size |
|-----------|-----------|-------------|------|
| PHP | `tina4stack/tina4-php:v3` | 7145 | ~154MB |

## PHP App Dockerfile

Every PHP Tina4 app uses this exact pattern:

```dockerfile
FROM tina4stack/tina4-php:v3
WORKDIR /app

# Install Composer and app dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && rm /usr/bin/composer

# Copy application code
COPY index.php .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/

# Create data directories
RUN mkdir -p data data/sessions data/queue data/mailbox

EXPOSE 7145
CMD ["php", "index.php", "0.0.0.0:7145"]
```

### .dockerignore

```
vendor/
data/
tests/
.tina4/
.DS_Store
*.db
*.db-wal
*.db-shm
logs/
```

### Build and Run

```bash
docker build -t my-app .
docker run -d -p 7145:7145 -v $(pwd)/data:/app/data my-app
```

## Adding Database Drivers

The base image ships with SQLite only. Add PDO drivers in your app's Dockerfile with
`mlocati/php-extension-installer`.

### PHP — PostgreSQL

```dockerfile
FROM tina4stack/tina4-php:v3
WORKDIR /app

# Add PostgreSQL extension
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_pgsql && rm /usr/bin/install-php-extensions

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts && rm /usr/bin/composer
COPY index.php .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7145
CMD ["php", "index.php", "0.0.0.0:7145"]
```

### PHP — MySQL

```dockerfile
FROM tina4stack/tina4-php:v3
WORKDIR /app

# Add MySQL extension
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_mysql && rm /usr/bin/install-php-extensions

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts && rm /usr/bin/composer
COPY index.php .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7145
CMD ["php", "index.php", "0.0.0.0:7145"]
```

### PHP — MSSQL

```dockerfile
FROM tina4stack/tina4-php:v3
WORKDIR /app

# Add MSSQL extension
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_sqlsrv && rm /usr/bin/install-php-extensions

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts && rm /usr/bin/composer
COPY index.php .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7145
CMD ["php", "index.php", "0.0.0.0:7145"]
```

### PHP — Firebird

Firebird requires system-level libraries not available in Alpine. Use a Debian-based image instead:

```dockerfile
FROM php:8.4-cli-bookworm
WORKDIR /app

# Install Firebird client and PHP extension
RUN apt-get update && apt-get install -y --no-install-recommends \
    firebird-dev libfbclient2 && \
    docker-php-ext-install pdo_firebird interbase && \
    apt-get purge -y firebird-dev && apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/*

# Install Tina4 PHP framework via Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts && rm /usr/bin/composer
COPY index.php .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7145
ENV TINA4_OVERRIDE_CLIENT=true
ENV TINA4_DEBUG=false
CMD ["php", "index.php", "0.0.0.0:7145"]
```

> **Note:** The Firebird Dockerfile cannot use `tina4stack/tina4-php:v3` because the base image is
> Alpine and Firebird's `fbclient` library requires glibc (Debian). This is the only database driver
> that requires a different base.

## Docker Compose

```yaml
services:
  app:
    build: .
    ports:
      - "7145:7145"                       # PHP default port
    environment:
      - TINA4_DEBUG=false
      - TINA4_SECRET=${TINA4_SECRET}
      - TINA4_DATABASE_URL=sqlite:data/app.db
    volumes:
      - app-data:/app/data
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:7145/health"]
      interval: 30s
      timeout: 5s
      retries: 3

volumes:
  app-data:
```

## Environment Variables

Pass secrets at runtime, never bake them into images:

```bash
docker run -d \
  -p 7145:7145 \
  -e TINA4_SECRET=your-secret \
  -e TINA4_DATABASE_URL=sqlite:data/app.db \
  -e TINA4_DEBUG=false \
  -v $(pwd)/data:/app/data \
  my-app
```

## Key Environment Variables for Docker

| Variable | Default | Purpose |
|----------|---------|---------|
| `TINA4_OVERRIDE_CLIENT` | `true` (set in base image) | Bypass the CLI guard in Docker |
| `TINA4_DEBUG` | `false` (set in base image) | Disable debug mode |
| `TINA4_SECRET` | — | JWT signing secret (pass at runtime) |
| `TINA4_DATABASE_URL` | — | Database connection string |
| `PORT` | `7145` | Listen port |

## Production Checklist

1. Use `tina4stack/tina4-php:v3` as the base (except Firebird — see above)
2. Mount a volume for `/app/data` (SQLite database, sessions, queue)
3. Set `TINA4_DEBUG=false`
4. Pass `TINA4_SECRET` via environment variable (not baked into the image)
5. Health check endpoint at `/health`
6. Configure a Docker restart policy (`unless-stopped` or `always`)
7. Set up log rotation via the Docker logging driver
8. Use a reverse proxy (nginx/Traefik) for SSL termination in front
