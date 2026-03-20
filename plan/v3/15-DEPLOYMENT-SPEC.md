# Tina4 v3.0 — Deployment & Container Specification

## Philosophy
- **Development**: lightweight built-in web server, zero setup, cross-platform
- **Production**: best-of-breed production server per language, K8s-ready
- **Containers**: lightest possible images, advanced caching, one Dockerfile per framework
- **CI/CD**: example GitHub Actions workflows with layer caching

## Dev vs Production Servers

| | Development | Production |
|---|-----------|------------|
| **Goal** | DX, hot reload, debug overlay | Performance, stability, concurrency |
| **Startup** | `tina4py serve` | `tina4py serve --prod` or Dockerfile |
| **Server** | Built-in (lightweight) | Best-of-breed (below) |
| **Hot reload** | Yes (file watcher) | No |
| **Debug overlay** | Yes | No |
| **Compression** | Optional | Always |
| **Workers** | Single process | Multi-worker |
| **Cross-platform** | macOS, Linux, Windows | Linux (container) |

### Production Servers Per Language

| Language | Dev Server | Production Server | Why |
|----------|-----------|-------------------|-----|
| **Python** | Built-in asyncio | **Uvicorn** + ASGI | Industry standard, async, fast |
| **PHP** | Built-in `php -S` | **Swoole** (preferred) or **FrankenPHP** | Async, WebSocket native, persistent |
| **Ruby** | **WEBrick** (stdlib) | **Puma** (threaded) | Default Rails server, battle-tested |
| **Node.js** | Built-in `node:http` | Same (Node.js is its own prod server) | Add **PM2** or **cluster** for multi-process |

### Production Mode Detection
```env
TINA4_ENV=production               # or: development (default)
```

When `TINA4_ENV=production`:
- Debug overlay disabled
- HTML minification enabled
- Structured JSON logging
- gzip compression forced
- Error pages show no stack traces
- .broken files written on errors
- frond.js served minified

## Docker Images (Lightest Possible)

### Strategy: Multi-Stage Builds + Distroless/Alpine

Each framework ships a `build/` folder with production-ready Dockerfiles.

### Python — `build/Dockerfile`
```dockerfile
# Stage 1: Build
FROM python:3.12-slim AS builder
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir --prefix=/install -r requirements.txt
COPY . .

# Stage 2: Production (distroless-like)
FROM python:3.12-slim AS production
WORKDIR /app

# Copy only installed packages and app code
COPY --from=builder /install /usr/local
COPY --from=builder /app /app

# Create required directories
RUN mkdir -p data/.broken logs secrets

# Non-root user
RUN adduser --disabled-password --no-create-home tina4
USER tina4

EXPOSE 7145
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:7145/health')"

ENV TINA4_ENV=production
CMD ["python", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "7145", "--workers", "4"]
```

**Image size target: ~80MB**

### PHP — `build/Dockerfile`
```dockerfile
# Stage 1: Build (with Composer)
FROM php:8.3-cli-alpine AS builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
RUN composer install --no-dev --optimize-autoloader --no-scripts
COPY . .

# Stage 2: Production
FROM php:8.3-cli-alpine AS production
WORKDIR /app

# Install only required extensions
RUN apk add --no-cache libpq libzip icu-libs \
    && docker-php-ext-install pdo_pgsql pdo_mysql opcache

# Copy app
COPY --from=builder /app /app

RUN mkdir -p data/.broken logs secrets \
    && adduser -D -H tina4 \
    && chown -R tina4:tina4 data logs secrets
USER tina4

EXPOSE 7145
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD php -r "echo file_get_contents('http://localhost:7145/health');" || exit 1

ENV TINA4_ENV=production
CMD ["php", "bin/tina4php", "serve", "--prod", "--port", "7145", "--workers", "4"]
```

**Image size target: ~50MB** (Alpine-based)

### PHP with Swoole — `build/Dockerfile.swoole`
```dockerfile
FROM phpswoole/swoole:php8.3-alpine AS production
WORKDIR /app
COPY --from=builder /app /app

RUN mkdir -p data/.broken logs secrets \
    && adduser -D -H tina4 \
    && chown -R tina4:tina4 data logs secrets
USER tina4

EXPOSE 7145
ENV TINA4_ENV=production
CMD ["php", "bin/tina4php", "serve", "--swoole", "--port", "7145", "--workers", "4"]
```

**Image size target: ~60MB**

### Ruby — `build/Dockerfile`
```dockerfile
# Stage 1: Build
FROM ruby:3.3-alpine AS builder
WORKDIR /app
COPY Gemfile Gemfile.lock ./
RUN bundle config set --local without 'development test' \
    && bundle install --jobs 4
COPY . .

# Stage 2: Production
FROM ruby:3.3-alpine AS production
WORKDIR /app

RUN apk add --no-cache libpq sqlite-libs

COPY --from=builder /usr/local/bundle /usr/local/bundle
COPY --from=builder /app /app

RUN mkdir -p data/.broken logs secrets \
    && adduser -D -H tina4 \
    && chown -R tina4:tina4 data logs secrets
USER tina4

EXPOSE 7145
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD ruby -e "require 'net/http'; Net::HTTP.get(URI('http://localhost:7145/health'))" || exit 1

ENV TINA4_ENV=production
CMD ["bundle", "exec", "puma", "-p", "7145", "-w", "4", "-e", "production"]
```

