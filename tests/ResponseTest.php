<?php

use PHPUnit\Framework\TestCase;
use Tina4\Response;

class ResponseTest extends TestCase
{
    public function testInvokeWithStringContent()
    {
        $response = new Response();
        $result = $response('Hello World', 200, 'text/html');

        $this->assertEquals('Hello World', $result['content']);
        $this->assertEquals(200, $result['httpCode']);
        $this->assertEquals('text/html', $result['contentType']);
    }

    public function testInvokeWithArrayContent()
    {
        $response = new Response();
        $result = $response(['key' => 'value'], 200, 'application/json');

        $this->assertEquals('{"key":"value"}', $result['content']);
        $this->assertEquals(200, $result['httpCode']);
        $this->assertEquals('application/json', $result['contentType']);
    }

    public function testInvokeWithDefaultContentType()
    {
        $response = new Response();
        $result = $response('Test', 404);

        $this->assertEquals('Test', $result['content']);
        $this->assertEquals(404, $result['httpCode']);
        $this->assertEquals('text/html', $result['contentType']);
    }

    /**
     * @throws \Exception
     */
    public function testRender()
    {
        // Mock \Tina4\renderTemplate to return a fixed string
        if (!function_exists('\Tina4\renderTemplate')) {
            function renderTemplate($template, $data)
            {
                return 'Rendered: ' . $template . ' with data ' . json_encode($data);
            }
        }

        $response = new Response();
        $result = $response->render('index.twig', ['name' => 'Test']);

        $this->assertEquals('Hello Test', $result['content']);
        $this->assertEquals(200, $result['httpCode']);
        $this->assertEquals('text/html', $result['contentType']);
    }

    public function testFileNotFound()
    {
        $response = new Response();
        $result = $response->file('nonexistent.txt', 'src/public');

        $this->assertEquals('File not found', $result['content']);
        $this->assertEquals(404, $result['httpCode']);
        $this->assertEquals('text/html', $result['contentType']);
    }

    public function testFileSuccess()
    {
        // Create a temporary file for testing
        $tempDir = sys_get_temp_dir() . '/tina4_test/';
        mkdir($tempDir);
        $tempFile = $tempDir . 'test.txt';
        file_put_contents($tempFile, 'File content');

        $response = new Response();
        $result = $response->file('test.txt', $tempDir);

        $this->assertEquals('File content', $result['content']);
        $this->assertEquals(200, $result['httpCode']);
        $this->assertEquals('text/plain', $result['contentType']); // Assuming mime_content_type detects as text/plain

        // Cleanup
        unlink($tempFile);
        rmdir($tempDir);
    }

    public function testRedirect()
    {
        $response = new Response();
        $result = $response->redirect('https://example.com', 301);

        $this->assertEquals('', $result['content']);
        $this->assertEquals(301, $result['httpCode']);
        $this->assertEquals('text/html', $result['contentType']);
        $this->assertEquals('https://example.com', $result['customHeaders']['Location']);
    }
}

// Add more test classes for other components like Migration, ORM, etc.

class MigrationTest extends TestCase
{
    // Mock DBA and test methods; this might require more setup like PHPUnit mocks
    public function testCreateMigration()
    {
        $migration = new Migration(sys_get_temp_dir() . '/migrations_test/');
        $result = $migration->createMigration('test_migration', '-- SQL here');

        $this->assertStringContainsString('Migration created', $result);

        // Cleanup: Remove created file if needed
    }

    // Additional tests for doMigration would require a test database setup
}