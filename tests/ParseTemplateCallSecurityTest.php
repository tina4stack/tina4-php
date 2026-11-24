<?php

/**
 * The template parser's "call" feature invokes callables and instantiates
 * classes named in template content. It must never (a) invoke a dangerous
 * callable (command/code execution, filesystem, deserialization), nor (b) use
 * eval() to instantiate a class, so a crafted class name cannot break out into
 * code. Templates are expected to be trusted; this is defence in depth for the
 * case where untrusted content reaches the parser.
 *
 * No doubles: the real ParseTemplate, real callMethod().
 */

use PHPUnit\Framework\TestCase;
use Tina4\ParseTemplate;

class ParseTemplateCallSecurityTest extends TestCase
{
    private function parser(): ParseTemplate
    {
        return new ParseTemplate("/__does_not_exist__");
    }

    public function testDangerousCallablesAreBlocked(): void
    {
        $parser = $this->parser();

        foreach (["system", "exec", "shell_exec", "passthru", "eval", "unserialize", "file_get_contents", "array_map"] as $dangerous) {
            $this->assertSame(
                "[Call to {$dangerous} is not permitted]",
                $parser->callMethod("call", "{$dangerous}?x"),
                "template must refuse to call {$dangerous}"
            );
        }
    }

    public function testBlockedCallableIsCaseAndNamespaceInsensitive(): void
    {
        $parser = $this->parser();

        $this->assertSame("[Call to SYSTEM is not permitted]", $parser->callMethod("call", "SYSTEM?x"));
        $this->assertSame("[Call to \\system is not permitted]", $parser->callMethod("call", "\\system?x"));
    }

    public function testUnknownClassIsNotInstantiatedViaEval(): void
    {
        $parser = $this->parser();

        // A bogus class name must resolve to a safe "not found" string, never a
        // fatal error or an eval() breakout.
        $result = (string) $parser->callMethod("Definitely_No_Such_Class_" . uniqid(), "someMethod?x");
        $this->assertStringContainsString("not found", $result);
    }
}
