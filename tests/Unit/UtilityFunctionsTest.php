<?php declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Apufunktioiden yksikkötestit
 */
class UtilityFunctionsTest extends TestCase
{
    /* ========================================================================
       UUID4 TESTIT
       ======================================================================== */
    
    public function testUuid4ReturnsValidFormat(): void
    {
        $uuid = uuid4();
        
        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        
        $this->assertMatchesRegularExpression($pattern, $uuid);
    }
    
    public function testUuid4ReturnsUniqueValues(): void
    {
        $uuid1 = uuid4();
        $uuid2 = uuid4();
        
        $this->assertNotEquals($uuid1, $uuid2);
    }
    
    public function testUuid4Version4Indicator(): void
    {
        $uuid = uuid4();
        $parts = explode('-', $uuid);
        
        // 3. segmentin pitäisi alkaa '4':llä
        $this->assertStringStartsWith('4', $parts[2]);
    }
    
    /* ========================================================================
       rand_token() TESTIT
       ======================================================================== */
    
    public function testRandTokenReturnsCorrectLength(): void
    {
        $token = rand_token(48);
        
        $this->assertEquals(48, strlen($token));
    }
    
    public function testRandTokenDefaultLength(): void
    {
        $token = rand_token();
        
        $this->assertEquals(TOKEN_LENGTH, strlen($token));
    }
    
