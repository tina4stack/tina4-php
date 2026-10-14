<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Realtime\S3Client;
use Tina4\Realtime\S3Exception;

/**
 * Unit proof of the zero-dependency SigV4 client's signing math — no service,
 * no mocks. The signature is checked against AWS's OWN published reference
 * ("get-vanilla" from the SigV4 test suite); the live-server round-trip (real
 * PUT/GET/presigned/DELETE against MinIO/Garage) lives in RealtimeTest.
 */
final class S3ClientTest extends TestCase
{
    public function testSigV4SignatureMatchesTheAwsGetVanillaVector(): void
    {
        $secret = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
        $dateStamp = '20150830';
        $region = 'us-east-1';
        $service = 'service';
        $amzDate = '20150830T123600Z';

        $canonicalRequest = implode("\n", [
            'GET', '/', '',
            "host:example.amazonaws.com\nx-amz-date:20150830T123600Z\n",
            'host;x-amz-date',
            hash('sha256', ''),
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate,
            "{$dateStamp}/{$region}/{$service}/aws4_request",
            hash('sha256', $canonicalRequest),
        ]);
        $k = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $stringToSign, $k, false);

        $this->assertSame(
            '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signature
        );
    }

    public function testS3ExceptionCarriesTheHttpStatus(): void
    {
        $error = new S3Exception(404, "GET /b/k: HTTP 404");
        $this->assertSame(404, $error->status);
        $this->assertInstanceOf(\RuntimeException::class, $error);
    }

    public function testPresignedUrlCarriesTheSigV4QueryParams(): void
    {
        $client = new S3Client('http://localhost:9100', 'AKID', 'secret', 'us-east-1');
        $url = $client->presignedGetUrl('bucket', 'obj.png', 120);

        $this->assertStringStartsWith('http://localhost:9100/bucket/obj.png?', $url);
        foreach ([
            'X-Amz-Algorithm=AWS4-HMAC-SHA256',
            'X-Amz-Credential=',
            'X-Amz-Date=',
            'X-Amz-Expires=120',
            'X-Amz-SignedHeaders=host',
            'X-Amz-Signature=',
        ] as $param) {
            $this->assertStringContainsString($param, $url);
        }
    }
}
