<?php
/**
 * tina4php doctor — diagnose silent startup failures.
 * Run: php vendor/bin/doctor.php
 */
echo "=== Tina4 PHP Doctor ===\n\n";

// 1. PHP version
echo "PHP: " . PHP_VERSION . " (" . PHP_OS_FAMILY . ")\n";
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    echo "  ERROR: PHP >= 8.2 required\n";
}

// 2. Required extensions
$required = ['json', 'mbstring', 'openssl', 'pdo'];
$optional = ['sqlite3', 'pdo_sqlite', 'curl', 'sockets'];
foreach ($required as $ext) {
    echo ($ext . ': ' . (extension_loaded($ext) ? 'OK' : 'MISSING') . "\n");
}
echo "\nOptional:\n";
foreach ($optional as $ext) {
    echo ($ext . ': ' . (extension_loaded($ext) ? 'OK' : 'MISSING') . "\n");
}

// 3. Autoloader
echo "\nAutoloader: ";
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $autoload = getcwd() . '/vendor/autoload.php';
}
if (file_exists($autoload)) {
    echo "OK ({$autoload})\n";
    try {
        require $autoload;
        echo "  Loaded successfully\n";
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "MISSING — run: composer install\n";
}

// 4. .env
$envFile = getcwd() . '/.env';
echo "\n.env: " . (file_exists($envFile) ? "OK ({$envFile})" : "MISSING") . "\n";

// 5. Writable dirs
foreach (['logs', 'data', 'sessions'] as $dir) {
    $path = getcwd() . '/' . $dir;
    if (is_dir($path)) {
        echo "{$dir}/: " . (is_writable($path) ? 'writable' : 'NOT WRITABLE') . "\n";
    }
}

// 6. Try bootstrap
echo "\nBootstrap test:\n";
if (class_exists('\Tina4\App')) {
    try {
        define('TINA4_CLI_SERVE', true);
        $app = new \Tina4\App();
        $app->start();
        echo "  App started OK\n";
        echo "  Routes: " . count(\Tina4\Router::getRoutes()) . "\n";
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
} else {
    echo "  Tina4\App class not found — autoload failed\n";
}

// 7. Port check
echo "\nPort 7145: ";
$sock = @stream_socket_server("tcp://0.0.0.0:7145", $errno, $errstr, STREAM_SERVER_BIND);
if ($sock) {
    echo "available\n";
    fclose($sock);
} else {
    echo "IN USE ({$errstr})\n";
}

echo "\nDone.\n";
