<?php declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Validointifunktioiden yksikkötestit
 */
class ValidationTest extends TestCase
{
    /* ========================================================================
       validate_single_nominee() TESTIT
       ======================================================================== */
    
    public function testValidateSingleNomineeWithValidData(): void
    {
        $nominee = [
            'name' => 'Matti Meikäläinen',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti Meikäläinen');
        
        $this->assertEmpty($errors, 'Validilla datalla ei pitäisi olla virheitä');
    }
    
    public function testValidateSingleNomineeWithEmptyName(): void
    {
        $nominee = [
            'name' => '',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, '');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Anna nimi', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithInvalidEmail(): void
    {
        $nominee = [
            'name' => 'Matti Meikäläinen',
            'email' => 'not-an-email',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti Meikäläinen');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Sähköpostiosoite on virheellinen', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithMissingAtSign(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'mattiexample.com',
            'guess_type' => '4',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('virheellinen', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithInvalidGuessType(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'matti@example.com',
            'guess_type' => '0', // Invalid
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Valitse tyyli 1–9', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithGuessType10(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'matti@example.com',
            'guess_type' => '10', // Invalid
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Valitse tyyli 1–9', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithInvalidProbability(): void
    {
        $nominee = [
            'name' => 'Matti',
            'email' => 'matti@example.com',
            'guess_type' => '4',
            'guess_prob' => 'invalid_option'
        ];
        
        $errors = validate_single_nominee($nominee, 'Matti');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Valitse varmuustaso', $errors[0]);
    }
    
    public function testValidateSingleNomineeWithMultipleErrors(): void
    {
        $nominee = [
            'name' => '',
            'email' => 'invalid-email',
            'guess_type' => '',
            'guess_prob' => ''
        ];
        
        $errors = validate_single_nominee($nominee, '');
        
        $this->assertGreaterThanOrEqual(4, count($errors), 'Pitäisi olla 4 virhettä');
    }
    
    public function testValidateSingleNomineeUsesNomineeNameInErrors(): void
    {
        $nominee = [
            'name' => '',
            'email' => 'test@example.com',
            'guess_type' => '5',
            'guess_prob' => 'quite_certain'
        ];
        
        $errors = validate_single_nominee($nominee, 'Liisa Virtanen');
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Liisa Virtanen', $errors[0]);
    }
    
    /* ========================================================================
       validate_form_data() TESTIT
       ======================================================================== */
    
    public function testValidateFormDataWithValidData(): void
    {
        $data = [
            'ref_name' => 'Pekka Kutsuja',
            'ref_note' => 'Tervetuloa testiin!',
            'nominees' => [
                [
                    'name' => 'Matti',
                    'email' => 'matti@example.com',
                    'guess_type' => '4',
                    'guess_prob' => 'quite_certain'
                ]
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertEmpty($errors);
    }
    
    public function testValidateFormDataWithEmptyRefName(): void
    {
        $data = [
            'ref_name' => '',
            'ref_note' => 'Test',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Anna kutsujan nimi', $errors[0]);
    }
    
    public function testValidateFormDataWithNoNominees(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Test',
            'nominees' => []
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vähintään yksi kutsuttava', $errors[0]);
    }
    
    public function testValidateFormDataWithMissingNomineesKey(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Test'
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('vähintään yksi kutsuttava', $errors[0]);
    }
    
    public function testValidateFormDataWithTooLongNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => str_repeat('a', NOTE_MAX_LEN + 1),
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('liian pitkät', $errors[0]);
    }
    
    public function testValidateFormDataWithHttpUrlInNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Katso lisää http://example.com',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('linkkejä', $errors[0]);
    }
    
    public function testValidateFormDataWithHttpsUrlInNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Katso https://example.com tästä',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('linkkejä', $errors[0]);
    }
    
    public function testValidateFormDataWithWwwUrlInNote(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => 'Katso www.example.com',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('linkkejä', $errors[0]);
    }
    
    public function testValidateFormDataStripsHtmlTags(): void
    {
        $data = [
            'ref_name' => 'Pekka',
            'ref_note' => '<script>alert("xss")</script>Tervetuloa',
            'nominees' => [
                ['name' => 'Matti', 'email' => 'matti@example.com', 'guess_type' => '4', 'guess_prob' => 'quite_certain']
            ]
        ];
        
        $errors = validate_form_data($data);
        
        // Ei pitäisi olla virhettä koska HTML poistetaan
        $this->assertEmpty($errors);
    }
}
