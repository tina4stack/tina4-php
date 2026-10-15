<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tina4\Realtime;

/**
 * Zero-dependency S3 client (AWS Signature Version 4) on the PHP standard
 * library. Covers exactly what the realtime {@see S3Storage} backend needs —
 * PUT, GET, DELETE, HEAD, and a presigned GET URL — so the `s3` backend no
 * longer needs aws/aws-sdk-php (and the transitive packages it carries).
 *
 * Path-style addressing (`<endpoint>/<bucket>/<key>`), which S3-compatible
 * servers (MinIO, Garage) require and AWS accepts; with no endpoint it targets
 * `https://s3.<region>.amazonaws.com`. SigV4 is HMAC-SHA256 canonical signing
 * — the same primitive Tina4's JWT already uses (core `hash_hmac`); nothing
 * here is reinvented crypto. Mirrors the Python master, proven against AWS's
 * published SigV4 test vector.
 */
class S3Client
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const SERVICE = 's3';
    // Retry policy, as for the AI client (ADR-0053): HTTP 429, any 5xx (S3's
    // SlowDown is a 503) and a dropped connection are sent again, a bounded
    // number of times, honouring Retry-After. Every operation here is idempotent.
    private const MAX_RETRIES = 2;
    private const BACKOFF_BASE = 0.5;   // seconds; retry n waits up to base * 2**n, jittered
    private const BACKOFF_CAP = 20.0;

    private string $scheme;
    private string $host;
    private string $prefix; // path an endpoint carries (https://host/s3 -> "/s3"), '' if none
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private int $timeout;

    public function __construct(
        ?string $endpoint = null,
        string $accessKey = '',
        string $secretKey = '',
        string $region = 'us-east-1',
        int $timeout = 30
    ) {
        $this->region = $region ?: 'us-east-1';
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->timeout = $timeout;
        $base = rtrim($endpoint ?: "https://s3.{$this->region}.amazonaws.com", '/');
        $parts = parse_url($base);
        $this->scheme = $parts['scheme'] ?? 'https';
        $this->host = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $this->host .= ':' . $parts['port'];
        }
        // An endpoint behind a path (https://host/s3) keeps it in front of every
        // request path, and in what is signed.
        $this->prefix = rtrim($parts['path'] ?? '', '/');
    }

    // -- public API (the subset S3Storage uses) --------------------------------

    public function putObject(string $bucket, string $key, string $data, string $contentType = 'application/octet-stream'): void
    {
        $this->request('PUT', $this->canonicalUri($bucket, $key), $data, ['content-type' => $contentType]);
    }

    public function getObject(string $bucket, string $key): string
    {
        [, $body] = $this->request('GET', $this->canonicalUri($bucket, $key), '');
        return $body;
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $this->request('DELETE', $this->canonicalUri($bucket, $key), '');
    }

    public function headObject(string $bucket, string $key): void
    {
        $this->request('HEAD', $this->canonicalUri($bucket, $key), '');
    }

    /** Create the bucket (path-style `PUT /<bucket>`); an already-owned bucket (409) is success. */
    public function createBucket(string $bucket): void
    {
        try {
            $this->request('PUT', $this->prefix . '/' . self::uriEncode($bucket, true), '');
        } catch (S3Exception $e) {
            if ($e->status !== 409) {
                throw $e;
            }
        }
    }

    /** A URL that fetches the object directly, signed via SigV4 query params. */
    public function presignedGetUrl(string $bucket, string $key, int $expires = 3600): string
    {
        $this->requireCredentials();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $canonicalUri = $this->canonicalUri($bucket, $key);
        $scope = "{$dateStamp}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => "{$this->accessKey}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQs = $this->canonicalQuery($query);
        $canonicalRequest = implode("\n", [
            'GET', $canonicalUri, $canonicalQs, "host:{$this->host}\n", 'host', 'UNSIGNED-PAYLOAD',
        ]);
        $signature = $this->sign($canonicalRequest, $amzDate, $dateStamp, $scope);
        $canonicalQs .= '&X-Amz-Signature=' . $signature;
        return "{$this->scheme}://{$this->host}{$canonicalUri}?{$canonicalQs}";
    }

    // -- signing + transport ----------------------------------------------------

    private function canonicalUri(string $bucket, string $key): string
    {
        return $this->prefix . '/' . self::uriEncode($bucket, true) . '/' . self::uriEncode($key, true);
    }

    /**
     * Signing with an empty key only earns a 403 from the server, which says
     * nothing about the cause. Name the settings instead, before sending.
     */
    private function requireCredentials(): void
    {
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new S3ConfigException(
                'S3 credentials are not set: set TINA4_STORAGE_KEY and TINA4_STORAGE_SECRET'
            );
        }
    }

    /** Seconds from a Retry-After header in its delta-seconds form, else null. */
    private static function retryAfterSeconds(array $headers): ?float
    {
        foreach ($headers as $line) {
            if (preg_match('#^retry-after:\s*(\d+(?:\.\d+)?)\s*$#i', $line, $m)) {
                return max(0.0, (float)$m[1]);
            }
        }
        return null;
    }

    /**
     * SigV4 canonical encoding: every byte outside the RFC 3986 unreserved set
     * becomes %XX; "/" is kept in a path and encoded in a query. rawurlencode
     * already leaves -_.~ literal and uses %20 for space, matching AWS.
     */
    private static function uriEncode(string $value, bool $isPath): string
    {
        $encoded = rawurlencode($value);
        return $isPath ? str_replace('%2F', '/', $encoded) : $encoded;
    }

    private function canonicalQuery(array $query): string
    {
        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = self::uriEncode($k, false) . '=' . self::uriEncode($v, false);
        }
        return implode('&', $pairs);
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function sign(string $canonicalRequest, string $amzDate, string $dateStamp, string $scope): string
    {
        $stringToSign = implode("\n", [
            self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest),
        ]);
        return hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);
    }

    /**
     * Sign and send, retrying a transient failure (429, any 5xx, or a dropped
     * connection = status 0) twice at most, honouring Retry-After; a final 4xx
     * is never retried. Each attempt is signed afresh (x-amz-date moves on).
     *
     * @return array{0:int,1:string} status and body
     * @throws S3Exception on a non-2xx final response
     * @throws S3ConfigException when credentials are not set
     */
    private function request(string $method, string $canonicalUri, string $body, array $extraHeaders = []): array
    {
        $this->requireCredentials();
        for ($attempt = 0; ; $attempt++) {
            $last = $attempt === self::MAX_RETRIES;
            $delay = null;
            try {
                return $this->send($method, $canonicalUri, $body, $extraHeaders);
            } catch (S3Exception $e) {
                // status 0 = transport failure (no response), treated as a drop.
                $transient = $e->status === 0 || $e->status === 429 || $e->status >= 500;
                if ($last || !$transient) {
                    throw $e;
                }
                $delay = $e->retryAfter;
            }
            if ($delay === null) {
                $delay = (mt_rand(0, 1000) / 1000.0) * (self::BACKOFF_BASE * (2 ** $attempt));
            }
            usleep((int)(min($delay, self::BACKOFF_CAP) * 1_000_000));
        }
    }

    /**
     * @return array{0:int,1:string} status and body
     * @throws S3Exception on a non-2xx response or a transport failure (status 0)
     */
    private function send(string $method, string $canonicalUri, string $body, array $extraHeaders = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $payloadHash = hash('sha256', $body);

        $signed = [
            'host' => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        ksort($signed);
        $canonicalHeaders = '';
        foreach ($signed as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
        }
        $signedHeaders = implode(';', array_keys($signed));
        $canonicalRequest = implode("\n", [
            $method, $canonicalUri, '', $canonicalHeaders, $signedHeaders, $payloadHash,
        ]);
        $scope = "{$dateStamp}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $signature = $this->sign($canonicalRequest, $amzDate, $dateStamp, $scope);
        $authorization = self::ALGORITHM . " Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headerLines = [
            "Authorization: {$authorization}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$amzDate}",
        ];
        foreach ($extraHeaders as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => in_array($method, ['PUT', 'POST'], true) ? $body : '',
            'timeout' => $this->timeout,
            'ignore_errors' => true,   // read the body/status on 4xx/5xx instead of warning
            'follow_location' => 0,
        ]]);
        $url = "{$this->scheme}://{$this->host}{$canonicalUri}";
        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = $this->statusFromHeaders($headers);
        // No status line AND no body => the connection failed (refused, reset,
        // timed out): nothing was answered. Report it as status 0 so the retry
        // loop treats it as a dropped connection, not a 4xx.
        if ($status === 0) {
            $error = new S3Exception(0, "{$method} {$canonicalUri}: connection failed");
            throw $error;
        }
        if ($status < 200 || $status >= 300) {
            $error = new S3Exception($status, "{$method} {$canonicalUri}: HTTP {$status}");
            $error->retryAfter = self::retryAfterSeconds($headers);
            throw $error;
        }
        return [$status, $response === false ? '' : $response];
    }

    /** First status line in $http_response_header, e.g. "HTTP/1.1 200 OK" -> 200. */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];   // keep the LAST status line (after redirects, if any)
            }
        }
        return $status ?? 0;
    }
}
