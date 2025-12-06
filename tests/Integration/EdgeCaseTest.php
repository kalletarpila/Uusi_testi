<?php declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Edge case -testit
 * 
 * Testaa erikoistilanteet, rajatapaukset ja turvallisuusriskit
 */
class EdgeCaseTest extends TestCase
{
    /* ========================================================================
       MAKSIMIRAJOITUKSET
       ======================================================================== */
    
    public function testMaximum10Nominees(): void
    {
        $nominees = [];
        for ($i = 1; $i <= 10; $i++) {
            $nominees[] = [
                'name' => "Person $i",
                'email' => "person$i@example.com",
                'guess_type' => (string)(($i % 9) + 1),
                'guess_prob' => 'quite_certain'
            ];
        }
        
        $_POST = [
            'ref_name' => 'Tester',
            'ref_note' => 'Test',
            'nominees' => $nominees
        ];
        
        $errors = validate_form_data($_POST);
        $this->assertEmpty($errors, '10 kutsuttavaa pitäisi olla OK');
        
        // Testaa että kaikki validoituvat
        $validCount = 0;
        foreach ($nominees as $nominee) {
            $nomineeErrors = validate_single_nominee($nominee, $nominee['name']);
            if (empty($nomineeErrors)) {
                $validCount++;
            }
        }
        
        $this->assertEquals(10, $validCount);
    }
    
    public function testMoreThan10NomineesShouldBeHandledByFrontend(): void
    {
        // Backend ei estä yli 10, koska JavaScript estää
        // Mutta testaamme että backend kyllä käsittelee ne
        $nominees = [];
        for ($i = 1; $i <= 15; $i++) {
            $nominees[] = [
                'name' => "Person $i",
                'email' => "person$i@example.com",
                'guess_type' => '4',
                'guess_prob' => 'quite_certain'
            ];
        }
        
        // Backend käsittelee kaikki (frontend rajaus)
        $validCount = 0;
        foreach ($nominees as $nominee) {
            $errors = validate_single_nominee($nominee, $nominee['name']);
            if (empty($errors)) {
                $validCount++;
            }
        }
        
        $this->assertEquals(15, $validCount);
    }
    
    /* ========================================================================
       XSS-HYÖKKÄYKSET
       ======================================================================== */
    
