<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use DOMDocument;
use ReflectionMethod;
use SimpleXMLElement;

/**
 * Tina4 PHP – Built-in SOAP 1.1 / WSDL 1.0 Service
 *
 * Zero-configuration SOAP web service with automatic WSDL generation.
 * Works seamlessly with Tina4 routing and respects reverse proxies.
 *
 * Features:
 *     • Auto-generates full WSDL on ?wsdl
 *     • Document/literal wrapped style
 *     • Full support for string, int, float, bool, array<T>, ?T
 *     • Automatic ArrayOfX complex types
 *     • Proper xsi:nil handling
 *     • X-Forwarded-* header support
 *     • Optional hooks: onRequest / onResult
 *     • Optional static SERVICE_URL override
 *
 * Usage example at the bottom of this file.
 */
abstract class WSDL
{

    protected const RESERVED_METHODS = ["xsdType", "convertParam", "registerArrayType", "addFieldsToSequence", "getOperations"];

    /**
     * Basic XSD type mapping – can be extended in subclasses if needed
     */
    protected const XSD_TYPES = [
        "string" => "xsd:string",
        "int" => "xsd:int",
        "float" => "xsd:double",
        "bool" => "xsd:boolean",
    ];

    /**
     * Optional class-level override for the service endpoint (useful behind proxies)
     */
    public const SERVICE_URL = null;

    /**
     * Declare the exact structure of the SOAP responses for methods.
     *
     * Example:
     *     protected array $returnSchemas = [
     *         "Login" => [
     *             "SessionId" => "string",
     *             "Expires" => "string",
     *             "Roles" => "array<string>",
     *             "Error" => "?string"
     *         ]
     *     ];
     *
     * Supported types:
     *     string, int, float, bool
     *     array<T> or T[]     → generates <ArrayOfX> complex type
     *     ?T                  → minOccurs="0" + nillable="true"
     */
    protected array $returnSchemas = [];

    protected Request $request;

