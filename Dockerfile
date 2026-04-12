# tina4stack/tina4-php:v3
# Base image for Tina4 PHP apps
#
# Usage in your project:
#   FROM tina4stack/tina4-php:v3
#   COPY . .
#   CMD ["php", "index.php", "0.0.0.0:7146"]
#
# Build:
#   docker build -t tina4stack/tina4-php:v3 .
#   docker push tina4stack/tina4-php:v3

# ── Stage 1: Composer install ─────────────────────────────────
FROM php:8.4-cli-alpine3.23 AS composer-stage
WORKDIR /build
RUN apk add --no-cache unzip git
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts
COPY . .

# ── Stage 2: Lean Alpine runtime ─────────────────────────────
FROM php:8.4-cli-alpine3.23
WORKDIR /app

# SQLite + OPcache only — add database extensions in your Dockerfile (see DEPLOYING.md)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions sqlite3 pdo_sqlite opcache && \
    rm -rf /usr/bin/install-php-extensions /var/cache/apk/*

# Copy framework (vendor + Tina4 core)
COPY --from=composer-stage /build/vendor /app/vendor
COPY --from=composer-stage /build/Tina4 /app/Tina4
COPY --from=composer-stage /build/composer.json /app/

# OPcache production settings
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini

# Copy bundled demo app (runs out of the box)
COPY --from=composer-stage /build/example/ /app/

EXPOSE 7145
ENV TINA4_OVERRIDE_CLIENT=true
ENV TINA4_DEBUG=false
CMD ["php", "index.php", "0.0.0.0:7145"]
