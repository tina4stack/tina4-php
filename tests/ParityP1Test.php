<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Api;
use Tina4\Database\Database;

/**
 * v3.13.1 P1 parity tests — three cross-framework convenience additions
 * that the docs already assumed existed:
 *
 *   1. $db->fetchAll($sql, $params)            returns rows[] directly
 *   2. Database::getConnection($url, ...)      classmethod factory
 *   3. new Api($url, bearerToken:, headers:, verifySSL:, ...)  ergonomic kwargs
 *
 * Mirrors the Python tina4_python Group A/B additions shipped in 3.13.0.
 */
final class ParityP1Test extends TestCase
{
    // ─── Database::getConnection() ───────────────────────────────────

    public function testGetConnectionWithExplicitUrl(): void
    {
        $db = Database::getConnection('sqlite::memory:');
        $this->assertInstanceOf(Database::class, $db);
    }

    public function testGetConnectionUsesEnvWhenUrlOmitted(): void
    {
        $prev = getenv('TINA4_DATABASE_URL');
        putenv('TINA4_DATABASE_URL=sqlite::memory:');
        try {
            $db = Database::getConnection();
            $this->assertInstanceOf(Database::class, $db);
        } finally {
            // Restore previous env state — putenv() with no '=' clears it
            if ($prev === false) {
                putenv('TINA4_DATABASE_URL');
            } else {
                putenv("TINA4_DATABASE_URL={$prev}");
            }
        }
    }

    public function testGetConnectionFallsBackToInMemorySQLite(): void
    {
        $prev = getenv('TINA4_DATABASE_URL');
        putenv('TINA4_DATABASE_URL'); // clear
        try {
            $db = Database::getConnection();
            $this->assertInstanceOf(Database::class, $db);
        } finally {
            if ($prev !== false) {
                putenv("TINA4_DATABASE_URL={$prev}");
            }
        }
    }

    // ─── $db->fetchAll() ─────────────────────────────────────────────

    public function testFetchAllReturnsRecordsList(): void
    {
        $db = Database::getConnection('sqlite::memory:');
        $db->execute('CREATE TABLE u (id INTEGER, name TEXT)');
        $db->insert('u', ['id' => 1, 'name' => 'Alice']);
        $db->insert('u', ['id' => 2, 'name' => 'Bob']);

        $rows = $db->fetchAll('SELECT * FROM u ORDER BY id');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function testFetchAllReturnsEmptyArrayWhenNoRows(): void
    {
        $db = Database::getConnection('sqlite::memory:');
        $db->execute('CREATE TABLE u (id INTEGER)');
        $this->assertSame([], $db->fetchAll('SELECT * FROM u'));
    }

    public function testFetchAllSupportsParamsAndPagination(): void
    {
        $db = Database::getConnection('sqlite::memory:');
        $db->execute('CREATE TABLE u (id INTEGER, active INTEGER)');
        for ($i = 0; $i < 10; $i++) {
            $db->insert('u', ['id' => $i, 'active' => $i % 2]);
        }
        $active = $db->fetchAll('SELECT id FROM u WHERE active = ? ORDER BY id', [1], 3);
        $this->assertSame([1, 3, 5], array_column($active, 'id'));
    }

    // ─── Api ergonomic kwargs ────────────────────────────────────────

    public function testApiBearerTokenKwarg(): void
    {
        $api = new Api('https://x.example', bearerToken: 'sk-test123');
        $reflection = new \ReflectionClass($api);
        $authHeaderProp = $reflection->getProperty('authHeader');
        $this->assertSame('Bearer sk-test123', $authHeaderProp->getValue($api));
    }

    public function testApiUsernamePasswordKwargs(): void
    {
        $api = new Api('https://x.example', username: 'u', password: 'p');
        $reflection = new \ReflectionClass($api);
        $authHeaderProp = $reflection->getProperty('authHeader');
        $this->assertStringStartsWith('Basic ', $authHeaderProp->getValue($api));
    }

    public function testApiHeadersKwarg(): void
    {
        $api = new Api('https://x.example', headers: ['X-Tenant' => 'acme', 'X-Trace' => 'abc']);
        $reflection = new \ReflectionClass($api);
        $headersProp = $reflection->getProperty('headers');
        $headers = $headersProp->getValue($api);
        $this->assertSame('acme', $headers['X-Tenant']);
        $this->assertSame('abc', $headers['X-Trace']);
    }

    public function testApiVerifySslFalseDisablesVerification(): void
    {
        $api = new Api('https://x.example', verifySSL: false);
        $reflection = new \ReflectionClass($api);
        $ignoreProp = $reflection->getProperty('ignoreSSL');
        $this->assertTrue($ignoreProp->getValue($api));
    }

    public function testApiIgnoreSslStillWorks(): void
    {
        $api = new Api('https://x.example', ignoreSSL: true);
        $reflection = new \ReflectionClass($api);
        $ignoreProp = $reflection->getProperty('ignoreSSL');
        $this->assertTrue($ignoreProp->getValue($api));
    }

    public function testApiBearerOverridesBasicWhenBothPassed(): void
    {
        $api = new Api('https://x.example', bearerToken: 'tok', username: 'u', password: 'p');
        $reflection = new \ReflectionClass($api);
        $authHeaderProp = $reflection->getProperty('authHeader');
        $this->assertStringStartsWith('Bearer ', $authHeaderProp->getValue($api));
    }

    public function testApiLegacyConstructorStillWorks(): void
    {
        // Old positional form — should not break
        $api = new Api('https://x.example', 'Bearer legacy', 30, false);
        $reflection = new \ReflectionClass($api);
        $authHeaderProp = $reflection->getProperty('authHeader');
        $this->assertSame('Bearer legacy', $authHeaderProp->getValue($api));
    }
}