**Image size target: ~60MB**

### Node.js — `build/Dockerfile`
```dockerfile
# Stage 1: Build
FROM node:20-alpine AS builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY . .
RUN npm run build

# Stage 2: Production (distroless)
FROM gcr.io/distroless/nodejs20-debian12 AS production
WORKDIR /app

COPY --from=builder /app/dist /app/dist
COPY --from=builder /app/node_modules /app/node_modules
COPY --from=builder /app/package.json /app/

EXPOSE 7145
ENV TINA4_ENV=production
CMD ["dist/server.js"]
```

**Image size target: ~40MB** (distroless)

## build/ Folder Structure

```
build/
├── python/
│   ├── Dockerfile
│   ├── .dockerignore
│   └── docker-compose.yml          # Dev + prod profiles
├── php/
│   ├── Dockerfile
│   ├── Dockerfile.swoole
│   ├── .dockerignore
│   └── docker-compose.yml
├── ruby/
│   ├── Dockerfile
│   ├── .dockerignore
│   └── docker-compose.yml
├── nodejs/
│   ├── Dockerfile
│   ├── .dockerignore
│   └── docker-compose.yml
└── k8s/                            # Kubernetes manifests
    ├── deployment.yaml
    ├── service.yaml
    ├── ingress.yaml
    ├── hpa.yaml                    # Horizontal Pod Autoscaler
    └── configmap.yaml              # Environment config
```

### .dockerignore (shared pattern)
```
.git
.env
node_modules
__pycache__
vendor
data/
logs/
secrets/
*.db
*.fdb
.broken
tests/
benchmarks/
plan/
```

## GitHub Actions Workflows

### CI/CD with Advanced Caching — `build/workflows/ci.yml`

```yaml
name: CI/CD

on:
  push:
    branches: [main, v3]
  pull_request:
    branches: [main, v3]

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: tina4_test
          POSTGRES_USER: tina4
          POSTGRES_PASSWORD: tina4test
        ports: ["5432:5432"]
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
      mysql:
        image: mysql:8
        env:
          MYSQL_DATABASE: tina4_test
          MYSQL_ROOT_PASSWORD: tina4test
        ports: ["3306:3306"]
      redis:
        image: redis:7
        ports: ["6379:6379"]

    steps:
      - uses: actions/checkout@v4

      # Language-specific setup with dependency caching
      - uses: actions/setup-python@v5
        with:
          python-version: "3.12"
          cache: "pip"              # Cache pip packages

      - name: Install dependencies
        run: pip install -r requirements.txt

      - name: Run tests
        run: tina4py test --db-all
        env:
          DATABASE_URL: postgresql://tina4:tina4test@localhost:5432/tina4_test

  build:
    needs: test
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main'

    steps:
      - uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          file: build/python/Dockerfile
          push: true
          tags: |
            ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:latest
            ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:${{ github.sha }}
          cache-from: type=gha            # GitHub Actions cache
          cache-to: type=gha,mode=max     # Cache ALL layers
          platforms: linux/amd64,linux/arm64
```

### Docker Layer Caching Strategy

```dockerfile
# CRITICAL: Order layers from least to most frequently changed

# Layer 1 — Base image (cached forever)
FROM python:3.12-slim AS builder

# Layer 2 — System dependencies (cached until Dockerfile changes)
RUN apt-get update && apt-get install -y --no-install-recommends gcc

# Layer 3 — Language dependencies (cached until requirements.txt changes)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Layer 4 — Application code (rebuilt on every commit)
COPY . .
```

**Cache hit rates:**
- Layer 1: ~100% (base image rarely changes)
- Layer 2: ~99% (system deps rarely change)
- Layer 3: ~90% (deps change occasionally)
- Layer 4: ~0% (code changes every commit)

**Result:** Most builds only rebuild Layer 4 — seconds, not minutes.

### GitHub Actions Cache Types

| Cache Type | Used For | TTL |
|-----------|---------|-----|
| `actions/cache` | pip/npm/gem/composer packages | 7 days |
| `docker/build-push-action` + `cache-from: type=gha` | Docker layers | 10GB limit |
| `actions/setup-*` built-in cache | Language runtimes | Automatic |

## Kubernetes Manifests

### `build/k8s/deployment.yaml`
```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: tina4-app
spec:
  replicas: 3
  strategy:
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0          # Zero-downtime deploys
  selector:
    matchLabels:
      app: tina4-app
  template:
    metadata:
      labels:
        app: tina4-app
    spec:
      containers:
        - name: tina4
          image: ghcr.io/org/app:latest
          ports:
            - containerPort: 7145
          env:
            - name: TINA4_ENV
              value: production
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: tina4-secrets
                  key: database-url
          resources:
            requests:
              cpu: 100m
              memory: 64Mi
            limits:
              cpu: 500m
              memory: 256Mi
          livenessProbe:
            httpGet:
              path: /health
              port: 7145
            initialDelaySeconds: 10
            periodSeconds: 30
            failureThreshold: 3
          readinessProbe:
            httpGet:
              path: /health
              port: 7145
            periodSeconds: 10
            failureThreshold: 1
          volumeMounts:
            - name: data
              mountPath: /app/data
      volumes:
        - name: data
          emptyDir: {}            # Ephemeral — logs and .broken files
```

