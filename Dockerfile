# ghcr.io/tina4stack/tina4-php
#
# Base image for Tina4 PHP apps: the PHP runtime plus the Tina4 framework and its
# vendor tree already installed, so a developer injects only their own src/.
#
# Usage in your project -- note there is NO composer install step, because the
# framework and its dependencies are already in the image:
#   FROM ghcr.io/tina4stack/tina4-php:3.13.93
#   COPY src/ /app/src/
#   # inherits the correct production CMD; override only if you need to
#
# Pinning: prefer an exact version tag. `latest` and `v3` also exist and move.
#
# ---------------------------------------------------------------------------
# THIS IMAGE DID NOT BOOT FOR MONTHS. Three independent defects, each catchable
# only by a real `docker run`, and no CI ever built or ran it:
#
#   1. The CMD silently dropped the bind address. `php index.php 0.0.0.0:7145`
#      looks like it sets the address, but index.php calls $app->run(), whose
#      signature run(?string $host, int $port) never reads argv. The address was
#      dropped and production mode never engaged, so the server bound 127.0.0.1
#      and was unreachable through `docker run -p`.
#   2. No CLI in the image. vendor/bin/tina4php is a composer shim that includes
#      vendor/tina4stack/tina4php/bin/tina4php, which does not exist here because
#      in THIS repo Tina4 is the project, not a vendored package.
#   3. The EXAMPLE APP's vendor tree clobbered the framework's, at the very last
#      instruction of the build. The demo app is a normal Tina4 PHP app, so it
#      vendors tina4php as a Packagist DEPENDENCY; its autoloader resolves the
#      eager `files` map to $vendorDir/tina4stack/tina4php/Tina4/Constants.php,
#      which does not exist even inside example/vendor, and which THIS repo can
#      never produce (here Tina4 IS the project: Tina4/Bootstrap/Constants.php).
#      `COPY /build/example/ /app/` dropped it on /app/vendor and requiring
#      vendor/autoload.php fatalled before any application code ran.
#
# All three are fixed below. (3) is fixed in .dockerignore with `**/vendor`, so
# no vendor tree at ANY depth enters the build context and composer's own install
# stays authoritative. Note a bare `vendor` pattern is NOT enough -- Docker
# matches from the context root, so it misses example/vendor entirely, which is
# exactly the one that mattered. Two earlier attempts blamed the repo's own
# vendor/ and patched the symptom by copying Tina4/ into the vendored path; that
# could never work, because the stale map names several such paths.
#
# The durable fix is not any single line here: it is a job that builds this
# image, boots it, and requires a 200 through a PUBLISHED port before it may be
# pushed. Without that gate this file will rot again.
# ---------------------------------------------------------------------------

# -- Stage 1: composer install ----------------------------------------------
FROM php:8.4-cli-alpine3.23 AS composer-stage
WORKDIR /build
RUN apk add --no-cache unzip git
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies resolve from the manifest alone, so this layer stays cached across
# source edits. --no-autoloader because the autoloader has to be generated AFTER
# the source arrives: this composer.json declares an eager `files` list
# (Tina4/Bootstrap/*.php) plus a PSR-4 map over Tina4/, and an optimised classmap
# built against an empty directory would be empty.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize

# -- Stage 2: lean Alpine runtime -------------------------------------------
FROM php:8.4-cli-alpine3.23
WORKDIR /app

# SQLite + OPcache only -- add database extensions in your own Dockerfile
# (see DEPLOYING.md).
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions sqlite3 pdo_sqlite opcache && \
    rm -rf /usr/bin/install-php-extensions /var/cache/apk/*

RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini

# The framework: its vendor tree, its source, and its manifest.
COPY --from=composer-stage /build/vendor /app/vendor
COPY --from=composer-stage /build/Tina4 /app/Tina4
COPY --from=composer-stage /build/composer.json /app/

# The framework's own CLI. Required, and NOT interchangeable with
# vendor/bin/tina4php: that shim includes a vendored path which does not exist in
# this image (see defect 2 above).
COPY --from=composer-stage /build/bin/ /app/bin/

# The bundled demo app, so the image runs out of the box.
COPY --from=composer-stage /build/example/ /app/

EXPOSE 7145
ENV TINA4_OVERRIDE_CLIENT=true
ENV TINA4_DEBUG=false

# Bind 0.0.0.0 explicitly, and exec the repo's own bin/tina4php (see defects 1
# and 2 above).
CMD ["php", "bin/tina4php", "serve", "--host", "0.0.0.0", "--port", "7145", "--production"]