    public function testXssInRefName(): void
    {
        $xssPayload = '<script>alert("xss")</script>';
        
        $nominee = [
            'name' => 'Test',
            'email' => 'test@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $invite = create_invite_record($xssPayload, $nominee);
        
        // Tarkista että HTML on escapoitu tulostuksessa
        $escaped = h($invite['referrer_name']);
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }
    
    public function testXssInNomineeName(): void
    {
        $nominee = [
            'name' => '<img src=x onerror=alert(1)>',
            'email' => 'test@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $invite = create_invite_record('Pekka', $nominee);
        $escaped = h($invite['nominee_name']);
        
        $this->assertStringNotContainsString('<img', $escaped);
        $this->assertStringContainsString('&lt;img', $escaped);
    }
    
    public function testXssInRefNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => '<script>document.cookie</script>Tervetuloa!',
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        // HTML tagit pitäisi poistaa validoinnissa
        $this->assertEmpty($errors);
    }
    
    public function testSqlInjectionInEmail(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => "test@example.com'; DROP TABLE users; --",
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        // Pitäisi hylätä koska ei ole validi email
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('virheellinen', $errors[0]);
    }
    
    /* ========================================================================
       PITKÄT MERKKIJONOT
       ======================================================================== */
    
    public function testVeryLongName(): void
    {
        $longName = str_repeat('a', 1000);
        
        $nominee = [
            'name' => $longName,
            'email' => 'test@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, $longName);
        
        // Ei rajaa nimelle, joten ei virhettä
        $this->assertEmpty($errors);
    }
    
    public function testVeryLongEmail(): void
    {
        $longLocal = str_repeat('a', 64);
        $longDomain = str_repeat('b', 63) . '.com';
        $email = $longLocal . '@' . $longDomain;
        
        $nominee = [
            'name' => 'Test',
            'email' => $email,
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        // Pitäisi validoitua (RFC sallii pitkät emailit)
        // Mutta MX-tarkistus voi epäonnistua
        $this->assertTrue(
            empty($errors) || 
            (isset($errors[0]) && str_contains($errors[0], 'MX'))
        );
    }
    
    public function testRefNoteExactlyAtMaxLength(): void
    {
        $note = str_repeat('a', NOTE_MAX_LEN);
        
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => $note,
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertEmpty($errors, 'Tasan max pituudessa ei pitäisi olla virhettä');
    }
    
    public function testRefNoteOneCharOverMax(): void
    {
        $note = str_repeat('a', NOTE_MAX_LEN + 1);
        
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => $note,
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('liian pitkät', $errors[0]);
    }
    
    /* ========================================================================
       ERIKOISMERKIT JA UNICODE
       ======================================================================== */
    
    public function testUnicodeInName(): void
    {
        $nominee = [
            'name' => 'Mätti Meikäläinen 中文 العربية',
            'email' => 'test@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, $nominee['name']);
        
        $this->assertEmpty($errors);
    }
    
    public function testEmojiInRefNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Tervetuloa testiin! 😊🎉👍',
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertEmpty($errors);
    }
    
    public function testNewlinesInRefNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => "Rivi 1\nRivi 2\r\nRivi 3",
            'nominees' => [
                ['name' => 'Test', 'email' => 'test@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertEmpty($errors);
    }
    
    /* ========================================================================
       TYHJÄT JA NULL-ARVOT
       ======================================================================== */
    
    public function testEmptyStringValues(): void
    {
        $nominee = [
            'name' => '',
            'email' => '',
            'guess_type' => '',
            'guess_prob' => ''
        ];
        
        $errors = validate_single_nominee($nominee, '');
        
        // Pitäisi olla virhe jokaisesta kentästä
        $this->assertGreaterThanOrEqual(4, count($errors));
    }
    
    public function testWhitespaceOnlyValues(): void
    {
        $nominee = [
            'name' => '   ',
            'email' => '  ',
            'guess_type' => ' ',
            'guess_prob' => ' '
        ];
        
        $errors = validate_single_nominee($nominee, '');
        
        // Trim pitäisi poistaa välilyönnit, joten virheitä
        $this->assertNotEmpty($errors);
    }
    
    public function testMissingArrayKeys(): void
    {
        $nominee = []; // Ei avaimia ollenkaan
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        // Pitäisi antaa virheet kaikista puuttuvista
        $this->assertGreaterThanOrEqual(4, count($errors));
    }
    
    /* ========================================================================
       EMAIL-ERIKOISTAPAUKSET
       ======================================================================== */
    
    public function testEmailWithPlusSign(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'test+tag@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        // + on sallittu emailissa
        $this->assertTrue(
            empty($errors) || 
            (isset($errors[0]) && str_contains($errors[0], 'MX'))
        );
    }
    
    public function testEmailWithDots(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'first.last@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        $this->assertTrue(
            empty($errors) || 
            (isset($errors[0]) && str_contains($errors[0], 'MX'))
        );
    }
    
    public function testEmailWithMultipleAtSigns(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'test@@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('virheellinen', $errors[0]);
    }
    
    public function testEmailWithoutDomain(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'test@',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        $this->assertNotEmpty($errors);
    }
    
    /* ========================================================================
       GUESS_TYPE RAJATAPAUKSET
       ======================================================================== */
    
    public function testAllValidGuessTypes(): void
    {
        for ($type = 1; $type <= 9; $type++) {
            $nominee = [
                'name' => 'Test',
                'email' => 'test@example.com',
                'guess_type' => (string)$type,
                'guess_prob' => 'quite_certain'
            ];
            
            $errors = validate_single_nominee($nominee, 'Test');
            
            $this->assertEmpty($errors, "Tyyppi $type pitäisi olla validi");
        }
    }
    
    public function testGuessTypeWithLeadingZero(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'test@example.com',
            'guess_type' => '04',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        // Regex vaatii täsmälleen [1-9], joten 04 ei kelpaa
        $this->assertNotEmpty($errors);
    }
    
    public function testGuessTypeNegativeNumber(): void
    {
        $nominee = [
            'name' => 'Test',
            'email' => 'test@example.com',
            'guess_type' => '-1',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Test');
        
        $this->assertNotEmpty($errors);
    }
}