### `build/k8s/hpa.yaml`
```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: tina4-app
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: tina4-app
  minReplicas: 2
  maxReplicas: 20
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
    - type: Resource
      resource:
        name: memory
        target:
          type: Utilization
          averageUtilization: 80
```

## CLI Build & Deploy Commands

Build and deploy from the command line — skip the CI/CD wait.

```bash
# Build Docker image locally
tina4py build                              # Build production image
tina4py build --tag myapp:v1.2.0           # Custom tag
tina4py build --platform linux/amd64,linux/arm64  # Multi-arch

# Push to registry
tina4py deploy push --registry ghcr.io/org/myapp
tina4py deploy push --registry docker.io/org/myapp --tag latest

# Deploy to K8s (generates/applies manifests)
tina4py deploy k8s                         # Apply to current kubectl context
tina4py deploy k8s --namespace production
tina4py deploy k8s --replicas 3
tina4py deploy k8s --dry-run               # Show manifests without applying

# Deploy to Docker Compose (local/staging)
tina4py deploy compose                     # docker compose up -d
tina4py deploy compose --profile staging

# Status
tina4py deploy status                      # Show running containers/pods
tina4py deploy logs                        # Tail production logs
tina4py deploy rollback                    # Rollback to previous image

# Full pipeline: build → push → deploy
tina4py deploy --registry ghcr.io/org/myapp --k8s --namespace production
```

### What `tina4py build` Does
1. Detects framework (Python/PHP/Ruby/Node.js)
2. Selects the correct Dockerfile from `build/`
3. Runs multi-stage Docker build with layer caching
4. Tags image with git commit SHA + version
5. Reports image size

### What `tina4py deploy push` Does
1. Authenticates to registry (reads `DOCKER_REGISTRY_TOKEN` or uses docker login)
2. Pushes image with tags (`:latest` + `:v3.0.0` + `:sha-abc123`)
3. Reports push time and digest

### What `tina4py deploy k8s` Does
1. Generates K8s manifests from `build/k8s/` templates
2. Substitutes image tag, replicas, env vars
3. Applies via `kubectl apply`
4. Waits for rollout to complete
5. Reports pod status

### Dev → Staging Fast Loop

The most important workflow. A developer changes code and needs to see it running on staging. This should be **under 60 seconds**, not 10 minutes through CI.

```bash
# The fast loop (one command)
tina4py stage                              # Build + push + deploy to staging
tina4py stage --watch                      # Rebuild on file change, auto-deploy

# What tina4py stage does:
# 1. Incremental Docker build (cached layers, only app code changes)     ~5s
# 2. Push to registry (only changed layer, not full image)               ~3s
# 3. kubectl rollout restart (or docker compose restart)                  ~10s
# 4. Wait for health check to pass                                       ~5s
# 5. Print staging URL                                                   ~0s
# Total:                                                                 ~23s
```

**Config** (`.env` or `build/staging.env`):
```env
STAGING_REGISTRY=ghcr.io/org/myapp
STAGING_NAMESPACE=staging
STAGING_CONTEXT=k8s-staging              # kubectl context
STAGING_URL=https://staging.myapp.com
```

**`--watch` mode:**
```
[10:30:01] File changed: src/routes/api/users/get.py
[10:30:02] Building...
[10:30:07] Pushing layer (142KB)...
[10:30:10] Deploying to staging...
[10:30:18] ✓ Healthy — https://staging.myapp.com
[10:30:18] Waiting for changes...
```

No git push. No CI pipeline. No waiting. Change → staging in seconds.

### Environment Promotion

```bash
# Promote staging image to production (no rebuild)
tina4py deploy promote staging production

# What this does:
# 1. Gets the image digest currently running in staging
# 2. Tags it as production
# 3. Deploys that exact image to production namespace
# 4. Zero rebuild — same binary, guaranteed identical
```

## Image Size Targets

| Framework | Base | Target Size | Strategy |
|-----------|------|------------|----------|
| Python | python:3.12-slim | ~80MB | slim + no-cache pip |
| PHP | php:8.3-cli-alpine | ~50MB | Alpine + minimal extensions |
| PHP+Swoole | phpswoole/swoole:alpine | ~60MB | Alpine + Swoole |
| Ruby | ruby:3.3-alpine | ~60MB | Alpine + minimal gems |
| Node.js | distroless/nodejs20 | ~40MB | Distroless, no shell |

**Comparison** — typical framework Docker images:
- Laravel: ~200-400MB
- Django: ~150-300MB
- Rails: ~300-500MB
- NestJS: ~150-250MB

Tina4 targets **3-10x smaller images**.
