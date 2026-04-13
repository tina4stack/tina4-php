@echo off
REM Tina4 Store Demo — One-command setup (Windows)
REM Usage: setup.bat

echo === Tina4 Store (PHP) Setup ===
echo.

REM Check PHP
where php >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: PHP not found. Install PHP 8.2+ first.
    echo Download from: https://windows.php.net/download/
    echo Extract to C:\php and add to PATH.
    exit /b 1
)

php -r "echo 'PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.PHP_EOL;"
echo [OK] PHP found

REM Check Composer
where composer >nul 2>nul
if %errorlevel% neq 0 (
    echo.
    echo Composer not found. Installing locally...
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --quiet
    del composer-setup.php
    set COMPOSER=php composer.phar
    echo [OK] Composer installed locally
) else (
    set COMPOSER=composer
    echo [OK] Composer found
)

REM Install dependencies
echo Installing dependencies...
%COMPOSER% install --no-dev --optimize-autoloader
echo [OK] Dependencies installed

REM Create .env if missing
if not exist .env (
    copy .env.example .env >nul
    echo [OK] Created .env from .env.example
) else (
    echo [OK] .env exists
)

REM Create data directories
if not exist data\sessions mkdir data\sessions
if not exist data\queue mkdir data\queue
if not exist data\mailbox mkdir data\mailbox
if not exist src\public\uploads mkdir src\public\uploads
echo [OK] Data directories ready

echo.
echo === Setup complete! ===
echo.
echo Start the server:
echo   php index.php
echo.
echo Then open: http://localhost:7145
echo.
echo Admin login: admin@tina4store.com / admin123
