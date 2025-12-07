<?php
/**
 * Testi: Varmistetaan että ohjelma menee automaattisesti UUTEEN tiebreakiin
 * 
 * Testaa että:
 * 1. Ilman ?oldTB1=1 parametria käytetään uutta tiebreakia
 * 2. Uusi tiebreak käyttää kahden vaihtoehdon valintaa
 * 3. Vanha tiebreak aktivoituu vain ?oldTB1=1 parametrilla
 */

// Vaihda työhakemisto ylähakemistoon (missä CSV-tiedostot ovat)
chdir(__DIR__ . '/..');

// Mock $_SERVER variables ENNEN session_start/include - EI ?oldTB1 parametria
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/test'; // Ei ?oldTB1=1
$_GET = []; // Tyhjä GET array

// Suppress output and include main file (joka käynnistää sessionin)
ob_start();
require_once __DIR__ . '/../index.php';
ob_end_clean();

// Clear session after initialization
$_SESSION = [];

echo "\n=== Test: Oletuksena käytetään UUTTA tiebreakia ===\n\n";

// Lataa CSV-data välimuistiin
load_and_cache_quiz_data();

// Lataa erikseen uuden tiebreakn kysymykset (koska ne ovat erillisessä funktiossa)
get_cached_phase1_tiebreak_blocks();

// Tarkista että lataus onnistui
$testBlocks = $_SESSION['cached_tb1_new_blocks'] ?? [];
if (empty($testBlocks)) {
    echo "VIRHE: CSV-tiedosto 'SeparateBlocksQuestionsTiebreak.csv' ei löydy tai on tyhjä!\n";
    echo "Tarkista että tiedosto on olemassa työhakemistossa.\n";
    exit(1);
}
echo "✓ CSV-data ladattu välimuistiin (" . count($testBlocks) . " kysymystä)\n\n";

// ======== Test 1: Ilman parametreja -> uusi tiebreak ========
echo "1. Testaa että ilman parametreja käytetään uutta tiebreakia...\n";

// Set up quiz1 points to create a tie between B1 and C1
$_SESSION['points1'] = [
    'A1' => 10,
    'B1' => 20, // Tied
    'C1' => 20, // Tied
    'D1' => 10
];
$_SESSION['yesCounts1'] = ['A1' => 2, 'B1' => 4, 'C1' => 4, 'D1' => 2];

// Initialize tiebreak (pitäisi mennä uuteen)
handle_quiz1_tie(['B1', 'C1']);

// Check state - pitäisi olla tb1_new_intro (uusi) EI tiebreak_intro (vanha)
if ($_SESSION['state'] === 'tb1_new_intro') {
    echo "   ✓ State on 'tb1_new_intro' (UUSI tiebreak)\n";
} elseif ($_SESSION['state'] === 'tiebreak_intro') {
    echo "   ✗ VIRHE: State on 'tiebreak_intro' (VANHA tiebreak)\n";
    echo "   Ohjelma käyttää vanhaa tiebreakia vaikka pitäisi käyttää uutta!\n";
    exit(1);
} else {
    echo "   ✗ VIRHE: Odottamaton state: " . ($_SESSION['state'] ?? 'null') . "\n";
    exit(1);
}

// Check that new tiebreak questions are loaded
$questions = $_SESSION['tb1_new_questions'] ?? [];
if (count($questions) > 0) {
    echo "   ✓ Uuden tiebreakn kysymykset ladattu: " . count($questions) . " kpl\n";
} else {
    echo "   ✗ VIRHE: Ei kysymyksiä ladattu\n";
    exit(1);
}

// Manually move to tb1_new state to check question format
$_SESSION['state'] = 'tb1_new';