    private array $arrayTypes = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Auto-convert incoming SOAP strings to proper PHP types
     */
    protected function convertParam(mixed $value, string $type): mixed
    {
        // Handle optional
        if (str_starts_with($type, "?")) {
            $type = substr($type, 1);
            if ($value === null) {
                return null;
            }
        }

        // Handle arrays
        $isArray = false;
        $itemType = $type;

        if (str_ends_with($type, "[]")) {
            $isArray = true;
            $itemType = substr($type, 0, -2);
        } elseif (str_starts_with($type, "array<") && str_ends_with($type, ">")) {
            $isArray = true;
            $itemType = substr($type, 6, -1);
        }

        if ($isArray) {
            if (!is_array($value)) {
                $value = [$value];
            }
            return array_map(fn($v) => $this->convertParam($v, $itemType), $value);
        }

        // Scalar types
        return match ($type) {
            "int" => (int)$value,
            "float" => (float)$value,
            "bool" => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * Return list of callable public methods that are not excluded.
     */
    public function getOperations(): array
    {
        $methods = get_class_methods($this);
        $excluded = [
            "__construct", "handle", "generateWsdl", "soapFault",
            "getOperations", "onRequest", "onResult"
        ];
        $operations = [];
        foreach ($methods as $method) {
            if (!in_array($method, $excluded) && !str_starts_with($method, "_")) {
                $operations[] = $method;
            }
        }
        return $operations;
    }

    /**
     * Convert a PHP type to an XSD type string.
     */
    protected function xsdType(string $type): string
    {
        // Strip optional/nullable
        if (str_starts_with($type, "?")) {
            $type = substr($type, 1);
        }

        // Strip array
        if (str_ends_with($type, "[]")) {
            $type = substr($type, 0, -2);
        } elseif (str_starts_with($type, "array<") && str_ends_with($type, ">")) {
            $type = substr($type, 6, -1);
        }

        return self::XSD_TYPES[$type] ?? "xsd:string";
    }

    /**
     * Register an ArrayOfX complex type if not already done.
     * Returns the qualified type name, e.g. tns:ArrayOfString
     */
    protected function registerArrayType(\DOMElement $schema, string $itemType): string
    {
        $itemXsd = $this->xsdType($itemType);
        $base = ucfirst(str_replace("xsd:", "", $itemXsd));
        $arrayName = "ArrayOf{$base}";

        if (in_array($arrayName, $this->arrayTypes)) {
            return "tns:{$arrayName}";
        }

        $complex = $schema->ownerDocument->createElement("xsd:complexType");
        $schema->appendChild($complex);
        $complex->setAttribute("name", $arrayName);

        $seq = $schema->ownerDocument->createElement("xsd:sequence");
        $complex->appendChild($seq);

        $elem = $schema->ownerDocument->createElement("xsd:element");
        $seq->appendChild($elem);
        $elem->setAttribute("name", "item");
        $elem->setAttribute("type", $itemXsd);
        $elem->setAttribute("minOccurs", "0");
        $elem->setAttribute("maxOccurs", "unbounded");
        $elem->setAttribute("nillable", "true");

        $this->arrayTypes[] = $arrayName;
        return "tns:{$arrayName}";
    }

    /**
     * Populate an <xsd:sequence> with elements defined by a ['name' => 'type'] schema array.
     * Handles array<T> / T[] and ?T correctly.
     */
    protected function addFieldsToSequence(\DOMElement $sequence, array $schema, \DOMElement $schemaElem): void
    {
        foreach ($schema as $fieldName => $fieldType) {
            $minOccurs = "1";
            $nillable = "false";
            $origType = $fieldType;

            if (str_starts_with($fieldType, "?")) {
                $minOccurs = "0";
                $nillable = "true";
                $fieldType = substr($fieldType, 1);
            }

            $isArray = false;
            if (str_ends_with($fieldType, "[]")) {
                $isArray = true;
                $itemType = substr($fieldType, 0, -2);
            } elseif (str_starts_with($fieldType, "array<") && str_ends_with($fieldType, ">")) {
                $isArray = true;
                $itemType = substr($fieldType, 6, -1);
            } else {
                $itemType = $fieldType;
            }

            $xsdType = $this->xsdType($itemType);
            if ($isArray) {
                $xsdType = $this->registerArrayType($schemaElem, $itemType);
            }

            $elem = $sequence->ownerDocument->createElement("xsd:element");
            $sequence->appendChild($elem);
            $elem->setAttribute("name", $fieldName);
            $elem->setAttribute("type", $xsdType);
            $elem->setAttribute("minOccurs", $minOccurs);
            $elem->setAttribute("nillable", $nillable);
        }
    }

    /**
     * Generate and return the complete WSDL document as a string.
     * Called automatically when ?wsdl is present in the query string.
     */
    public function generateWsdl(): string
    {
        $serviceName = get_class($this);
        $serviceName = str_replace("\\", "/" , $serviceName);

        $tns = "http://tempuri.org/" . strtolower($serviceName);

        // Determine location
        if (defined("static::SERVICE_URL") && static::SERVICE_URL) {
            $location = rtrim(static::SERVICE_URL, "/");
        } else {
            $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? $_SERVER["HTTP_X_FORWARDED_PROTOCOL"] ?? "http";
            $host = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? $_SERVER["HTTP_HOST"] ?? "";
            $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?? "";
            $location = "{$proto}://{$host}{$path}";
        }

        $doc = new DOMDocument("1.0", "utf-8");
        $definitions = $doc->createElement("definitions");
        $doc->appendChild($definitions);
        $definitions->setAttribute("name", $serviceName);
        $definitions->setAttribute("targetNamespace", $tns);
        $definitions->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns", "http://schemas.xmlsoap.org/wsdl/");
        $definitions->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:tns", $tns);
        $definitions->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:soap", "http://schemas.xmlsoap.org/wsdl/soap/");
        $definitions->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:xsd", "http://www.w3.org/2001/XMLSchema");

        // <types>
        $types = $doc->createElement("types");
        $definitions->appendChild($types);
        $schema = $doc->createElement("xsd:schema");
        $types->appendChild($schema);
        $schema->setAttribute("targetNamespace", $tns);
        $schema->setAttribute("elementFormDefault", "qualified");

        // Build request & response types for every operation
        $operations = $this->getOperations();
        foreach ($operations as $op) {
            if (in_array($op, self::RESERVED_METHODS)) {
              continue;
            }

            $method = new ReflectionMethod($this, $op);

            // Request message
            $reqElem = $doc->createElement("xsd:element");
            $schema->appendChild($reqElem);
            $reqElem->setAttribute("name", $op);
            $reqComplex = $doc->createElement("xsd:complexType");
            $reqElem->appendChild($reqComplex);
            $reqSeq = $doc->createElement("xsd:sequence");
            $reqComplex->appendChild($reqSeq);

            $params = $method->getParameters();
            if (empty($params)) {
                $dummy = $doc->createElement("xsd:element");
                $reqSeq->appendChild($dummy);
                $dummy->setAttribute("name", "dummy");
                $dummy->setAttribute("type", "xsd:string");
                $dummy->setAttribute("minOccurs", "0");
            } else {
                foreach ($params as $param) {
                    $name = $param->getName();
                    $type = $param->getType() ? $param->getType()->getName() : "string";
                    $minOccurs = $param->allowsNull() ? "0" : "1";
                    $nillable = $param->allowsNull() ? "true" : "false";
                    $xsdType = self::XSD_TYPES[$type] ?? "xsd:string";

                    $isArray = false;
                    $itemType = $type;
                    $docType = null;

                    $docComment = $method->getDocComment();
                    if ($docComment) {
                        if (preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($name, '/') . '/', $docComment, $matches)) {
                            $docType = $matches[1];
                        }
                    }

                    $paramType = $docType ?? $type;

                    if (str_ends_with($paramType, "[]")) {
                        $isArray = true;
                        $itemType = substr($paramType, 0, -2);
                    } elseif (str_starts_with($paramType, "array<") && str_ends_with($paramType, ">")) {
                        $isArray = true;
                        $itemType = substr($paramType, 6, -1);
                    }

                    if ($isArray) {
                        $xsdType = $this->registerArrayType($schema, $itemType);
                    }

                    $elem = $doc->createElement("xsd:element");
                    $reqSeq->appendChild($elem);
                    $elem->setAttribute("name", $name);
                    $elem->setAttribute("type", $xsdType);
                    $elem->setAttribute("minOccurs", $minOccurs);
                    $elem->setAttribute("nillable", $nillable);
                }
            }

            // Response message
            $respElem = $doc->createElement("xsd:element");
            $schema->appendChild($respElem);
            $respElem->setAttribute("name", $op . "Response");
            $respComplex = $doc->createElement("xsd:complexType");
            $respElem->appendChild($respComplex);
            $respSeq = $doc->createElement("xsd:sequence");
            $respComplex->appendChild($respSeq);

            $resultWrapper = $doc->createElement("xsd:element");
            $respSeq->appendChild($resultWrapper);
            $resultWrapper->setAttribute("name", $op . "Result");

            $returnSchema = $this->returnSchemas[$op] ?? null;
            if ($returnSchema) {
                $wrapperComplex = $doc->createElement("xsd:complexType");
                $resultWrapper->appendChild($wrapperComplex);
                $wrapperSeq = $doc->createElement("xsd:sequence");
                $wrapperComplex->appendChild($wrapperSeq);
                $this->addFieldsToSequence($wrapperSeq, $returnSchema, $schema);
            } else {
                // Fallback for undecorated methods
                $wrapperComplex = $doc->createElement("xsd:complexType");
                $resultWrapper->appendChild($wrapperComplex);
                $wrapperSeq = $doc->createElement("xsd:sequence");
                $wrapperComplex->appendChild($wrapperSeq);
                $item = $doc->createElement("xsd:element");
                $wrapperSeq->appendChild($item);
                $item->setAttribute("name", "item");
                $item->setAttribute("type", "xsd:string");
                $item->setAttribute("minOccurs", "0");
                $item->setAttribute("maxOccurs", "unbounded");
            }
        }

        // <message>
        foreach ($operations as $op) {

            if (in_array($op, self::RESERVED_METHODS)) {
                continue;
            }

            $reqMsg = $doc->createElement("message");
            $definitions->appendChild($reqMsg);
            $reqMsg->setAttribute("name", $op . "Request");
            $part = $doc->createElement("part");
            $reqMsg->appendChild($part);
            $part->setAttribute("name", "parameters");
            $part->setAttribute("element", "tns:" . $op);

            $respMsg = $doc->createElement("message");
            $definitions->appendChild($respMsg);
            $respMsg->setAttribute("name", $op . "Response");
            $part = $doc->createElement("part");
            $respMsg->appendChild($part);
            $part->setAttribute("name", "parameters");
            $part->setAttribute("element", "tns:" . $op . "Response");
        }

        // <portType>
        $portType = $doc->createElement("portType");
        $definitions->appendChild($portType);
        $portType->setAttribute("name", $serviceName . "PortType");

        foreach ($operations as $op) {

            if (in_array($op, self::RESERVED_METHODS)) {
                continue;
            }

            $operation = $doc->createElement("operation");
            $portType->appendChild($operation);
            $operation->setAttribute("name", $op);

            $input = $doc->createElement("input");
            $operation->appendChild($input);
            $input->setAttribute("message", "tns:" . $op . "Request");

            $output = $doc->createElement("output");
            $operation->appendChild($output);
            $output->setAttribute("message", "tns:" . $op . "Response");
        }

        // <binding>
        $binding = $doc->createElement("binding");
        $definitions->appendChild($binding);
        $binding->setAttribute("name", $serviceName . "Binding");
        $binding->setAttribute("type", "tns:" . $serviceName . "PortType");

        $soapBinding = $doc->createElement("soap:binding");
        $binding->appendChild($soapBinding);
        $soapBinding->setAttribute("style", "document");
        $soapBinding->setAttribute("transport", "http://schemas.xmlsoap.org/soap/http");

        foreach ($operations as $op) {

            if (in_array($op, self::RESERVED_METHODS)) {
                continue;
            }

            $operation = $doc->createElement("operation");
            $binding->appendChild($operation);
            $operation->setAttribute("name", $op);

            $soapOp = $doc->createElement("soap:operation");
            $operation->appendChild($soapOp);
            $soapOp->setAttribute("soapAction", $tns . "#" . $op);

            $input = $doc->createElement("input");
            $operation->appendChild($input);
            $soapBody = $doc->createElement("soap:body");
            $input->appendChild($soapBody);
            $soapBody->setAttribute("use", "literal");

            $output = $doc->createElement("output");
            $operation->appendChild($output);
            $soapBody = $doc->createElement("soap:body");
            $output->appendChild($soapBody);
            $soapBody->setAttribute("use", "literal");
        }

        // <service>
        $service = $doc->createElement("service");
        $definitions->appendChild($service);
        $service->setAttribute("name", $serviceName . "Service");

        $port = $doc->createElement("port");
        $service->appendChild($port);
        $port->setAttribute("name", $serviceName . "Port");
        $port->setAttribute("binding", "tns:" . $serviceName . "Binding");

        $address = $doc->createElement("soap:address");
        $port->appendChild($address);
        $address->setAttribute("location", $location);

        $doc->formatOutput = true;
        return $doc->saveXML();
    }

    /**
     * SOAP Request Handling
     */
    public function handle(): string
    {
        if (isset($_GET["wsdl"])) {
            return $this->generateWsdl();
        }

        try {
            $input = file_get_contents("php://input");
            $doc = new DOMDocument();
            $doc->loadXML($input);
            $bodies = $doc->getElementsByTagNameNS("http://schemas.xmlsoap.org/soap/envelope/", "Body");
            $body = $bodies->item(0);
            if (!$body || $body->childNodes->length === 0) {
                throw new \Exception("No SOAP Body");
            }

            $operationElem = $body->firstChild;
            while ($operationElem && $operationElem->nodeType !== XML_ELEMENT_NODE) {
                $operationElem = $operationElem->nextSibling;
            }
            if (!$operationElem) {
                throw new \Exception("No operation element in SOAP Body");
            }
            $operationName = $operationElem->localName;

            $args = [];
            $child = $operationElem->firstChild;
            while ($child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $key = $child->localName;
                    $value = null;
                    $hasChildren = false;
                    $items = [];
                    $subChild = $child->firstChild;
                    while ($subChild) {
                        if ($subChild->nodeType === XML_ELEMENT_NODE) {
                            $hasChildren = true;
                            $itemKey = $subChild->localName;
                            $itemValue = trim($subChild->nodeValue);
                            if ($subChild->getAttributeNS("http://www.w3.org/2001/XMLSchema-instance", "nil") === "true") {
                                $itemValue = null;
                            }
                            if ($itemKey === "item") {
                                $items[] = $itemValue;
                            } else {
                                // If not 'item', treat as flat structure
                                $value = $itemValue;
                            }
                        } elseif ($subChild->nodeType === XML_TEXT_NODE) {
                            $value = trim($subChild->nodeValue);
                        }
                        $subChild = $subChild->nextSibling;
                    }

                    if ($child->getAttributeNS("http://www.w3.org/2001/XMLSchema-instance", "nil") === "true") {
                        $value = null;
                    }

                    if ($hasChildren && !empty($items)) {
                        $value = $items;
                    } elseif (!$hasChildren && $value === "") {
                        $value = null;
                    }

                    if (isset($args[$key])) {
                        if (!is_array($args[$key])) {
                            $args[$key] = [$args[$key]];
                        }
                        $args[$key][] = $value;
                    } else {
                        $args[$key] = $value;
                    }
                }
                $child = $child->nextSibling;
            }

            $method = new ReflectionMethod($this, $operationName);
            $params = $method->getParameters();

            $kwargs = [];
            foreach ($params as $param) {
                $name = $param->getName();
                if (isset($args[$name])) {
                    $type = $param->getType() ? $param->getType()->getName() : "string";
                    $docType = null;
                    $docComment = $method->getDocComment();
                    if ($docComment) {
                        if (preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($name, '/') . '/', $docComment, $matches)) {
                            $docType = $matches[1];
                        }
                    }
                    $paramType = $docType ?? $type;
                    $kwargs[$name] = $this->convertParam($args[$name], $paramType);
                } elseif ($param->isDefaultValueAvailable()) {
                    $kwargs[$name] = $param->getDefaultValue();
                } else {
                    throw new \Exception("Missing required parameter: {$name}");
                }
            }

            if (method_exists($this, "onRequest")) {
                $this->onRequest($this->request);
            }

            $result = $method->invokeArgs($this, $kwargs);

            if (method_exists($this, "onResult")) {
                $result = $this->onResult($result);
            }

            // Build correct SOAP response
            $respDoc = new DOMDocument("1.0", "utf-8");
            $envelope = $respDoc->createElementNS("http://schemas.xmlsoap.org/soap/envelope/", "soapenv:Envelope");
            $respDoc->appendChild($envelope);
            $envelope->setAttributeNS("http://www.w3.org/2000/xmlns/", "xmlns:tns", "http://tempuri.org/" . strtolower(get_class($this)));

            $bodyEl = $respDoc->createElementNS("http://schemas.xmlsoap.org/soap/envelope/", "soapenv:Body");
            $envelope->appendChild($bodyEl);

            $resp = $respDoc->createElement($operationName . "Response");
            $bodyEl->appendChild($resp);

            $resultEl = $respDoc->createElement($operationName . "Result");
            $resp->appendChild($resultEl);

            foreach ($result ?? [] as $key => $value) {
                if (is_array($value)) {
                    $arrayEl = $respDoc->createElement($key);
                    $resultEl->appendChild($arrayEl);
                    foreach ($value as $item) {
                        $el = $respDoc->createElement("item");
                        $arrayEl->appendChild($el);
                        if ($item === null) {
                            $el->setAttributeNS("http://www.w3.org/2001/XMLSchema-instance", "xsi:nil", "true");
                        } else {
                            $el->nodeValue = (string)$item;
                        }
                    }
                } else {
                    $el = $respDoc->createElement($key);
                    $resultEl->appendChild($el);
                    if ($value === null) {
                        $el->setAttributeNS("http://www.w3.org/2001/XMLSchema-instance", "xsi:nil", "true");
                    } else {
                        $el->nodeValue = (string)$value;
                    }
                }
            }

            $respDoc->formatOutput = true;
            return $respDoc->saveXML();

        } catch (\Exception $e) {
            error_log($e->getTraceAsString());
            return $this->soapFault($e->getMessage());
        }
    }

    /**
     * Return a minimal but valid SOAP Fault.
     */
    protected function soapFault(string $message): string
    {
        $doc = new DOMDocument("1.0", "utf-8");
        $env = $doc->createElementNS("http://schemas.xmlsoap.org/soap/envelope/", "Envelope");
        $doc->appendChild($env);

        $body = $doc->createElementNS("http://schemas.xmlsoap.org/soap/envelope/", "Body");
        $env->appendChild($body);

        $fault = $doc->createElementNS("http://schemas.xmlsoap.org/soap/envelope/", "Fault");
        $body->appendChild($fault);

        $faultcode = $doc->createElement("faultcode", "Server");
        $fault->appendChild($faultcode);

        $faultstring = $doc->createElement("faultstring", $message);
        $fault->appendChild($faultstring);

        return $doc->saveXML();
    }
}