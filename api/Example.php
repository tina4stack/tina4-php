<?php
use Nowakowskir\JWT\JWT;

/**
 * @description A swagger annotation
 * @tags Testing AUTH
 * @example AUTH
 * @secure
 */
\Tina4\Get::add("/api/auth-example", function (\Tina4\Response $response, \Tina4\Request $request) {

    $json = (object)["hello" => "World!"];

    return $response ($json, HTTP_OK, APPLICATION_JSON);
});


/**
 * Function to generate Auth key
 */
\Tina4\Get::add("/test-token", function(){
    global $auth;
    //$auth = \Tina4\Auth();
    $privateKey = "9sk7-TxMTH46uKc_5.PL3kZfcx~LSBe4gs";
    $payload = array(
        "ver" => "0.1.0",
        "type" => "embed",
        "wcn" => "76e90e1e-55a3-4057-8268-1508184d458a",
        "wid" => "cb3d123f-5321-432d-bb93-da69c6cf6628",
        "rid" => "b2fc8f62-0e72-466b-bfdf-3516d25cfdbc",
        "iss" => "PowerBISDK",
        "aud" => "https://analysis.windows.net/powerbi/api",
        "exp" => time()+60*60,
        "nbf" => time()
    );

    $token = \Firebase\JWT\JWT::encode($payload , $privateKey, 'HS256');


    $payload = \Firebase\JWT\JWT::decode($token , $privateKey, ['HS256']);

    print_r ($payload);
});