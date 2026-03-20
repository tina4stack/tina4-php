<?php

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\Response;
use Tina4\WSDL;
use Tina4\WSDLOperation;

/**
 * Concrete test implementation of WSDL.
 */
class TestCalculator extends WSDL
{
    protected string $serviceName = 'Calculator';

    #[WSDLOperation(['Result' => 'int'])]
    public function Add(int $a, int $b): array
    {
        return ['Result' => $a + $b];
    }

    #[WSDLOperation(['Result' => 'int'])]
    public function Subtract(int $a, int $b): array
    {
        return ['Result' => $a - $b];
    }

    #[WSDLOperation(['Result' => 'float'])]
    public function Divide(float $a, float $b): array
    {
        if ($b == 0) {
            throw new \RuntimeException('Division by zero');
        }
        return ['Result' => $a / $b];
    }

    #[WSDLOperation(['Greeting' => 'string'])]
    public function Greet(string $name): array
    {
        return ['Greeting' => "Hello, {$name}!"];
    }

    #[WSDLOperation(['IsValid' => 'boolean'])]
    public function Check(bool $flag): array
    {
        return ['IsValid' => $flag];
    }

    #[WSDLOperation(['Items' => 'string'])]
    public function ListItems(): array
    {
        return ['Items' => ['a', 'b', 'c']];
    }

    #[WSDLOperation(['Result' => 'string'])]
    public function NullResult(): array
    {
        return ['Result' => null];
    }
}

/**
 * WSDL service with no explicit service name.
 */
class TestAutoNamed extends WSDL
{
    #[WSDLOperation(['Echo' => 'string'])]
    public function EchoBack(string $msg): array
    {
        return ['Echo' => $msg];
    }
}

class WsdlTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/calculator', array $query = [], string $rawBody = ''): Request
    {
        return new Request(
            method: $method,
            path: $path,
            query: $query,
            body: $rawBody,
            headers: [],
        );
    }

    private function makeCalculator(string $method = 'GET', string $path = '/calculator', array $query = [], string $rawBody = ''): TestCalculator
    {
        return new TestCalculator($this->makeRequest($method, $path, $query, $rawBody));
    }

    private function buildSoapRequest(string $operation, string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . $bodyXml
            . '</soap:Body>'
            . '</soap:Envelope>';
    }

    // ── Service Discovery ───────────────────────────────────────

    public function testServiceNameExplicit(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('Calculator', $response->getBody());
    }

    public function testAutoNamedServiceUsesClassName(): void
    {
        $req = $this->makeRequest();
        $svc = new TestAutoNamed($req);
        $response = $svc->handle();
        $this->assertStringContainsString('TestAutoNamed', $response->getBody());
    }

    // ── WSDL Generation (GET) ───────────────────────────────────

    public function testGetReturnsWsdlXml(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<?xml', $response->getBody());
        $this->assertStringContainsString('<definitions', $response->getBody());
    }

    public function testWsdlQueryParamReturnsWsdl(): void
    {
        $calc = $this->makeCalculator('POST', '/calculator', ['wsdl' => '']);
        $response = $calc->handle();
        $this->assertStringContainsString('<definitions', $response->getBody());
    }

    public function testWsdlHasTargetNamespace(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('targetNamespace="urn:Calculator"', $response->getBody());
    }

    public function testWsdlHasTypesSection(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<types>', $response->getBody());
        $this->assertStringContainsString('</types>', $response->getBody());
    }

    public function testWsdlHasMessagesForEachOperation(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<message name="AddInput">', $response->getBody());
        $this->assertStringContainsString('<message name="AddOutput">', $response->getBody());
        $this->assertStringContainsString('<message name="SubtractInput">', $response->getBody());
        $this->assertStringContainsString('<message name="SubtractOutput">', $response->getBody());
    }

    public function testWsdlHasPortType(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<portType name="CalculatorPortType">', $response->getBody());
    }

    public function testWsdlHasBinding(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<binding name="CalculatorBinding"', $response->getBody());
        $this->assertStringContainsString('soap:binding style="document"', $response->getBody());
    }

    public function testWsdlHasServiceSection(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<service name="Calculator">', $response->getBody());
    }

    public function testWsdlHasSoapAddress(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('<soap:address location=', $response->getBody());
    }

    public function testWsdlOperationHasSoapAction(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('soapAction="urn:Calculator/Add"', $response->getBody());
        $this->assertStringContainsString('soapAction="urn:Calculator/Subtract"', $response->getBody());
    }

    // ── Type Mappings ───────────────────────────────────────────

    public function testIntParamMapsToXsdInt(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('type="xsd:int"', $response->getBody());
    }

    public function testFloatReturnMapsToXsdFloat(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('type="xsd:float"', $response->getBody());
    }

    public function testStringParamMapsToXsdString(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('type="xsd:string"', $response->getBody());
    }

    public function testBooleanReturnMapsToXsdBoolean(): void
    {
        $calc = $this->makeCalculator();
        $response = $calc->handle();
        $this->assertStringContainsString('type="xsd:boolean"', $response->getBody());
    }

    // ── SOAP Request Handling ───────────────────────────────────

    public function testSoapAddOperation(): void
    {
        $body = '<Add><a>3</a><b>4</b></Add>';
        $xml = $this->buildSoapRequest('Add', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<AddResponse>', $response->getBody());
        $this->assertStringContainsString('<Result>7</Result>', $response->getBody());
    }

    public function testSoapSubtractOperation(): void
    {
        $body = '<Subtract><a>10</a><b>3</b></Subtract>';
        $xml = $this->buildSoapRequest('Subtract', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<SubtractResponse>', $response->getBody());
        $this->assertStringContainsString('<Result>7</Result>', $response->getBody());
    }

    public function testSoapDivideOperation(): void
    {
        $body = '<Divide><a>10</a><b>4</b></Divide>';
        $xml = $this->buildSoapRequest('Divide', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<DivideResponse>', $response->getBody());
        $this->assertStringContainsString('<Result>2.5</Result>', $response->getBody());
    }

    public function testSoapGreetStringParam(): void
    {
        $body = '<Greet><name>World</name></Greet>';
        $xml = $this->buildSoapRequest('Greet', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<GreetResponse>', $response->getBody());
        $this->assertStringContainsString('Hello, World!', $response->getBody());
    }

    public function testSoapBoolTrueConversion(): void
    {
        $body = '<Check><flag>true</flag></Check>';
        $xml = $this->buildSoapRequest('Check', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<IsValid>true</IsValid>', $response->getBody());
    }

    public function testSoapBoolFalseConversion(): void
    {
        $body = '<Check><flag>false</flag></Check>';
        $xml = $this->buildSoapRequest('Check', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<IsValid>false</IsValid>', $response->getBody());
    }

    // ── Fault Responses ─────────────────────────────────────────

    public function testEmptyBodyReturnsFault(): void
    {
        $calc = $this->makeCalculator('POST', '/calculator', [], '');
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
        $this->assertStringContainsString('Empty request body', $response->getBody());
    }

    public function testMalformedXmlReturnsFault(): void
    {
        $calc = $this->makeCalculator('POST', '/calculator', [], '<broken');
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
        $this->assertStringContainsString('Malformed XML', $response->getBody());
    }

    public function testUnknownOperationReturnsFault(): void
    {
        $body = '<UnknownOp><x>1</x></UnknownOp>';
        $xml = $this->buildSoapRequest('UnknownOp', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
        $this->assertStringContainsString('Unknown operation', $response->getBody());
    }

    public function testExceptionInOperationReturnsFault(): void
    {
        $body = '<Divide><a>1</a><b>0</b></Divide>';
        $xml = $this->buildSoapRequest('Divide', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
        $this->assertStringContainsString('Division by zero', $response->getBody());
    }

    public function testFaultHasCorrectStructure(): void
    {
        $calc = $this->makeCalculator('POST', '/calculator', [], '');
        $response = $calc->handle();
        $this->assertStringContainsString('<faultcode>', $response->getBody());
        $this->assertStringContainsString('<faultstring>', $response->getBody());
    }

    public function testEmptyBodyFaultCodeIsClient(): void
    {
        $calc = $this->makeCalculator('POST', '/calculator', [], '');
        $response = $calc->handle();
        $this->assertStringContainsString('<faultcode>Client</faultcode>', $response->getBody());
    }

    public function testServerFaultCodeOnException(): void
    {
        $body = '<Divide><a>1</a><b>0</b></Divide>';
        $xml = $this->buildSoapRequest('Divide', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<faultcode>Server</faultcode>', $response->getBody());
    }

    // ── XML Escaping ────────────────────────────────────────────

    public function testXmlSpecialCharsEscaped(): void
    {
        $body = '<Greet><name>&lt;script&gt;</name></Greet>';
        $xml = $this->buildSoapRequest('Greet', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        // The output should have the special chars escaped
        $this->assertStringContainsString('&lt;script&gt;', $response->getBody());
    }

    // ── Array and Null Results ───────────────────────────────────

    public function testArrayResultRendersMultipleElements(): void
    {
        $body = '<ListItems/>';
        $xml = $this->buildSoapRequest('ListItems', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('<ListItemsResponse>', $response->getBody());
        $this->assertStringContainsString('<Items>a</Items>', $response->getBody());
        $this->assertStringContainsString('<Items>b</Items>', $response->getBody());
        $this->assertStringContainsString('<Items>c</Items>', $response->getBody());
    }

    public function testNullResultUsesXsiNil(): void
    {
        $body = '<NullResult/>';
        $xml = $this->buildSoapRequest('NullResult', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('xsi:nil="true"', $response->getBody());
    }

    // ── Response Structure ──────────────────────────────────────

    public function testSoapResponseHasEnvelope(): void
    {
        $body = '<Add><a>1</a><b>2</b></Add>';
        $xml = $this->buildSoapRequest('Add', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Envelope', $response->getBody());
        $this->assertStringContainsString('soap:Body', $response->getBody());
    }

    public function testSoapResponseIsValidXml(): void
    {
        $body = '<Add><a>1</a><b>2</b></Add>';
        $xml = $this->buildSoapRequest('Add', $body);
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($response->getBody());
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($doc, 'SOAP response should be valid XML');
    }

    // ── WSDLOperation Attribute ─────────────────────────────────

    public function testWsdlOperationAttributeCreation(): void
    {
        $attr = new WSDLOperation(['Result' => 'int', 'Message' => 'string']);
        $this->assertSame('int', $attr->returnTypes['Result']);
        $this->assertSame('string', $attr->returnTypes['Message']);
    }

    public function testWsdlOperationEmptyReturnTypes(): void
    {
        $attr = new WSDLOperation();
        $this->assertEmpty($attr->returnTypes);
    }

    // ── Missing SOAP Body ───────────────────────────────────────

    public function testMissingSoapBodyReturnsFault(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '</soap:Envelope>';
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
    }

    public function testEmptySoapBodyReturnsFault(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body></soap:Body>'
            . '</soap:Envelope>';
        $calc = $this->makeCalculator('POST', '/calculator', [], $xml);
        $response = $calc->handle();
        $this->assertStringContainsString('soap:Fault', $response->getBody());
    }
}
