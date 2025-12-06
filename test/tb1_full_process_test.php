<?php
/**
 * Test 9: Täydellinen tiebreak-prosessi - vastaa kaikkiin kysymyksiin ja määritä voittaja
 */

// Estä HTML-renderöinti
define('TEST_MODE', true);

require_once __DIR__ . '/../index.php';

echo "\n=== Test 9: Täydellinen tiebreak-prosessi ===\n\n";

// Reset session
$_SESSION = [];
$_SESSION['points1'] = ['A1' => 12, 'B1' => 12, 'C1' => 8, 'D1' => 5];
$_SESSION['state'] = 'quiz1';

echo "1. Alustetaan tiebreak B1 vs C1 tie:lle...\n";

// Alusta tiebreak BvsC parille
handle_quiz1_tie_new(['B1', 'C1']);

// Varmista että alustus onnistui
if (($_SESSION['state'] ?? '') !== 'tb1_new' || !isset($_SESSION['tb1_new_questions'])) {
    echo "   ✗ VIRHE: Tiebreak-alustus epäonnistui\n";
    echo "   State: " . ($_SESSION['state'] ?? 'NOT SET') . "\n";
    exit(1);
}

echo "   ✓ Tiebreak alustettu tilaan: " . $_SESSION['state'] . "\n";
echo "   ✓ Kysymyksiä ladattu: " . count($_SESSION['tb1_new_questions']) . " kpl\n";
echo "   ✓ Tiebreak tyyppi: " . ($_SESSION['tb1_new_questions'][0]['blocks_str'] ?? 'N/A') . " (BvsC pari)\n";

echo "\n2. Vastataan 7 kysymykseen...\n";
echo "   Vastaukset: B, C, B, C, B, B, B (B voittaa 5-2)\n\n";

// Vastaa kaikkiin 7 kysymykseen
// B voittaa 5-2
$answers = ['B', 'C', 'B', 'C', 'B', 'B', 'B'];

foreach ($answers as $i => $block) {
    $_POST['tb1_new_choice'] = $block;
    
    echo "   Kysymys " . ($i + 1) . "/7: Valittu blokki $block\n";
    
    // Simuloi vastaus ilman redirect
    ob_start();
    try {
        // Kopioi handle_tb1_new_answer logiikka ilman redirectia
        $index = (int)($_SESSION['tb1_new_index'] ?? 0);
        $questions = $_SESSION['tb1_new_questions'] ?? [];
        
        if ($index >= 0 && $index < count($questions)) {
            $q = $questions[$index];
            
            // Increment block counter
            $_SESSION['tb1_new_counters'][$block] = ($_SESSION['tb1_new_counters'][$block] ?? 0) + 1;
            
            // Save answer
            $_SESSION['tb1_new_answers'][] = $q['question_num'] . $block;
            
            // Move to next question
            $_SESSION['tb1_new_index'] = $index + 1;
            
            // Check if all questions answered
            $totalQuestions = count($_SESSION['tb1_new_questions'] ?? []);
            if ($_SESSION['tb1_new_index'] >= $totalQuestions) {
                echo "   → Kaikki kysymykset vastattu, määritetään voittaja...\n";
                handle_tb1_new_completion();
            }
        }
    } catch (Exception $e) {
        // Ignore
    }
    ob_end_clean();
}

echo "\n3. Tarkistetaan vastaukset...\n";

$final_b = $_SESSION['tb1_new_counters']['B'] ?? 0;
$final_c = $_SESSION['tb1_new_counters']['C'] ?? 0;
$final_a = $_SESSION['tb1_new_counters']['A'] ?? 0;
$final_d = $_SESSION['tb1_new_counters']['D'] ?? 0;

echo "   Lopulliset laskurit:\n";
echo "     - A: $final_a (ei ollut tiebreakissa)\n";
echo "     - B: $final_b ← voittajan pitäisi olla tämä\n";
echo "     - C: $final_c\n";
echo "     - D: $final_d (ei ollut tiebreakissa)\n";

if ($final_b === 5 && $final_c === 2) {
    echo "   ✓ Laskurit oikein: B=5, C=2\n";
} else {
    echo "   ✗ VIRHE: Laskurit väärin (odotettu B=5, C=2, saatiin B=$final_b, C=$final_c)\n";
}

// Tarkista että kaikki 7 vastausta on tallennettu
$answer_count = count($_SESSION['tb1_new_answers'] ?? []);
if ($answer_count === 7) {
    echo "   ✓ Kaikki 7 vastausta tallennettu logia varten\n";
    echo "   Tallennetut vastaukset: " . implode(', ', $_SESSION['tb1_new_answers']) . "\n";
} else {
    echo "   ✗ VIRHE: Vastauksia $answer_count, odotettu 7\n";
}

echo "\n4. Tarkistetaan voittaja...\n";

$winner = $_SESSION['log_row']['class1'] ?? 'NOT SET';
$new_state = $_SESSION['state'] ?? 'NOT SET';
$phase1_tb = $_SESSION['log_row']['Phase1_tb'] ?? 'NOT SET';

echo "   Voittajaksi määritetty: $winner\n";
echo "   Uusi tila: $new_state\n";
echo "   Phase1_tb: $phase1_tb\n";

if ($winner === 'B1') {
    echo "\n   ✓✓✓ TESTI ONNISTUI ✓✓✓\n";
    echo "   B1 voitti tiebreakin (5 pistettä vs C1:n 2 pistettä)\n";
    
    // Tarkista Phase1_tb
    if ($phase1_tb === 'Kyllä') {
        echo "   ✓ Phase1_tb asetettu oikein: Kyllä\n";
    } else {
        echo "   ✗ Varoitus: Phase1_tb on '$phase1_tb', odotettu 'Kyllä'\n";
    }
    
    // Tarkista että state on siirtynyt eteenpäin
    if (in_array($new_state, ['phase2_intro', 'phase2', 'done2'])) {
        echo "   ✓ Tila siirtyi eteenpäin: $new_state\n";
    } else {
        echo "   ⚠ Varoitus: Tila on $new_state (odotettu phase2_intro, phase2 tai done2)\n";
    }
} else {
    echo "\n   ✗✗✗ TESTI EPÄONNISTUI ✗✗✗\n";
    echo "   Voittaja on $winner, odotettu B1\n";
    echo "   Debug info:\n";
    echo "   - Tied classes: " . json_encode($_SESSION['tiebreak_classes'] ?? []) . "\n";
    echo "   - Counters: " . json_encode($_SESSION['tb1_new_counters'] ?? []) . "\n";
    exit(1);
}

echo "\n=== Test 9 suoritettu ===\n";
