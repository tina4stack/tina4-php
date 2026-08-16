<?php

use PHPUnit\Framework\TestCase;
use Tina4\Metrics;
use Tina4\MetricsEngineException;

/** Real handoff tests for the native metrics engine (ADR-0054). */
class MetricsHandoffTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->assertNotEmpty(shell_exec('command -v tina4'), 'the native tina4 CLI must be on PATH');
        $this->directory = sys_get_temp_dir() . '/tina4-metrics-handoff-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0755, true);
        file_put_contents($this->directory . '/orders.php', "<?php\nfunction total(array \$lines): int { return count(\$lines); }\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/orders.php');
        @rmdir($this->directory);
    }

    public function testFullAnalysisComesFromNativeCli(): void
    {
        $result = Metrics::fullAnalysis($this->directory);
        $this->assertSame('tina4-cli', $result['engine']);
        $this->assertGreaterThanOrEqual(1, $result['files_analyzed']);
        $this->assertArrayHasKey('file_metrics', $result);
        $this->assertArrayHasKey('dependency_graph', $result);
        $this->assertArrayHasKey('has_referencing_test', $result['file_metrics'][0]);
        $this->assertArrayNotHasKey('has_tests', $result['file_metrics'][0]);
    }

    public function testFileDetailComesFromNativeCli(): void
    {
        $result = Metrics::fileDetail($this->directory . '/orders.php');
        $this->assertSame('tina4-cli', $result['engine']);
        $this->assertStringEndsWith('orders.php', $result['path']);
        $this->assertGreaterThanOrEqual(1, $result['function_count']);
        $this->assertIsArray($result['functions']);
        $this->assertSame('total', $result['functions'][0]['name']);
    }

    public function testMissingFileFailsLoudly(): void
    {
        $this->expectException(MetricsEngineException::class);
        $this->expectExceptionMessage('no such file');
        Metrics::fileDetail($this->directory . '/missing.php');
    }
}
