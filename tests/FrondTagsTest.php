<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Feature 53 - Frond {% include %} / {% extends %} path confinement (TAG-DEC-01).
 *
 * Real templates on disk, a real secret file OUTSIDE the templates dir, and a
 * real symlink -- NO mocks. Every case drives the REAL Frond engine against
 * files it wrote to a temp directory. A legit include/extends UNDER the
 * templates dir renders; a `..` traversal, an absolute path, and a symlink whose
 * realpath escapes the templates dir are all REFUSED (a clear error, never the
 * outside file's bytes).
 *
 * Mutation proof: drop the containment guard in Frond::resolveTemplatePath
 * (Tina4/Frond.php) and the traversal / absolute / symlink cases RENDER the
 * outside file's SECRET marker instead of throwing -- these tests then go RED.
 *
 * Shared conformance fixture:
 * tina4-documentation/plan/v3/fixtures/frondtags_contract.json
 */
class FrondTagsTest extends TestCase
{
    /** A marker written only to a file OUTSIDE the templates dir. */
    private const SECRET = 'TOP-SECRET-OUTSIDE-9f83c1';

    /** @var string[] temp base dirs to remove on teardown */
    private array $tempDirs = [];

    /**
     * Build a REAL templates dir with a legit partial + base, and a REAL secret
     * file OUTSIDE it.
     *
     * @return array{0:string,1:string,2:string} [baseDir, templatesDir, secretPath]
     */
    private function makeTree(): array
    {
        $base = sys_get_temp_dir() . '/frondtags_php_' . bin2hex(random_bytes(6));
        $templates = $base . '/templates';
        mkdir($templates . '/partials', 0777, true);
        file_put_contents($templates . '/partials/hello.twig', 'Hello from a real partial');
        file_put_contents($templates . '/base.twig', '[BASE {% block body %}default{% endblock %} END]');
        $secret = $base . '/secret.txt';               // lives OUTSIDE templates/
        file_put_contents($secret, self::SECRET);
        $this->tempDirs[] = $base;
        return [$base, $templates, $secret];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /** Remove a tree, unlinking symlinks without following them. */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . '/' . $entry);
            }
            @rmdir($path);
        }
    }

    public function testALegitIncludeRendersUnderTheTemplatesDir(): void
    {
        [, $templates] = $this->makeTree();
        file_put_contents($templates . '/page.twig', 'X {% include "partials/hello.twig" %} Y');
        $out = (new Frond($templates))->render('page.twig');
        $this->assertStringContainsString('Hello from a real partial', $out);
    }

    public function testALegitExtendsRendersUnderTheTemplatesDir(): void
    {
        [, $templates] = $this->makeTree();
        file_put_contents(
            $templates . '/child.twig',
            '{% extends "base.twig" %}{% block body %}CHILD-BODY{% endblock %}'
        );
        $out = (new Frond($templates))->render('child.twig');
        $this->assertStringContainsString('CHILD-BODY', $out);
        $this->assertStringContainsString('BASE', $out);   // the parent shell rendered too
    }

    public function testADotDotTraversalIncludeIsRefused(): void
    {
        [, $templates] = $this->makeTree();
        // ../secret.txt climbs OUT of the templates dir.
        file_put_contents($templates . '/evil.twig', '{% include "../secret.txt" %}');
        $this->assertRefused($templates, 'evil.twig');
    }

    public function testAnAbsolutePathIncludeIsRefused(): void
    {
        [, $templates, $secret] = $this->makeTree();
        // An absolute path to the real secret file.
        file_put_contents($templates . '/evil_abs.twig', '{% include "' . $secret . '" %}');
        $this->assertRefused($templates, 'evil_abs.twig');
    }

    public function testASymlinkEscapingTheTemplatesDirIsRefused(): void
    {
        [, $templates, $secret] = $this->makeTree();
        // A REAL symlink INSIDE the templates dir whose target is the secret
        // OUTSIDE it. Its name has no `..` and is not absolute, so only the
        // realpath containment can catch it.
        symlink($secret, $templates . '/sneaky.twig');
        file_put_contents($templates . '/evil_link.twig', '{% include "sneaky.twig" %}');
        $this->assertRefused($templates, 'evil_link.twig');
    }

    /** Render must THROW (refused) and the SECRET must never leak into output/message. */
    private function assertRefused(string $templates, string $template): void
    {
        $frond = new Frond($templates);
        $threw = false;
        $out = '';
        try {
            $out = $frond->render($template);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('escape', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        $this->assertTrue($threw, "template '$template' must be refused, not read");
        $this->assertStringNotContainsString(self::SECRET, $out);
    }
}
