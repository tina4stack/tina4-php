<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A missing optional driver names the package AND the exact install command
 * (the standard set by tina4-nodejs#67):
 *
 *   The 'X' package is required for FEATURE. Install it with: <command>
 *
 * Every case runs in a separate real PHP process where the driver is GENUINELY
 * absent, and the child reports what it found, so an environment that has the
 * driver FAILS the test instead of quietly proving nothing. No shim, no double.
 *
 *   S3 storage      aws/aws-sdk-php is not a dependency of tina4-php, so the
 *                   project's own composer autoloader cannot load it.
 *   database cache  `php -n` starts an interpreter with no php.ini, so ext-pgsql
 *                   is genuinely not loaded. The Mongo, Redis, Valkey and
 *                   Memcached cache backends speak their wire protocols over
 *                   plain sockets and have no driver that can go missing; the
 *                   database backend is the one cache backend that can.
 */

use PHPUnit\Framework\TestCase;

final class DriverMissingMessageTest extends TestCase
{
    private const S3_MESSAGE = "The 'aws/aws-sdk-php' package is required for S3Storage. "
        . 'Install it with: composer require aws/aws-sdk-php';

    /**
     * Run a probe program in a child PHP process and decode its JSON report.
     *
     * @param array<int, string> $phpFlags
     * @return array<string, mixed>
     */
    private function probe(string $program, array $phpFlags = [], array $extraEnvironment = []): array
    {
        $probePath = \TempPath::file('tina4_driver_probe_', '.php');
        file_put_contents($probePath, $program);
        $workDirectory = \TempPath::dir('tina4_driver_probe_');

        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'TINA4_CACHE') || str_starts_with($name, 'TINA4_STORAGE') || str_starts_with($name, 'TINA4_LOG')) {
                unset($environment[$name]);
            }
        }
        $environment = array_merge($environment, [
            'PROBE_AUTOLOAD' => dirname(__DIR__) . '/vendor/autoload.php',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_LOG_OUTPUT' => 'stdout',
            'TINA4_LOG_LEVEL' => 'DEBUG',
        ], $extraEnvironment);

        $process = proc_open(
            array_merge([PHP_BINARY], $phpFlags, [$probePath]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workDirectory,
            $environment
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringContainsString('__PROBE__', $stdout, "probe did not report:\n{$stdout}\n{$stderr}");
        [$logged, $json] = explode('__PROBE__', $stdout, 2);
        $report = json_decode($json, true);
        $this->assertIsArray($report, "probe report is not JSON:\n{$stdout}");
        $report['logged'] = $logged . $stderr;
        return $report;
    }

    public function testS3StorageNamesThePackageAndTheInstallCommand(): void
    {
        $report = $this->probe(<<<'PHP'
<?php
require getenv('PROBE_AUTOLOAD');
$report = ['sdk_present' => class_exists('Aws\\S3\\S3Client')];
try {
    new \Tina4\Realtime\S3Storage('http://127.0.0.1:1', 'key', 'secret', 'bucket');
    $report['outcome'] = 'constructed';
} catch (\Throwable $error) {
    $report['outcome'] = 'raised';
    $report['error_type'] = get_class($error);
    $report['message'] = $error->getMessage();
}
echo '__PROBE__' . json_encode($report);
PHP);

        $this->assertFalse($report['sdk_present'], 'aws/aws-sdk-php is loadable here, so this test would prove nothing');
        $this->assertSame('raised', $report['outcome']);
        $this->assertSame(\RuntimeException::class, $report['error_type']);
        $this->assertSame(self::S3_MESSAGE, $report['message']);
    }

    public function testStorageSelectFallbackWarningCarriesTheInstallCommand(): void
    {
        $report = $this->probe(<<<'PHP'
<?php
require getenv('PROBE_AUTOLOAD');
$report = ['sdk_present' => class_exists('Aws\\S3\\S3Client')];
$storage = \Tina4\Realtime\Storage::select();
$report['selected'] = get_class($storage);
echo '__PROBE__' . json_encode($report);
PHP, [], ['TINA4_STORAGE_BACKEND' => 's3', 'TINA4_STORAGE_BUCKET' => 'bucket']);

        $this->assertFalse($report['sdk_present'], 'aws/aws-sdk-php is loadable here, so this test would prove nothing');
        $this->assertSame(\Tina4\Realtime\LocalStorage::class, $report['selected']);
        $this->assertStringContainsString('falling back to local filesystem storage', $report['logged']);
        $this->assertStringContainsString('Install it with: composer require aws/aws-sdk-php', $report['logged']);
    }

    private const DATABASE_CACHE_PROBE = <<<'PHP'
<?php
require getenv('PROBE_AUTOLOAD');
$report = ['pgsql_present' => function_exists('pg_connect')];
$cache = \Tina4\Cache\CacheFactory::create('database', getenv('PROBE_URL'), 10, getcwd() . '/cache');
$report['selected'] = get_class($cache);
echo '__PROBE__' . json_encode($report);
PHP;

    public function testDatabaseCacheFallbackNamesTheMissingDriverAndItsInstallCommand(): void
    {
        // 192.0.2.1 is TEST-NET-1: never reached, because the driver check comes first.
        $report = $this->probe(self::DATABASE_CACHE_PROBE, ['-n'], [
            'PROBE_URL' => 'postgresql://cache_user:cache-s3cret@192.0.2.1:5432/cache',
        ]);

        $this->assertFalse($report['pgsql_present'], 'php -n still loaded ext-pgsql, so this test would prove nothing');
        $this->assertSame(\Tina4\Cache\FileBackend::class, $report['selected']);
        $this->assertStringContainsString("Cache backend 'database' is unavailable", $report['logged']);
        $this->assertStringContainsString('ext-pgsql', $report['logged']);
        $this->assertStringContainsString('apt-get install php-pgsql', $report['logged']);
        $this->assertStringNotContainsString('cache-s3cret', $report['logged']);
    }

    public function testDatabaseCacheUnreachableServiceDoesNotClaimAMissingDriver(): void
    {
        // NEGATIVE CONTROL: the driver IS loaded, the service is not there.
        $report = $this->probe(self::DATABASE_CACHE_PROBE, [], [
            'PROBE_URL' => 'postgresql://cache_user:cache-s3cret@127.0.0.1:1/cache',
        ]);

        $this->assertTrue($report['pgsql_present'], 'ext-pgsql is not loaded here, so the negative control proves nothing');
        $this->assertSame(\Tina4\Cache\FileBackend::class, $report['selected']);
        $this->assertStringContainsString("Cache backend 'database' is unavailable", $report['logged']);
        $this->assertStringNotContainsString('Install it with', $report['logged']);
        $this->assertStringNotContainsString('cache-s3cret', $report['logged']);
    }
}
