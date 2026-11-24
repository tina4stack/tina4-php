<?php

/**
 * ParseTemplate resolves a request path against the template-include locations
 * (which include src/public) and renders/serves what it finds. It must NEVER
 * execute or disclose a server-interpretable file (e.g. a .php uploaded into
 * src/public) — such a file is treated as "not found". Legitimate template
 * assets (html/twig/css/…) in those same locations must still be served, so
 * src/public remains a valid template-include location.
 *
 * No doubles: real files on disk, the real ParseTemplate, exercised through its
 * real parseFile() resolution.
 */

use PHPUnit\Framework\TestCase;
use Tina4\ParseTemplate;

class ParseTemplateSecurityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tina4-pt-" . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . "evil.php", "<?php echo 'EXECUTED_MARKER'; ?>");
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . "shell.phtml", "<?php echo 'EXECUTED_MARKER'; ?>");
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . "page.html", "<h1>LEGIT_HTML</h1>");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . "*") as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * @return array{content:string, httpCode:int}
     */
    private function serve(string $requestFile): array
    {
        $parseTemplate = new ParseTemplate("/__does_not_exist__");
        $reflection = new \ReflectionObject($parseTemplate);

        $locations = $reflection->getProperty("locations");
        $locations->setValue($parseTemplate, [$this->dir]);

        $root = $reflection->getProperty("root");
        $root->setValue($parseTemplate, "");

        $content = $parseTemplate->parseFile($requestFile);

        return ["content" => (string) $content, "httpCode" => (int) $parseTemplate->httpCode];
    }

    public function testPhpIsNeitherExecutedNorDisclosed(): void
    {
        $result = $this->serve("/evil.php");

        $this->assertStringNotContainsString("EXECUTED_MARKER", $result["content"], "a .php must never be executed");
        $this->assertStringNotContainsString("<?php", $result["content"], "a .php source must never be disclosed");
        $this->assertSame(404, $result["httpCode"], "a .php must be treated as not found");
    }

    public function testPhtmlIsAlsoRefused(): void
    {
        $result = $this->serve("/shell.phtml");

        $this->assertStringNotContainsString("EXECUTED_MARKER", $result["content"]);
        $this->assertStringNotContainsString("<?php", $result["content"]);
        $this->assertSame(404, $result["httpCode"]);
    }

    public function testLegitHtmlTemplateIsStillServed(): void
    {
        $result = $this->serve("/page.html");

        $this->assertStringContainsString("LEGIT_HTML", $result["content"], "a legit .html in a template location must still serve");
        $this->assertNotSame(404, $result["httpCode"], "a legit template asset must not be refused");
    }
}
