<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * SOAP bodies must be UTF-8, and the check happens BEFORE any parse.
 *
 * The DOCTYPE guard is a byte regex. A body in another encoding hides the
 * DOCTYPE from it while libxml still decodes and honours it: a UTF-16 body
 * (with or without a byte-order mark) or a UTF-7 body got its internal entity
 * expanded. Every such body is now refused with the same "Malformed XML"
 * Client fault Python returns, and the operation never runs.
 *
 * Also pinned here: the operation and its parameters are matched by LOCAL
 * name in any namespace, as the Python master does. PHP matched only the
 * no-namespace form and urn:<ServiceName>, so a client using its own
 * namespace got "Empty SOAP Body" for a perfectly valid request.
 *
 * Pure logic over the real WSDL handler: no dependency, no double.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\WSDL;
use Tina4\WSDLOperation;

class WsdlEncodingParityService extends WSDL
{
    protected string $serviceName = 'Parity';

    public static int $invocations = 0;

    #[WSDLOperation(['Result' => 'int'])]
    public function Add(int $a, int $b): array
    {
        self::$invocations++;
        return ['Result' => $a + $b];
    }

    #[WSDLOperation(['Result' => 'string'])]
    public function Echo(string $text): array
    {
        self::$invocations++;
        return ['Result' => $text];
    }
}

final class WsdlEncodingSecurityTest extends TestCase
{
    /** soap-parity case 10: UTF-16LE with a BOM, an internal-entity DOCTYPE, Echo(&e;). */
    private const UTF16_BOM_DOCTYPE_BASE64 = '//48AD8AeABtAGwAIAB2AGUAcgBzAGkAbwBuAD0AIgAxAC4AMAAiACAAZQBuAGMAbwBkAGkAbgBnAD0AIgBVAFQARgAtADEANgAiAD8APgA8ACEARABPAEMAVABZAFAARQAgAHMAbwBhAHAAOgBFAG4AdgBlAGwAbwBwAGUAIABbADwAIQBFAE4AVABJAFQAWQAgAGUAIAAiAEUAWABQAEEATgBEAEUARAAiAD4AXQA+ADwAcwBvAGEAcAA6AEUAbgB2AGUAbABvAHAAZQAgAHgAbQBsAG4AcwA6AHMAbwBhAHAAPQAiAGgAdAB0AHAAOgAvAC8AcwBjAGgAZQBtAGEAcwAuAHgAbQBsAHMAbwBhAHAALgBvAHIAZwAvAHMAbwBhAHAALwBlAG4AdgBlAGwAbwBwAGUALwAiACAAeABtAGwAbgBzADoAdAA9ACIAdQByAG4AOgB0AGkAbgBhADQAOgBwAGEAcgBpAHQAeQAiAD4APABzAG8AYQBwADoAQgBvAGQAeQA+ADwAdAA6AEUAYwBoAG8APgA8AHQAOgB0AGUAeAB0AD4AJgBlADsAPAAvAHQAOgB0AGUAeAB0AD4APAAvAHQAOgBFAGMAaABvAD4APAAvAHMAbwBhAHAAOgBCAG8AZAB5AD4APAAvAHMAbwBhAHAAOgBFAG4AdgBlAGwAbwBwAGUAPgA=';

    private const ENVELOPE_OPEN = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:t="urn:tina4:parity"><soap:Body>';
    private const ENVELOPE_CLOSE = '</soap:Body></soap:Envelope>';
    private const ENTITY_DOCTYPE = '<!DOCTYPE soap:Envelope [<!ENTITY e "EXPANDED">]>';

    protected function setUp(): void
    {
        WsdlEncodingParityService::$invocations = 0;
    }

    /** @return array{0: string, 1: string, 2: string} [faultcode|'', faultstring|Result, raw xml] */
    private function send(string $body): array
    {
        $service = new WsdlEncodingParityService(new Request(
            method: 'POST',
            path: '/parity',
            query: [],
            body: $body,
            headers: ['content-type' => 'text/xml'],
        ));
        $xml = $service->handle()->getBody();
        if (preg_match('#<faultcode>([^<]*)</faultcode>\s*<faultstring>([^<]*)</faultstring>#s', $xml, $match)) {
            return [$match[1], html_entity_decode($match[2], ENT_QUOTES | ENT_XML1), $xml];
        }
        if (preg_match('#<Result>(.*?)</Result>#s', $xml, $match)) {
            return ['', html_entity_decode($match[1], ENT_QUOTES | ENT_XML1), $xml];
        }
        $this->fail("neither a fault nor a result:\n{$xml}");
    }