// Check first question structure (uusi tiebreak)
if (count($questions) > 0) {
    $q = $questions[0];
    
    // Uudessa tiebreakissa pitää olla: statement, block_left, option_left, block_right, option_right
    $required_fields = ['statement', 'block_left', 'option_left', 'block_right', 'option_right'];
    $has_all_fields = true;
    
    foreach ($required_fields as $field) {
        if (!isset($q[$field])) {
            echo "   ✗ VIRHE: Kysymyksestä puuttuu kenttä '$field'\n";
            $has_all_fields = false;
        }
    }
    
    if ($has_all_fields) {
        echo "   ✓ Kysymykset ovat oikeassa muodossa (uusi tiebreak)\n";
        echo "      - Statement: " . substr($q['statement'], 0, 50) . "...\n";
        echo "      - Left: " . $q['block_left'] . " - " . substr($q['option_left'], 0, 30) . "...\n";
        echo "      - Right: " . $q['block_right'] . " - " . substr($q['option_right'], 0, 30) . "...\n";
    } else {
        exit(1);
    }
}

// ======== Test 2: ?oldTB1=1 parametrilla -> vanha tiebreak ========
echo "\n2. Testaa että ?oldTB1=1 parametrilla käytetään vanhaa tiebreakia...\n";

// Reset session
$_SESSION = [];
$_SESSION['points1'] = [
    'A1' => 10,
    'B1' => 20,
    'C1' => 20,
    'D1' => 10
];
$_SESSION['yesCounts1'] = ['A1' => 2, 'B1' => 4, 'C1' => 4, 'D1' => 2];

// Set GET parameter
$_GET['oldTB1'] = '1';

// Initialize tiebreak (pitäisi mennä vanhaan)
handle_quiz1_tie(['B1', 'C1']);

// Check state - pitäisi olla tiebreak_intro (vanha)
if ($_SESSION['state'] === 'tiebreak_intro') {
    echo "   ✓ State on 'tiebreak_intro' (VANHA tiebreak) kun ?oldTB1=1\n";
} elseif ($_SESSION['state'] === 'tb1_new_intro') {
    echo "   ✗ VIRHE: State on 'tb1_new_intro' vaikka ?oldTB1=1 asetettu\n";
    exit(1);
} else {
    echo "   ✗ VIRHE: Odottamaton state: " . ($_SESSION['state'] ?? 'null') . "\n";
    exit(1);
}

// Check that old tiebreak questions are loaded
$old_questions = $_SESSION['tiebreak_set'] ?? [];
if (count($old_questions) > 0) {
    echo "   ✓ Vanhan tiebreakn kysymykset ladattu: " . count($old_questions) . " kpl\n";
} else {
    echo "   ✗ VIRHE: Ei kysymyksiä ladattu\n";
    exit(1);
}

// ======== Test 3: Varmistetaan että use_old_tb1 flag toimii ========
echo "\n3. Testaa että use_old_tb1 session-muuttuja toimii...\n";

// Reset and test with session flag
$_SESSION = [];
$_SESSION['use_old_tb1'] = true; // Session flag
$_SESSION['points1'] = [
    'A1' => 10,
    'B1' => 20,
    'C1' => 20,
    'D1' => 10
];
$_SESSION['yesCounts1'] = ['A1' => 2, 'B1' => 4, 'C1' => 4, 'D1' => 2];
$_GET = []; // Ei GET parametria

// Initialize tiebreak (pitäisi mennä vanhaan koska session flag)
handle_quiz1_tie(['B1', 'C1']);

if ($_SESSION['state'] === 'tiebreak_intro') {
    echo "   ✓ Session flag use_old_tb1 toimii - käyttää vanhaa tiebreakia\n";
} else {
    echo "   ✗ VIRHE: Session flag ei toimi, state: " . ($_SESSION['state'] ?? 'null') . "\n";
    exit(1);
}

// ======== Summary ========
echo "\n=== KAIKKI TESTIT LÄPÄISTY ===\n";
echo "✓ Oletuksena käytetään UUTTA tiebreakia\n";
echo "✓ Uusi tiebreak käyttää kahden vaihtoehdon valintaa\n";
echo "✓ Vanha tiebreak aktivoituu ?oldTB1=1 parametrilla\n";
echo "✓ Session flag use_old_tb1 toimii\n";
echo "\nJohtopäätös: Ohjelma menee automaattisesti uuteen tiebreakiin!\n";

exit(0);
