<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * SOAP 1.1 / WSDL 1.1 service base class.
 *
 * Auto-generates WSDL definitions and handles SOAP XML requests.
 * Zero external dependencies — uses PHP built-in SimpleXML and Reflection.
 *
 * Usage:
 *
 *     class Calculator extends WSDL
 *     {
 *         protected string $serviceName = 'Calculator';
 *
 *         #[WSDLOperation(['Result' => 'int'])]
 *         public function Add(int $a, int $b): array
 *         {
 *             return ['Result' => $a + $b];
 *         }
 *     }
 *
 *     // In a route:
 *     Router::get('/calculator', fn($req, $res) => (new Calculator($req))->handle());
 *     Router::post('/calculator', fn($req, $res) => (new Calculator($req))->handle());
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class WSDLOperation
{
    /**
     * @param array<string, string> $returnTypes Map of return element names to XSD type names
     *                                           e.g. ['Result' => 'int', 'Message' => 'string']
     */
    public function __construct(public array $returnTypes = [])
    {
    }
}

abstract class WSDL
{
    // SOAP / WSDL namespace constants
    private const NS_SOAP = 'http://schemas.xmlsoap.org/wsdl/soap/';
    private const NS_WSDL = 'http://schemas.xmlsoap.org/wsdl/';
    private const NS_XSD = 'http://www.w3.org/2001/XMLSchema';
    private const NS_SOAP_ENV = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * Create a WSDLOperation attribute instance (programmatic alternative to #[WSDLOperation]).
     *
     * @param array<string, string> $returnTypes Map of return element names to XSD type names
     */
    public static function wsdlOperation(array $returnTypes = []): WSDLOperation
    {
        return new WSDLOperation($returnTypes);
    }

    /** PHP type name to XSD type mapping */
    private const TYPE_MAP = [
        'int' => 'xsd:int',
        'integer' => 'xsd:int',
        'float' => 'xsd:float',
        'double' => 'xsd:double',
        'string' => 'xsd:string',
        'bool' => 'xsd:boolean',
        'boolean' => 'xsd:boolean',
    ];

    protected Request $request;
    protected string $namespace = 'http://tina4.com/wsdl';
    protected string $serviceName;

    /** @var array<string, \ReflectionMethod> Discovered operations */
    private array $operations = [];

    /** @var array<string, WSDLOperation> Operation attribute instances */
    private array $operationAttributes = [];

    public function __construct(Request $request)
    {
        $this->request = $request;

        if (!isset($this->serviceName)) {
            $this->serviceName = (new \ReflectionClass($this))->getShortName();
        }

        $this->discoverOperations();
    }

    /**
     * Handle the request: GET ?wsdl returns WSDL, POST handles SOAP.
     */
    public function handle(): Response
    {
        $response = new Response();

        // GET or ?wsdl query parameter — return WSDL definition
        if ($this->request->method === 'GET' || array_key_exists('wsdl', $this->request->query)) {
            $endpointUrl = $this->inferEndpointUrl();
            $wsdl = $this->generateWSDL($endpointUrl);
            return $response($wsdl, 200, 'text/xml; charset=UTF-8');
        }

        // POST — handle SOAP request
        $xmlBody = $this->request->rawBody;
        if (empty($xmlBody)) {
            $fault = $this->soapFault('Client', 'Empty request body');
            return $response($fault, 400, 'text/xml; charset=UTF-8');
        }

        $soapResponse = $this->handleSOAP($xmlBody);
        return $response($soapResponse, 200, 'text/xml; charset=UTF-8');
    }

    /**
     * Generate WSDL 1.1 XML definition.
     */
    public function generateWSDL(string $endpointUrl = ''): string
    {
        $tns = "urn:{$this->serviceName}";
        $parts = [];

        $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $parts[] = "<definitions name=\"{$this->serviceName}\"";
        $parts[] = "  targetNamespace=\"{$tns}\"";
        $parts[] = "  xmlns:tns=\"{$tns}\"";
        $parts[] = '  xmlns:soap="' . self::NS_SOAP . '"';
        $parts[] = '  xmlns:xsd="' . self::NS_XSD . '"';
        $parts[] = '  xmlns="' . self::NS_WSDL . '">';
        $parts[] = '';

        // Types
        $parts[] = '  <types>';
        $parts[] = "    <xsd:schema targetNamespace=\"{$tns}\">";

        foreach ($this->operations as $opName => $method) {
            // Request element
            $parts[] = "      <xsd:element name=\"{$opName}\">";
            $parts[] = '        <xsd:complexType>';
            $parts[] = '          <xsd:sequence>';

            foreach ($method->getParameters() as $param) {
                $xsdType = $this->phpTypeToXsd($param->getType());
                $parts[] = "            <xsd:element name=\"{$param->getName()}\" type=\"{$xsdType}\"/>";
            }

            $parts[] = '          </xsd:sequence>';
            $parts[] = '        </xsd:complexType>';
            $parts[] = "      </xsd:element>";

            // Response element
            $parts[] = "      <xsd:element name=\"{$opName}Response\">";
            $parts[] = '        <xsd:complexType>';
            $parts[] = '          <xsd:sequence>';

            $attr = $this->operationAttributes[$opName] ?? null;
            if ($attr !== null) {
                foreach ($attr->returnTypes as $rName => $rType) {
                    $xsdType = self::TYPE_MAP[$rType] ?? 'xsd:string';
                    $parts[] = "            <xsd:element name=\"{$rName}\" type=\"{$xsdType}\"/>";
                }
            }

            $parts[] = '          </xsd:sequence>';
            $parts[] = '        </xsd:complexType>';
            $parts[] = "      </xsd:element>";
        }

        $parts[] = '    </xsd:schema>';
        $parts[] = '  </types>';
        $parts[] = '';

        // Messages
        foreach ($this->operations as $opName => $method) {
            $parts[] = "  <message name=\"{$opName}Input\">";
            $parts[] = "    <part name=\"parameters\" element=\"tns:{$opName}\"/>";
            $parts[] = '  </message>';
            $parts[] = "  <message name=\"{$opName}Output\">";
            $parts[] = "    <part name=\"parameters\" element=\"tns:{$opName}Response\"/>";
            $parts[] = '  </message>';
        }
        $parts[] = '';

        // PortType
        $parts[] = "  <portType name=\"{$this->serviceName}PortType\">";
        foreach ($this->operations as $opName => $method) {
            $parts[] = "    <operation name=\"{$opName}\">";
            $parts[] = "      <input message=\"tns:{$opName}Input\"/>";
            $parts[] = "      <output message=\"tns:{$opName}Output\"/>";
            $parts[] = '    </operation>';
        }
        $parts[] = '  </portType>';
        $parts[] = '';

        // Binding
        $parts[] = "  <binding name=\"{$this->serviceName}Binding\" type=\"tns:{$this->serviceName}PortType\">";
        $parts[] = '    <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>';
        foreach ($this->operations as $opName => $method) {
            $parts[] = "    <operation name=\"{$opName}\">";
            $parts[] = "      <soap:operation soapAction=\"{$tns}/{$opName}\"/>";
            $parts[] = '      <input><soap:body use="literal"/></input>';
            $parts[] = '      <output><soap:body use="literal"/></output>';
            $parts[] = '    </operation>';
        }
        $parts[] = '  </binding>';
        $parts[] = '';

        // Service
        $parts[] = "  <service name=\"{$this->serviceName}\">";
        $parts[] = "    <port name=\"{$this->serviceName}Port\" binding=\"tns:{$this->serviceName}Binding\">";
        $parts[] = "      <soap:address location=\"{$endpointUrl}\"/>";
        $parts[] = '    </port>';
        $parts[] = '  </service>';

        $parts[] = '</definitions>';

        return implode("\n", $parts);
    }

    /**
     * Parse and execute a SOAP XML request.
     */
    protected function handleSOAP(string $xmlBody): string
    {
        $this->onRequest($this->request);

        // Suppress XML warnings and parse
        $previousErrors = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xmlBody);
        libxml_use_internal_errors($previousErrors);

        if ($root === false) {
            return $this->soapFault('Client', 'Malformed XML');
        }

        // Register SOAP envelope namespace for XPath
        $root->registerXPathNamespace('soap', self::NS_SOAP_ENV);

        // Find the SOAP Body
        $bodyElements = $root->xpath('//soap:Body');
        if (empty($bodyElements)) {
            // Try without namespace prefix (some clients omit it)
            $bodyElements = $root->xpath('//*[local-name()="Body"]');
        }

        if (empty($bodyElements)) {
            return $this->soapFault('Client', 'Missing SOAP Body');
        }

        $body = $bodyElements[0];

        // First child of Body is the operation element
        $children = $body->children();
        if ($children->count() === 0) {
            // Try with the target namespace
            $tns = "urn:{$this->serviceName}";
            $children = $body->children($tns);
        }

        if ($children->count() === 0) {
            return $this->soapFault('Client', 'Empty SOAP Body');
        }

        $opElement = $children[0];

        // Extract operation name (strip namespace prefix)
        $opName = $opElement->getName();

        if (!isset($this->operations[$opName])) {
            return $this->soapFault('Client', "Unknown operation: {$opName}");
        }

        $method = $this->operations[$opName];

        // Extract and convert parameters
        $params = [];
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            $value = null;

            // Try child elements (with and without namespace)
            foreach ($opElement->children() as $child) {
                if ($child->getName() === $paramName) {
                    $value = (string)$child;
                    break;
                }
            }

            if ($value === null) {
                // Try with target namespace
                $tns = "urn:{$this->serviceName}";
                foreach ($opElement->children($tns) as $child) {
                    if ($child->getName() === $paramName) {
                        $value = (string)$child;
                        break;
                    }
                }
            }

            if ($value !== null) {
                $params[$paramName] = $this->convertValue($value, $param->getType());
            } elseif ($param->isDefaultValueAvailable()) {
                $params[$paramName] = $param->getDefaultValue();
            } else {
                $params[$paramName] = null;
            }
        }

