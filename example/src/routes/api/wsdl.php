<?php

\Tina4\Router::get("/api/soap/orders", function ($request, $response) {
    // Return WSDL definition
    $wsdlXml = '<?xml version="1.0" encoding="UTF-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"
             xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
             xmlns:tns="urn:Tina4Store"
             xmlns:xsd="http://www.w3.org/2001/XMLSchema"
             name="OrderService"
             targetNamespace="urn:Tina4Store">
    <types>
        <xsd:schema targetNamespace="urn:Tina4Store">
            <xsd:element name="PlaceOrderRequest">
                <xsd:complexType>
                    <xsd:sequence>
                        <xsd:element name="customer_id" type="xsd:int"/>
                        <xsd:element name="product_ids" type="xsd:string"/>
                        <xsd:element name="quantities" type="xsd:string"/>
                    </xsd:sequence>
                </xsd:complexType>
            </xsd:element>
            <xsd:element name="PlaceOrderResponse">
                <xsd:complexType>
                    <xsd:sequence>
                        <xsd:element name="order_id" type="xsd:int"/>
                        <xsd:element name="status" type="xsd:string"/>
                    </xsd:sequence>
                </xsd:complexType>
            </xsd:element>
            <xsd:element name="GetOrderStatusRequest">
                <xsd:complexType>
                    <xsd:sequence>
                        <xsd:element name="order_id" type="xsd:int"/>
                    </xsd:sequence>
                </xsd:complexType>
            </xsd:element>
            <xsd:element name="GetOrderStatusResponse">
                <xsd:complexType>
                    <xsd:sequence>
                        <xsd:element name="order_id" type="xsd:int"/>
                        <xsd:element name="status" type="xsd:string"/>
                        <xsd:element name="total" type="xsd:float"/>
                    </xsd:sequence>
                </xsd:complexType>
            </xsd:element>
        </xsd:schema>
    </types>
    <message name="PlaceOrderInput"><part name="parameters" element="tns:PlaceOrderRequest"/></message>
    <message name="PlaceOrderOutput"><part name="parameters" element="tns:PlaceOrderResponse"/></message>
    <message name="GetOrderStatusInput"><part name="parameters" element="tns:GetOrderStatusRequest"/></message>
    <message name="GetOrderStatusOutput"><part name="parameters" element="tns:GetOrderStatusResponse"/></message>
    <portType name="OrderServicePortType">
        <operation name="PlaceOrder">
            <input message="tns:PlaceOrderInput"/>
            <output message="tns:PlaceOrderOutput"/>
        </operation>
        <operation name="GetOrderStatus">
            <input message="tns:GetOrderStatusInput"/>
            <output message="tns:GetOrderStatusOutput"/>
        </operation>
    </portType>
    <binding name="OrderServiceBinding" type="tns:OrderServicePortType">
        <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
        <operation name="PlaceOrder">
            <soap:operation soapAction="urn:Tina4Store#PlaceOrder"/>
            <input><soap:body use="literal"/></input>
            <output><soap:body use="literal"/></output>
        </operation>
        <operation name="GetOrderStatus">
            <soap:operation soapAction="urn:Tina4Store#GetOrderStatus"/>
            <input><soap:body use="literal"/></input>
            <output><soap:body use="literal"/></output>
        </operation>
    </binding>
    <service name="OrderService">
        <port name="OrderServicePort" binding="tns:OrderServiceBinding">
            <soap:address location="/api/soap/orders"/>
        </port>
    </service>
</definitions>';
    return $response($wsdlXml, 200, "text/xml");
})->noAuth();

\Tina4\Router::post("/api/soap/orders", function ($request, $response) {
    $db = \Tina4\App::getDatabase();
    $body = $request->body['raw'] ?? '';

    // Parse SOAP envelope
    if (str_contains($body, 'PlaceOrder')) {
        preg_match('/<customer_id>(\d+)<\/customer_id>/', $body, $custMatch);
        preg_match('/<product_ids>([^<]+)<\/product_ids>/', $body, $pidsMatch);
        preg_match('/<quantities>([^<]+)<\/quantities>/', $body, $qtysMatch);

        $customerId = (int) ($custMatch[1] ?? 0);
        $pids = array_map('intval', explode(',', $pidsMatch[1] ?? ''));
        $qtys = array_map('intval', explode(',', $qtysMatch[1] ?? ''));

        $db->execute("INSERT INTO orders (customer_id, total, status, created_at) VALUES (?, 0, 'pending', ?)", [$customerId, date('c')]);
        $orderId = $db->getLastId();
        $total = 0;

        foreach ($pids as $i => $pid) {
            $qty = $qtys[$i] ?? 1;
            $prodResult = $db->fetch("SELECT price FROM products WHERE id = ?", [$pid], 1, 0);
            $price = ($prodResult && $prodResult->records) ? $prodResult->records[0]['price'] : 0;
            $total += $price * $qty;
            $db->execute("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)", [$orderId, $pid, $qty, $price]);
        }

        $db->execute("UPDATE orders SET total = ? WHERE id = ?", [$total, $orderId]);
        $db->commit();

        $soapResponse = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Body><PlaceOrderResponse xmlns="urn:Tina4Store">
<order_id>' . $orderId . '</order_id><status>pending</status>
</PlaceOrderResponse></soap:Body></soap:Envelope>';
        return $response($soapResponse, 200, "text/xml");
    }

    if (str_contains($body, 'GetOrderStatus')) {
        preg_match('/<order_id>(\d+)<\/order_id>/', $body, $match);
        $orderId = (int) ($match[1] ?? 0);
        $orderResult = $db->fetch("SELECT id, status, total FROM orders WHERE id = ?", [$orderId], 1, 0);
        $order = ($orderResult && $orderResult->records) ? $orderResult->records[0] : null;

        $soapResponse = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Body><GetOrderStatusResponse xmlns="urn:Tina4Store">
<order_id>' . ($order ? $order['id'] : $orderId) . '</order_id>
<status>' . ($order ? $order['status'] : 'not_found') . '</status>
<total>' . ($order ? $order['total'] : 0) . '</total>
</GetOrderStatusResponse></soap:Body></soap:Envelope>';
        return $response($soapResponse, 200, "text/xml");
    }

    return $response("Unknown SOAP operation", 400);
})->noAuth();