    private function echoEnvelope(string $text): string
    {
        return self::ENVELOPE_OPEN . "<t:Echo><t:text>{$text}</t:text></t:Echo>" . self::ENVELOPE_CLOSE;
    }

    private function assertRefusedAsMalformed(string $body): void
    {
        [$faultCode, $faultString, $xml] = $this->send($body);
        $this->assertSame('Client', $faultCode, $xml);
        $this->assertSame('Malformed XML', $faultString);
        $this->assertStringNotContainsString('EXPANDED', $xml);
        $this->assertSame(0, WsdlEncodingParityService::$invocations, 'the operation ran on a refused body');
    }

    public function testUtf16BodyWithBomAndDoctypeIsRefusedBeforeParse(): void
    {
        $this->assertRefusedAsMalformed(base64_decode(self::UTF16_BOM_DOCTYPE_BASE64));
    }

    public function testUtf16BodyWithoutBomIsRefused(): void
    {
        $document = '<?xml version="1.0" encoding="UTF-16"?>' . self::ENTITY_DOCTYPE . $this->echoEnvelope('&e;');
        $this->assertRefusedAsMalformed(mb_convert_encoding($document, 'UTF-16LE', 'UTF-8'));
    }

    public function testUtf7DeclaredBodyHidingADoctypeIsRefused(): void
    {
        // "<!DOCTYPE ... [<!ENTITY e "EXPANDED">]>" written in UTF-7: every byte
        // is ASCII and valid UTF-8, and the byte regex sees no "<!DOCTYPE".
        $document = '<?xml version="1.0" encoding="UTF-7"?>'
            . '+ADwAIQ-DOCTYPE soap:Envelope +AFsAPAAh-ENTITY e +ACI-EXPANDED+ACIAPgBdAD4-'
            . $this->echoEnvelope('&e;');
        $this->assertRefusedAsMalformed($document);
    }

    public function testUtf8BomPrefixedBodyIsRefused(): void
    {
        $this->assertRefusedAsMalformed("\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-8"?>' . $this->echoEnvelope('hello'));
    }

    public function testInvalidUtf8BytesAreRefused(): void
    {
        $this->assertRefusedAsMalformed('<?xml version="1.0" encoding="ISO-8859-1"?>' . $this->echoEnvelope("caf\xE9"));
    }

    public function testOnlyTheExactUtf8EncodingNameIsAccepted(): void
    {
        foreach (['UTF8', 'ISO-8859-1', 'US-ASCII', 'UTF-16'] as $encoding) {
            WsdlEncodingParityService::$invocations = 0;
            $this->assertRefusedAsMalformed("<?xml version=\"1.0\" encoding=\"{$encoding}\"?>" . $this->echoEnvelope('ascii'));
        }
        [$faultCode, $result] = $this->send("<?xml version='1.0' encoding='Utf-8'?>" . $this->echoEnvelope('ascii'));
        $this->assertSame('', $faultCode);
        $this->assertSame('ascii', $result);
        [$faultCode, $result] = $this->send('<?xml version="1.0"?>' . $this->echoEnvelope('no-encoding'));
        $this->assertSame('', $faultCode);
        $this->assertSame('no-encoding', $result);
    }

    public function testUtf8BodyWithNonAsciiTextIsAccepted(): void
    {
        [$faultCode, $result] = $this->send('<?xml version="1.0" encoding="utf-8"?>' . $this->echoEnvelope('héllo ✓'));
        $this->assertSame('', $faultCode);
        $this->assertSame('héllo ✓', $result);
    }

    public function testBodyWithoutXmlDeclarationIsAccepted(): void
    {
        [$faultCode, $result] = $this->send($this->echoEnvelope('plain'));
        $this->assertSame('', $faultCode);
        $this->assertSame('plain', $result);
    }

    public function testOperationAndParametersInAClientNamespaceResolveByLocalName(): void
    {
        [$faultCode, $result] = $this->send(
            '<?xml version="1.0" encoding="UTF-8"?>' . self::ENVELOPE_OPEN
            . '<t:Add><t:a>2</t:a><t:b>3</t:b></t:Add>' . self::ENVELOPE_CLOSE
        );
        $this->assertSame('', $faultCode);
        $this->assertSame('5', $result);
    }

    public function testUnknownOperationInAClientNamespaceIsNamed(): void
    {
        [$faultCode, $faultString] = $this->send(self::ENVELOPE_OPEN . '<t:Nope><t:x>1</t:x></t:Nope>' . self::ENVELOPE_CLOSE);
        $this->assertSame('Client', $faultCode);
        $this->assertSame('Unknown operation: Nope', $faultString);
    }
}