        try {
            $result = $method->invokeArgs($this, $params);
            $result = $this->onResult($result);
        } catch (\Throwable $e) {
            return $this->soapFault('Server', $e->getMessage());
        }

        return $this->soapResponse($opName, $result);
    }

    /**
     * Lifecycle hook: called before operation invocation. Override to validate/log.
     */
    public function onRequest(Request $request): void
    {
    }

    /**
     * Lifecycle hook: called after operation returns. Override to transform/audit.
     */
    public function onResult(mixed $result): mixed
    {
        return $result;
    }

    /**
     * Discover public methods marked with #[WSDLOperation].
     */
    private function discoverOperations(): void
    {
        $ref = new \ReflectionClass($this);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited WSDL base class methods
            if ($method->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $attributes = $method->getAttributes(WSDLOperation::class);
            if (empty($attributes)) {
                continue;
            }

            $opName = $method->getName();
            $this->operations[$opName] = $method;
            $this->operationAttributes[$opName] = $attributes[0]->newInstance();
        }
    }

    /**
     * Map a PHP ReflectionType to an XSD type string.
     */
    private function phpTypeToXsd(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'xsd:string';
        }

        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            return self::TYPE_MAP[$name] ?? 'xsd:string';
        }

        // Union types default to string
        return 'xsd:string';
    }

    /**
     * Convert a string value from XML to the target PHP type.
     */
    private function convertValue(string $value, ?\ReflectionType $type): mixed
    {
        if ($type === null || !($type instanceof \ReflectionNamedType)) {
            return $value;
        }

        return match ($type->getName()) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => in_array(strtolower($value), ['true', '1', 'yes'], true),
            default => $value,
        };
    }

    /**
     * Build a SOAP response XML envelope.
     */
    private function soapResponse(string $opName, mixed $result): string
    {
        $parts = [];
        $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $parts[] = '<soap:Envelope xmlns:soap="' . self::NS_SOAP_ENV . '">';
        $parts[] = '<soap:Body>';
        $parts[] = "<{$opName}Response>";

        if (is_array($result)) {
            foreach ($result as $key => $value) {
                if ($value === null) {
                    $parts[] = "<{$key} xsi:nil=\"true\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"/>";
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        $parts[] = "<{$key}>" . $this->escapeXml((string)$item) . "</{$key}>";
                    }
                } elseif (is_bool($value)) {
                    $parts[] = "<{$key}>" . ($value ? 'true' : 'false') . "</{$key}>";
                } else {
                    $parts[] = "<{$key}>" . $this->escapeXml((string)$value) . "</{$key}>";
                }
            }
        }

        $parts[] = "</{$opName}Response>";
        $parts[] = '</soap:Body>';
        $parts[] = '</soap:Envelope>';

        return implode("\n", $parts);
    }

    /**
     * Build a SOAP fault response XML.
     */
    private function soapFault(string $code, string $message): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP_ENV . '">'
            . '<soap:Body>'
            . '<soap:Fault>'
            . '<faultcode>' . $code . '</faultcode>'
            . '<faultstring>' . $this->escapeXml($message) . '</faultstring>'
            . '</soap:Fault>'
            . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * Infer the endpoint URL from the request.
     */
    private function inferEndpointUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = $this->request->path;

        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Escape special XML characters.
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
