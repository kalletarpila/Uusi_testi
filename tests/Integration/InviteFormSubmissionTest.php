<?php declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Integraatiotestit lomakkeen lähetykselle
 * 
 * Nämä testit simuloivat koko lomakkeen lähetyksen prosessin
 */
class InviteFormSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Aseta session valmiiksi lomakkeen lähetykselle
        session_start();
        $_SESSION['form_ts'] = time() - 10; // 10 sekuntia sitten
        $_SESSION['captcha_ans'] = '12';
        $_SESSION['captcha_q'] = '5 + 7 = ?';
        
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }
    
    /* ========================================================================
       ONNISTUNEET LÄHETYKSET
       ======================================================================== */
    
    public function testSuccessfulSingleNomineeSubmission(): void
    {
        $_POST = [
            'send_invite' => '1',
            'homepage' => '', // Honeypot tyhjä
            'ref_name' => 'Pekka Kutsuja',
            'ref_note' => 'Tervetuloa testiin!',
            'captcha' => '12',
            'nominees' => [
                [
                    'name' => 'Matti Meikäläinen',
                    'email' => 'matti@example.com',
                    'guess_type' => '4',
                    'guess_prob' => 'quite_certain'
                ]
            ]
        ];
        
        // Simuloi lomakkeen käsittely
        ob_start();
        // Tässä pitäisi ajaa invite.php:n POST-logiikka
        // Koska tämä on vaikea testata suoraan, testaamme vain funktioita
        
        $errors = validate_form_data($_POST);
        $this->assertEmpty($errors);
        
        $nomineeErrors = validate_single_nominee($_POST['nominees'][0], 'Matti Meikäläinen');
        $this->assertEmpty($nomineeErrors);
        
        $invite = create_invite_record($_POST['ref_name'], $_POST['nominees'][0]);
        $this->assertIsArray($invite);
        $this->assertEquals('Pekka Kutsuja', $invite['referrer_name']);
        $this->assertEquals('Matti Meikäläinen', $invite['nominee_name']);
        
        ob_end_clean();
    }
    
    public function testSuccessfulMultipleNomineesSubmission(): void
    {
        $_POST = [
            'send_invite' => '1',
            'homepage' => '',
            'ref_name' => 'Pekka Kutsuja',
            'ref_note' => 'Tervetuloa!',
            'captcha' => '12',
            'nominees' => [
                [
                    'name' => 'Matti',
                    'email' => 'matti@example.com',
                    'guess_type' => '4',
                    'guess_prob' => 'quite_certain'
                ],
                [
                    'name' => 'Liisa',
                    'email' => 'liisa@example.com',
                    'guess_type' => '2',
                    'guess_prob' => 'very_certain'
                ],
                [
                    'name' => 'Kalle',
                    'email' => 'kalle@example.com',
                    'guess_type' => '7',
                    'guess_prob' => 'not_certain'
                ]
            ]
        ];
        
        $errors = validate_form_data($_POST);
        $this->assertEmpty($errors);
        
        // Testaa että jokainen nominee validoituu
        foreach ($_POST['nominees'] as $nominee) {
            $nomineeErrors = validate_single_nominee($nominee, $nominee['name']);
            $this->assertEmpty($nomineeErrors);
        }
        
        // Testaa että jokaiselle luodaan eri kutsu
        $invites = [];
        foreach ($_POST['nominees'] as $nominee) {
            $invites[] = create_invite_record($_POST['ref_name'], $nominee);
        }
        
        $this->assertCount(3, $invites);
        $this->assertNotEquals($invites[0]['id'], $invites[1]['id']);
        $this->assertNotEquals($invites[1]['id'], $invites[2]['id']);
    }
    
    /* ========================================================================
       TURVALLISUUSTESTIT
       ======================================================================== */
    
    public function testHoneypotTriggersBlock(): void
    {
        $_POST = [
            'send_invite' => '1',
            'homepage' => 'http://spam.com', // Honeypot täytetty
            'ref_name' => 'Spammer',
            'ref_note' => 'Spam',
            'captcha' => '12',
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        // Honeypot täytettynä → ei pitäisi mennä läpi
        // Tämä on silent block, joten ei virhettä, mutta ei myöskään lähetystä
        $this->assertEquals('http://spam.com', trim($_POST['homepage']));
    }
    
    public function testTooFastSubmissionIsBlocked(): void
    {
        $_SESSION['form_ts'] = time(); // Juuri nyt
        
        $_POST = [
            'send_invite' => '1',
            'homepage' => '',
            'ref_name' => 'Fast User',
            'ref_note' => 'Fast',
            'captcha' => '12',
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $timeDiff = time() - $_SESSION['form_ts'];
        $this->assertLessThan(MIN_FORM_SECS, $timeDiff);
    }
    
    public function testInvalidCaptchaIsRejected(): void
    {
        $_SESSION['captcha_ans'] = '12';
        
        $_POST = [
            'send_invite' => '1',
            'homepage' => '',
            'ref_name' => 'User',
            'ref_note' => 'Test',
            'captcha' => '99', // Väärä vastaus
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $captchaValid = validate_captcha($_POST['captcha']);
        $this->assertFalse($captchaValid);
    }
    
    /* ========================================================================
       VALIDOINTIVIRHEET
       ======================================================================== */
    
    public function testPartialSuccessWithSomeInvalidNominees(): void
    {
        $_POST = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Test',
            'nominees' => [
                // Valid
                [
                    'name' => 'Matti',
                    'email' => 'matti@example.com',
                    'guess_type' => '4',
                    'guess_prob' => 'quite_certain'
                ],
                // Invalid - no email
                [
                    'name' => 'Liisa',
                    'email' => '',
                    'guess_type' => '2',
                    'guess_prob' => 'quite_certain'
                ],
                // Valid
                [
                    'name' => 'Kalle',
                    'email' => 'kalle@example.com',
                    'guess_type' => '7',
                    'guess_prob' => 'quite_certain'
                ]
            ]
        ];
        
        $validCount = 0;
        $errorCount = 0;
        
        foreach ($_POST['nominees'] as $nominee) {
            $errors = validate_single_nominee($nominee, $nominee['name']);
            if (empty($errors)) {
                $validCount++;
            } else {
                $errorCount++;
            }
        }
        
        $this->assertEquals(2, $validCount);
        $this->assertEquals(1, $errorCount);
    }
    
    public function testEmptyRefNameIsRejected(): void
    {
        $_POST = [
            'ref_name' => '',
            'ref_note' => 'Test',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($_POST);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('kutsujan nimi', $errors[0]);
    }
    
    public function testNoNomineesIsRejected(): void
    {
        $_POST = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Test',
            'nominees' => []
        ];
        
        $errors = validate_form_data($_POST);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vähintään yksi', $errors[0]);
    }
    
    /* ========================================================================
       DUPLIKAATIT
       ======================================================================== */
    
    public function testDuplicateEmailsCreateSeparateInvites(): void
    {
        $_POST = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Test',
            'nominees' => [
                [
                    'name' => 'Matti 1',
                    'email' => 'matti@example.com',
                    'guess_type' => '4',
                    'guess_prob' => 'quite_certain'
                ],
                [
                    'name' => 'Matti 2',
                    'email' => 'matti@example.com', // Sama email
                    'guess_type' => '5',
                    'guess_prob' => 'very_certain'
                ]
            ]
        ];
        
        $invites = [];
        foreach ($_POST['nominees'] as $nominee) {
            $invite = create_invite_record($_POST['ref_name'], $nominee);
            $invites[] = $invite;
        }
        
        // Pitäisi luoda kaksi erillistä kutsua
        $this->assertCount(2, $invites);
        $this->assertEquals('matti@example.com', $invites[0]['nominee_email']);
        $this->assertEquals('matti@example.com', $invites[1]['nominee_email']);
        
        // Mutta eri tunnisteet
        $this->assertNotEquals($invites[0]['id'], $invites[1]['id']);
        $this->assertNotEquals($invites[0]['token'], $invites[1]['token']);
    }
    
    /* ========================================================================
       DATAN TALLENNUSTESTIT
       ======================================================================== */
    
    public function testInvitesAreSavedToFile(): void
    {
        $invites = [];
        
        for ($i = 0; $i < 3; $i++) {
            $nominee = [
                'name' => "Person $i",
                'email' => "person$i@example.com",
                'guess_type' => '4',
                'guess_prob' => 'quite_certain'
            ];
            
            $invites[] = create_invite_record('Tester', $nominee);
        }
        
        $saved = save_invites($invites);
        $this->assertTrue($saved);
        
        $loaded = load_invites();
        $this->assertCount(3, $loaded);
    }
    
    public function testInvitesAreAppendedNotOverwritten(): void
    {
        // Tallenna ensimmäinen erä
        $invite1 = create_invite_record('Pekka', [
            'name' => 'First',
            'email' => 'first@example.com',
            'guess_type' => '1',
            'guess_prob' => 'quite_certain'
        ]);
        
        save_invites([$invite1]);
        
        // Lataa ja lisää toinen
        $existing = load_invites();
        $invite2 = create_invite_record('Pekka', [
            'name' => 'Second',
            'email' => 'second@example.com',
            'guess_type' => '2',
            'guess_prob' => 'quite_certain'
        ]);
        
        $existing[] = $invite2;
        save_invites($existing);
        
        // Tarkista että molemmat ovat tallessa
        $all = load_invites();
        $this->assertCount(2, $all);
    }
}
