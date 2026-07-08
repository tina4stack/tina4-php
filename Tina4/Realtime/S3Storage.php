<?php

namespace Tina4\Realtime;

/**
 * S3-compatible store (AWS S3, MinIO, ...). Opt-in; needs the AWS SDK
 * (aws/aws-sdk-php). Presigned GET URLs let clients fetch large blobs straight
 * from object storage instead of streaming through the app.
 *
 * Constructed only when TINA4_STORAGE_BACKEND=s3; Storage::select() falls back
 * to LocalStorage (with a logged warning) if the SDK is missing or the config
 * is incomplete — a real store, never a silent no-op (mirrors Python S3Storage).
 */
class S3Storage implements StorageBackend
{
    private object $client;
    private string $bucket;

    public function __construct(
        ?string $endpoint = null,
        ?string $key = null,
        ?string $secret = null,
        ?string $bucket = null,
        ?string $region = null
    ) {
        $clientClass = 'Aws\\S3\\S3Client';
        if (!class_exists($clientClass)) {
            throw new \RuntimeException('S3Storage requires aws/aws-sdk-php');
        }
        $this->bucket = $bucket ?: (getenv('TINA4_STORAGE_BUCKET') ?: '');
        if ($this->bucket === '') {
            throw new \InvalidArgumentException('S3Storage requires TINA4_STORAGE_BUCKET');
        }
        $config = [
            'version' => 'latest',
            'region' => $region ?: (getenv('TINA4_STORAGE_REGION') ?: 'us-east-1'),
            'credentials' => [
                'key' => $key ?: getenv('TINA4_STORAGE_KEY'),
                'secret' => $secret ?: getenv('TINA4_STORAGE_SECRET'),
            ],
        ];
        $url = $endpoint ?: getenv('TINA4_STORAGE_URL');
        if ($url) {
            $config['endpoint'] = $url;
            $config['use_path_style_endpoint'] = true;   // MinIO / S3-compatible
        }
        $this->client = new $clientClass($config);
    }

    public function put(string $key, string $data, string $mime = 'application/octet-stream'): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket, 'Key' => $key, 'Body' => $data, 'ContentType' => $mime,
        ]);
    }

    public function get(string $key): ?string
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return (string)$result['Body'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function url(string $key, int $ttl = 3600): ?string
    {
        $cmd = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        return (string)$this->client->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (\Throwable $e) {
            \Tina4\Log::error("S3Storage delete failed for {$key}: " . $e->getMessage());
        }
    }

    public function exists(string $key): bool
    {
        try {
            return (bool)$this->client->doesObjectExist($this->bucket, $key);
        } catch (\Throwable) {
            return false;
        }
    }
}