    public function testRandTokenContainsOnlyAlphanumeric(): void
    {
        $token = rand_token(100);
        
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $token);
    }
    
    public function testRandTokenReturnsUniqueValues(): void
    {
        $token1 = rand_token();
        $token2 = rand_token();
        
        $this->assertNotEquals($token1, $token2);
    }
    
    /* ========================================================================
       human_code() TESTIT
       ======================================================================== */
    
    public function testHumanCodeReturnsCorrectLength(): void
    {
        $code = human_code(8);
        
        $this->assertEquals(8, strlen($code));
    }
    
    public function testHumanCodeDefaultLength(): void
    {
        $code = human_code();
        
        $this->assertEquals(HUMAN_CODE_LENGTH, strlen($code));
    }
    
    public function testHumanCodeContainsNoConfusingCharacters(): void
    {
        // Testaa että koodissa ei ole I, L, O, 0, 1
        for ($i = 0; $i < 100; $i++) {
            $code = human_code(10);
            
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('L', $code);
            $this->assertStringNotContainsString('O', $code);
            $this->assertStringNotContainsString('0', $code);
            $this->assertStringNotContainsString('1', $code);
        }
    }
    
    public function testHumanCodeIsUpperCase(): void
    {
        $code = human_code(20);
        
        $this->assertEquals(strtoupper($code), $code);
    }
    
    /* ========================================================================
       validate_captcha() TESTIT
       ======================================================================== */
    
    public function testValidateCaptchaWithCorrectAnswer(): void
    {
        $_SESSION['captcha_ans'] = '12';
        
        $result = validate_captcha('12');
        
        $this->assertTrue($result);
    }
    
    public function testValidateCaptchaWithIncorrectAnswer(): void
    {
        $_SESSION['captcha_ans'] = '12';
        
        $result = validate_captcha('15');
        
        $this->assertFalse($result);
    }
    
    public function testValidateCaptchaWithEmptyInput(): void
    {
        $_SESSION['captcha_ans'] = '12';
        
        $result = validate_captcha('');
        
        $this->assertFalse($result);
    }
    
    public function testValidateCaptchaWithoutSession(): void
    {
        unset($_SESSION['captcha_ans']);
        
        $result = validate_captcha('12');
        
        $this->assertFalse($result);
    }
    
    public function testValidateCaptchaTrimsWhitespace(): void
    {
        $_SESSION['captcha_ans'] = '12';
        
        $result = validate_captcha('  12  ');
        
        $this->assertTrue($result);
    }
    
    /* ========================================================================
       create_invite_record() TESTIT
       ======================================================================== */
    
    public function testCreateInviteRecordReturnsValidStructure(): void
    {
        $nominee = [
            'name' => 'Matti Meikäläinen',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $invite = create_invite_record('Pekka Kutsuja', $nominee);
        
        $this->assertIsArray($invite);
        $this->assertArrayHasKey('id', $invite);
        $this->assertArrayHasKey('token', $invite);
        $this->assertArrayHasKey('code', $invite);
        $this->assertArrayHasKey('referrer_name', $invite);
        $this->assertArrayHasKey('nominee_name', $invite);
        $this->assertArrayHasKey('nominee_email', $invite);
        $this->assertArrayHasKey('guess_type', $invite);
        $this->assertArrayHasKey('guess_prob', $invite);
        $this->assertArrayHasKey('status', $invite);
        $this->assertArrayHasKey('created_at', $invite);
    }
    
    public function testCreateInviteRecordSetsCorrectValues(): void
    {
        $nominee = [
            'name' => 'Matti Meikäläinen',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'very_certain'
        ];
        
        $invite = create_invite_record('Pekka Kutsuja', $nominee);
        
        $this->assertEquals('Pekka Kutsuja', $invite['referrer_name']);
        $this->assertEquals('Matti Meikäläinen', $invite['nominee_name']);
        $this->assertEquals('matti@example.com', $invite['nominee_email']);
        $this->assertEquals('4', $invite['guess_type']);
        $this->assertEquals('very_certain', $invite['guess_prob']);
        $this->assertEquals('sent', $invite['status']);
    }
    
    public function testCreateInviteRecordGeneratesUniqueTokens(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $invite1 = create_invite_record('Pekka', $nominee);
        $invite2 = create_invite_record('Pekka', $nominee);
        
        $this->assertNotEquals($invite1['id'], $invite2['id']);
        $this->assertNotEquals($invite1['token'], $invite2['token']);
        $this->assertNotEquals($invite1['code'], $invite2['code']);
    }
    
    public function testCreateInviteRecordHasValidTimestamp(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $invite = create_invite_record('Pekka', $nominee);
        
        // Tarkista että timestamp on oikeassa formaatissa
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $invite['created_at']
        );
    }
    
    /* ========================================================================
       h() (HTML escape) TESTIT
       ======================================================================== */
    
    public function testHtmlEscapeBasicXss(): void
    {
        $result = h('<script>alert("xss")</script>');
        
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
    
    public function testHtmlEscapeQuotes(): void
    {
        $result = h('"test" and \'test\'');
        
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&#039;', $result);
    }
    
    public function testHtmlEscapeAmpersand(): void
    {
        $result = h('Test & Test');
        
        $this->assertStringContainsString('&amp;', $result);
    }
    
    /* ========================================================================
       ensure_captcha() ja reset_captcha() TESTIT
       ======================================================================== */
    
    public function testEnsureCaptchaCreatesQuestion(): void
    {
        unset($_SESSION['captcha_q'], $_SESSION['captcha_ans']);
        
        ensure_captcha();
        
        $this->assertArrayHasKey('captcha_q', $_SESSION);
        $this->assertArrayHasKey('captcha_ans', $_SESSION);
    }
    
    public function testEnsureCaptchaQuestionFormat(): void
    {
        unset($_SESSION['captcha_q'], $_SESSION['captcha_ans']);
        
        ensure_captcha();
        
        $this->assertMatchesRegularExpression('/^\d+ \+ \d+ = \?$/', $_SESSION['captcha_q']);
    }
    
    public function testEnsureCaptchaAnswerIsCorrect(): void
    {
        unset($_SESSION['captcha_q'], $_SESSION['captcha_ans']);
        
        ensure_captcha();
        
        // Parse question
        preg_match('/^(\d+) \+ (\d+) = \?$/', $_SESSION['captcha_q'], $matches);
        $expectedAnswer = (int)$matches[1] + (int)$matches[2];
        
        $this->assertEquals((string)$expectedAnswer, $_SESSION['captcha_ans']);
    }
    
    public function testResetCaptchaCreatesNewQuestion(): void
    {
        ensure_captcha();
        $oldQuestion = $_SESSION['captcha_q'];
        
        reset_captcha();
        
        $this->assertNotEquals($oldQuestion, $_SESSION['captcha_q']);
    }
}
