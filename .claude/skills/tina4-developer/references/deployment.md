# Deployment Recipes

## Docker Base Images

Tina4 provides official Docker Hub base images. These are lean, Alpine-based, SQLite-only images.
Your app Dockerfile extends them and adds only what it needs.

| Framework | Base Image | Default Port | Size |
|-----------|-----------|-------------|------|
| Python | `tina4stack/tina4-python:v3` | 7146 | ~56MB |
| PHP | `tina4stack/tina4-php:v3` | 7145 | ~154MB |

## Python App Dockerfile

Every Python Tina4 app uses this exact pattern:

```dockerfile
FROM tina4stack/tina4-python:v3
WORKDIR /app

# Copy application code
COPY app.py .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/

# Create data directories
RUN mkdir -p data data/sessions data/queue data/mailbox

EXPOSE 7146
CMD ["python", "app.py"]
```

### .dockerignore

```
.venv
__pycache__
*.pyc
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
docker run -d -p 7146:7146 -v $(pwd)/data:/app/data my-app
```

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

### Build and Run

```bash
docker build -t my-app .
docker run -d -p 7145:7145 -v $(pwd)/data:/app/data my-app
```

## Adding Database Drivers

The base images ship with SQLite only. Add drivers in your app's Dockerfile.

### Python — PostgreSQL

```dockerfile
FROM tina4stack/tina4-python:v3
WORKDIR /app

# Add PostgreSQL driver (pure Python, no system deps on Alpine)
RUN python -m pip install --no-cache-dir psycopg2-binary

COPY app.py .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7146
CMD ["python", "app.py"]
```

### Python — MySQL

```dockerfile
FROM tina4stack/tina4-python:v3
WORKDIR /app

# Add MySQL driver
RUN apk add --no-cache mariadb-connector-c-dev && \
    python -m pip install --no-cache-dir mysqlclient

COPY app.py .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7146
CMD ["python", "app.py"]
```

### Python — MSSQL

```dockerfile
FROM tina4stack/tina4-python:v3
WORKDIR /app

# Add MSSQL driver
RUN apk add --no-cache unixodbc-dev freetds-dev && \
    python -m pip install --no-cache-dir pymssql

COPY app.py .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7146
CMD ["python", "app.py"]
```

### Python — Firebird

```dockerfile
FROM tina4stack/tina4-python:v3
WORKDIR /app

# Firebird driver is pure Python — no system deps needed
RUN python -m pip install --no-cache-dir firebird-driver

COPY app.py .
COPY .env .
COPY migrations/ migrations/
COPY src/ src/
RUN mkdir -p data data/sessions data/queue data/mailbox
EXPOSE 7146
CMD ["python", "app.py"]
```

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

> **Note:** The Firebird PHP Dockerfile cannot use `tina4stack/tina4-php:v3` because the base
> image is Alpine and Firebird's `fbclient` library requires glibc (Debian). This is the only
> database driver that requires a different base.

## Docker Compose

```yaml
services:
  app:
    build: .
    ports:
      - "7146:7146"    # Python default
    environment:
      - TINA4_DEBUG=false
      - JWT_SECRET=${JWT_SECRET}
      - DATABASE_URL=sqlite:///data/app.db
    volumes:
      - app-data:/app/data
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:7146/health')"]
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
  -p 7146:7146 \
  -e JWT_SECRET=your-secret \
  -e DATABASE_URL=sqlite:///data/app.db \
  -e TINA4_DEBUG=false \
  -v $(pwd)/data:/app/data \
  my-app
```

## Key Environment Variables for Docker

| Variable | Default | Purpose |
|----------|---------|---------|
| `TINA4_OVERRIDE_CLIENT` | `true` (set in base image) | Bypass CLI guard in Docker |
| `TINA4_DEBUG` | `false` (set in base image) | Disable debug mode |
| `TINA4_NO_BROWSER` | `true` (Python base only) | Prevent browser open |
| `PYTHONUNBUFFERED` | `1` (Python base only) | Flush stdout for Docker logs |
| `HOST` | `0.0.0.0` (Python base only) | Bind address |
| `PORT` | `7146` (Python) / `7145` (PHP) | Listen port |

## Production Checklist

1. Use `tina4stack/tina4-python:v3` or `tina4stack/tina4-php:v3` as base
2. Mount a volume for `/app/data` (SQLite database, sessions, queue)
3. Set `TINA4_DEBUG=false`
4. Pass `JWT_SECRET` via environment variable (not .env in image)
5. Add health check endpoint at `/health`
6. Configure Docker restart policy (`unless-stopped` or `always`)
7. Set up log rotation via Docker logging driver
8. Use reverse proxy (nginx/Traefik) for SSL termination in front
