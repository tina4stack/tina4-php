# Deployment

Tina4 v3 supports deployment via PHP's built-in server, Swoole for high-performance async servers, and Docker containers. The framework ships with Swoole HTTP and TCP server bootstrap files.

## Development Server

The fastest way to run Tina4 during development:

```bash
# Using composer script
composer start

# Or directly
composer tina4php webservice:run
```

This starts PHP's built-in web server with the Tina4 router handling all requests.

## Swoole HTTP Server

The `swoole-http.php` file bootstraps a Swoole-based HTTP server for high-performance production deployments.

```bash
php swoole-http.php
```

Swoole provides:
- Asynchronous, non-blocking I/O
- Built-in coroutines
- High concurrency without external process managers
- Persistent database connections

**Requirement:** Install the Swoole extension (`pecl install swoole`).

## Swoole TCP Server

For raw TCP applications (e.g., custom protocols, game servers):

```bash
php swoole-tcp.php
```

## Docker Deployment

### Basic Dockerfile

```dockerfile
FROM php:8.2-cli

# Install extensions
RUN docker-php-ext-install pdo pdo_mysql

# Install SQLite3 extension
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader

# Copy application code
COPY . .

# Run post-install scripts
RUN composer dump-autoload --optimize

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
```

### Swoole Dockerfile

```dockerfile
FROM php:8.2-cli

# Install Swoole
RUN pecl install swoole && docker-php-ext-enable swoole

# Install other extensions
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["php", "swoole-http.php"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "8080:8080"
    environment:
      - TINA4_DEBUG=false
      - DATABASE_URL=sqlite:///data/app.db
      - JWT_SECRET=${JWT_SECRET}
      - TINA4_CORS_ORIGINS=https://myapp.com
      - TINA4_RATE_LIMIT=100
      - TINA4_RATE_WINDOW=60
    volumes:
      - app-data:/app/data
      - app-logs:/app/logs
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  app-data:
  app-logs:
```

## Production .env

```bash
# Production configuration
TINA4_DEBUG=false
DATABASE_URL=pgsql://user:password@db:5432/myapp
JWT_SECRET=<generate-a-long-random-string>
TINA4_CORS_ORIGINS=https://myapp.com,https://www.myapp.com
TINA4_RATE_LIMIT=100
TINA4_RATE_WINDOW=60
TINA4_SESSION_BACKEND=redis
TINA4_SESSION_REDIS_URL=tcp://redis:6379
TINA4_AUTO_COMMIT=false
```

## Nginx Reverse Proxy

For production, put nginx in front of the PHP server for SSL termination, static file serving, and load balancing.

```nginx
server {
    listen 443 ssl http2;
    server_name myapp.com;

    ssl_certificate /etc/ssl/certs/myapp.crt;
    ssl_certificate_key /etc/ssl/private/myapp.key;

    # Static files
    location /public/ {
        alias /app/src/public/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # WebSocket upgrade
    location /ws {
        proxy_pass http://app:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    # Application
    location / {
        proxy_pass http://app:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Health Check Endpoint

The `App` class registers a `/health` endpoint automatically:

```bash
curl http://localhost:8080/health
# {"status":"ok","version":"3.0.0","uptime":42.15}
```

Use this for Docker health checks, load balancer probes, and monitoring systems.

## Running Migrations in Production

```bash
# Via CLI
composer tina4php migrate:run

# Or programmatically in index.php
$migration = new \Tina4\Migration($db, 'src/migrations');
$result = $migration->migrate();
```

## CLI Commands

```bash
# Initialize a new project
composer tina4php initialize:run

# Start the web server
composer tina4php webservice:run

# Run tests
composer tina4php tests:run

# Verbose tests
composer tina4php tests:verbose

# Lint
composer lint
```

## Tips

- Always set `TINA4_DEBUG=false` in production to disable verbose logging and template recompilation.
- Use environment variables for all secrets — never commit `.env` to version control.
- The `/health` endpoint is ideal for container orchestration (Kubernetes readiness/liveness probes).
- For Swoole deployments, persistent database connections are maintained across requests — connection pooling is built in.
- Use Docker volumes for `data/` and `logs/` directories to persist state across container restarts.
- Run migrations as part of the container startup or as a separate init container.
- Enable WAL mode for SQLite in production (enabled by default in `SQLite3Adapter`) for better concurrent read performance.
