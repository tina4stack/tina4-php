#!/usr/bin/env bash
# Tina4 Store Demo — One-command setup
# Usage: bash setup.sh
set -euo pipefail

echo "=== Tina4 Store (PHP) Setup ==="
echo ""

# Check PHP
if ! command -v php &>/dev/null; then
    echo "ERROR: PHP not found. Install PHP 8.2+ first."
    echo "  macOS:   brew install php"
    echo "  Ubuntu:  sudo apt install php php-sqlite3"
    echo "  Windows: https://windows.php.net/download/"
    exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "[OK] PHP $PHP_VER"

# Check Composer
if ! command -v composer &>/dev/null; then
    echo ""
    echo "Composer not found. Installing locally..."
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --quiet
    rm composer-setup.php
    COMPOSER="php composer.phar"
    echo "[OK] Composer installed locally"
else
    COMPOSER="composer"
    echo "[OK] Composer $(composer --version 2>/dev/null | head -1)"
fi

# Install dependencies
echo ""
echo "Installing dependencies..."
$COMPOSER install --no-dev --optimize-autoloader 2>&1 | tail -3

# Create .env if missing
if [ ! -f .env ]; then
    cp .env.example .env
    echo "[OK] Created .env from .env.example"
else
    echo "[OK] .env exists"
fi

# Create data directories
mkdir -p data data/sessions data/queue data/mailbox src/public/uploads
echo "[OK] Data directories ready"

echo ""
echo "=== Setup complete! ==="
echo ""
echo "Start the server:"
echo "  php index.php"
echo ""
echo "Then open: http://localhost:7145"
echo ""
echo "Admin login: admin@tina4store.com / admin123"
