<?php declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Tiedostooperaatioiden yksikkötestit
 */
class FileOperationsTest extends TestCase
{
    /* ========================================================================
       data_dir() TESTIT
       ======================================================================== */
    
    public function testDataDirReturnsPath(): void
    {
        $path = data_dir();
        
        $this->assertIsString($path);
        $this->assertStringContainsString('data', $path);
    }
    
    public function testDataDirCreatesDirectory(): void
    {
        $path = data_dir();
        
        $this->assertDirectoryExists($path);
    }
    
    /* ========================================================================
       load_invites() ja save_invites() TESTIT
       ======================================================================== */
    
    public function testSaveAndLoadInvites(): void
    {
        $invites = [
            [
                'id' => 'test-id-1',
                'token' => 'test-token-1',
                'nominee_email' => 'test1@example.com'
            ],
            [
                'id' => 'test-id-2',
                'token' => 'test-token-2',
                'nominee_email' => 'test2@example.com'
            ]
        ];
        
        $saved = save_invites($invites);
        $this->assertTrue($saved);
        
        $loaded = load_invites();
        $this->assertCount(2, $loaded);
        $this->assertEquals('test-id-1', $loaded[0]['id']);
        $this->assertEquals('test2@example.com', $loaded[1]['nominee_email']);
    }
    
    public function testLoadInvitesReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        // Varmista että tiedostoa ei ole
        $path = invites_path();
        if (file_exists($path)) {
            unlink($path);
        }
        
        $invites = load_invites();
        
        $this->assertIsArray($invites);
        $this->assertEmpty($invites);
    }
    
    public function testSaveInvitesCreatesJsonFile(): void
    {
        $invites = [
            ['id' => 'test-1', 'token' => 'abc']
        ];
        
        save_invites($invites);
        
        $path = invites_path();
        $this->assertFileExists($path);
        
        $content = file_get_contents($path);
        $this->assertJson($content);
    }
    
    public function testSaveInvitesPreservesUtf8(): void
    {
        $invites = [
            [
                'id' => 'test-1',
                'nominee_name' => 'Mätti Meikäläinen',
                'referrer_name' => 'Pekka Öykkäri'
            ]
        ];
        
        save_invites($invites);
        $loaded = load_invites();
        
        $this->assertEquals('Mätti Meikäläinen', $loaded[0]['nominee_name']);
        $this->assertEquals('Pekka Öykkäri', $loaded[0]['referrer_name']);
    }
    
    public function testSaveInvitesOverwritesPreviousData(): void
    {
        $invites1 = [['id' => 'first']];
        $invites2 = [['id' => 'second']];
        
        save_invites($invites1);
        save_invites($invites2);
        
        $loaded = load_invites();
        
        $this->assertCount(1, $loaded);
        $this->assertEquals('second', $loaded[0]['id']);
    }
    
    /* ========================================================================
       invite_log() TESTIT
       ======================================================================== */
    
    public function testInviteLogCreatesLogFile(): void
    {
        invite_log('Test message');
        
        $logPath = invite_log_path();
        $this->assertFileExists($logPath);
    }
    
    public function testInviteLogWritesMessage(): void
    {
        $testMessage = 'Test log entry ' . time();
        
        invite_log($testMessage);
        
        $logPath = invite_log_path();
        $content = file_get_contents($logPath);
        
        $this->assertStringContainsString($testMessage, $content);
    }
    
    public function testInviteLogIncludesTimestamp(): void
    {
        invite_log('Test');
        
        $logPath = invite_log_path();
        $content = file_get_contents($logPath);
        
        // Tarkista että sisältää timestampin muodossa [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression(
            '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/',
            $content
        );
    }
    
    public function testInviteLogIncludesIpAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        
        invite_log('Test');
        
        $logPath = invite_log_path();
        $content = file_get_contents($logPath);
        
        $this->assertStringContainsString('192.168.1.1', $content);
    }
    
    public function testInviteLogAppendsToExistingFile(): void
    {
        invite_log('First message');
        invite_log('Second message');
        
        $logPath = invite_log_path();
        $content = file_get_contents($logPath);
        
        $this->assertStringContainsString('First message', $content);
        $this->assertStringContainsString('Second message', $content);
    }
    
    /* ========================================================================
       base_url() TESTIT
       ======================================================================== */
    
    public function testBaseUrlWithHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/test/invite.php';
        
        $url = base_url();
        
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('example.com', $url);
    }
    
    public function testBaseUrlWithHttp(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/test/invite.php';
        
        $url = base_url();
        
        $this->assertStringStartsWith('http://', $url);
    }
    
    public function testBaseUrlIncludesDirectory(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/subfolder/invite.php';
        
        $url = base_url();
        
        $this->assertStringContainsString('/subfolder', $url);
    }
}
