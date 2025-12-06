<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case
 * 
 * Yhteinen pohja kaikille testeille
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Tyhjennä session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        
        // Tyhjennä $_POST ja $_GET
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        // Tyhjennä test data
        $this->cleanTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanTestData();
        parent::tearDown();
    }
    
    protected function cleanTestData(): void
    {
        if (defined('TEST_DATA_DIR') && is_dir(TEST_DATA_DIR)) {
            $files = glob(TEST_DATA_DIR . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Assertoi että array sisältää avaimen jolla on tietty arvo
     */
    protected function assertArrayContainsKeyValue(array $array, string $key, $value): void
    {
        $this->assertArrayHasKey($key, $array);
        $this->assertEquals($value, $array[$key]);
    }
    
    /**
     * Assertoi että merkkijono sisältää tekstin (case-insensitive)
     */
    protected function assertContainsStringIgnoringCase(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertStringContainsString(
            strtolower($needle),
            strtolower($haystack),
            $message
        );
    }
}
