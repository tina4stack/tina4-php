<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Response;

/**
 * Regression: Response::file() must not serve a file outside its root.
 *
 * The bug: the natural spelling of a download route,
 *
 *     $response->file('downloads/' . $name);   // $name = '../secret.env'
 *
 * served any file the process could read.
 *
 * Two properties are pinned, and BOTH matter:
 *
 *  - the single-hop escape 'downloads/../secret.env' is refused. This is the
 *    discriminating case. A deep '../../../..' chain can climb above / and
 *    resolve to nothing, so it can 404 on a VULNERABLE build too - a test
 *    that only checks the deep chain passes against the bug.
 *  - a legitimate file inside the root is still served. Without this negative
 *    control, a "fix" that simply breaks file() would pass.
 *
 * No mocks: real files on a real temp filesystem.
 */
class ResponseFileTraversalTest extends TestCase
{
    private string $root;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->root = sys_get_temp_dir() . '/tina4-trav-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/downloads', 0777, true);
        file_put_contents($this->root . '/downloads/report.txt', "PUBLIC REPORT\n");
        file_put_contents($this->root . '/secret.env', "TINA4_SECRET=super-secret-value\n");
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        @unlink($this->root . '/downloads/report.txt');
        @unlink($this->root . '/secret.env');
        @rmdir($this->root . '/downloads');
        @rmdir($this->root);
    }

    private function serve(string $path, ?string $root = null): Response
    {
        $response = new Response();
        $response->file($path, null, false, $root);
        return $response;
    }

    /**
     * NEGATIVE CONTROL - a fix that breaks file() outright must not pass.
     */
    public function testFileServesAFileInsideTheRoot(): void
    {
        $response = $this->serve('downloads/report.txt');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("PUBLIC REPORT\n", $response->getBody());
    }

    /**
     * The reliable discriminator: one '..' reaching a real file next door.
     */
    public function testFileRefusesSingleHopEscape(): void
    {
        $response = $this->serve('downloads/../secret.env');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('super-secret-value', (string)$response->getBody());
    }

    public function testFileRefusesDeepTraversalChain(): void
    {
        $response = $this->serve('../../../../../../etc/passwd');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * No '..' at all - containment, not the '..' check, has to catch this.
     * Containment applies ONLY when the caller declared a root, so the root
     * is what makes this 403.
     */
    public function testFileRefusesAbsolutePathOutsideADeclaredRoot(): void
    {
        $response = $this->serve('/etc/passwd', $this->root);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * REGRESSION CONTROL. Confinement once defaulted to getcwd(), so every
     * legitimate absolute path outside the project answered 403 - a missing
     * file reported Forbidden instead of Not Found. Unrooted, an absolute
     * path is the caller's business (Express res.sendFile, Rails send_file,
     * ASP.NET PhysicalFile all serve one), so this must NOT be 403.
     */
    public function testFileServesAnAbsolutePathWhenNoRootIsDeclared(): void
    {
        $outside = sys_get_temp_dir() . '/tina4-outside-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($outside, "OUTSIDE\n");
        try {
            $response = $this->serve($outside);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame("OUTSIDE\n", $response->getBody());
        } finally {
            @unlink($outside);
        }

        $missing = $this->serve('/nonexistent/path/to/file.css');
        $this->assertSame(404, $missing->getStatusCode());
    }

    public function testFileHonoursAnExplicitRoot(): void
    {
        chdir($this->root . '/downloads');
        $ok = $this->serve('report.txt', $this->root . '/downloads');
        $this->assertSame(200, $ok->getStatusCode());

        $escaped = $this->serve('../secret.env', $this->root . '/downloads');
        $this->assertSame(403, $escaped->getStatusCode());
    }

    /**
     * The 404 body must not echo the requested path back - it leaked the
     * absolute filesystem layout to anyone who could probe a missing file.
     */
    public function testMissingFileDoesNotLeakTheAbsolutePath(): void
    {
        $response = $this->serve('downloads/nope.txt');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('File not found', $response->getBody());
        $this->assertStringNotContainsString($this->root, (string)$response->getBody());
    }
}
