<?php
/**
 * Testit uudelle Phase 1 tiebreak-toiminnallisuudelle
 */

// Vaihda työhakemisto juureen, jotta CSV-tiedostot löytyvät
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../index.php';

echo "<h2>tb1_new_test.php</h2><pre>\n";

// Reset session
$_SESSION = [];
session_start();

// Test 1: CSV lataus
echo "Test 1: CSV-tiedoston lataus\n";
$blocks = load_phase1_tiebreak_blocks('SeparateBlocksQuestionsTiebreak.csv');
if (count($blocks) === 42) {
    echo "✓ Ladattu 42 kysymystä (kaikki 6 lohkoparia)\n";
    echo "  Ensimmäinen kysymys: " . substr($blocks[0]['statement'] ?? '', 0, 50) . "...\n";
    echo "  Block left: " . ($blocks[0]['block_left'] ?? 'N/A') . "\n";
    echo "  Block right: " . ($blocks[0]['block_right'] ?? 'N/A') . "\n";
} else {
    echo "✗ VIRHE: Ladattu " . count($blocks) . " kysymystä, odotettu 42\n";
}

// Test 2: Cache-funktio
echo "\nTest 2: Cache-funktio\n";
$_SESSION = [];
$cached = get_cached_phase1_tiebreak_blocks();
if (count($cached) === 42) {
    echo "✓ Cache palauttaa 42 kysymystä\n";
} else {
    echo "✗ VIRHE: Cache palauttaa " . count($cached) . " kysymystä\n";
}

// Test 3: Tiebreak-alustus
echo "\nTest 3: Tiebreak-alustus\n";
$_SESSION = [];
$_SESSION['points1'] = ['A1' => 10, 'B1' => 10, 'C1' => 5];
handle_quiz1_tie_new(['B1', 'C1']); // BvsC pari

if (($_SESSION['state'] ?? '') === 'tb1_new') {
    echo "✓ Tila asetettu: tb1_new\n";
} else {
    echo "✗ VIRHE: Tila on " . ($_SESSION['state'] ?? 'NOT SET') . "\n";
}

$questionCount = count($_SESSION['tb1_new_questions'] ?? []);
if (isset($_SESSION['tb1_new_questions']) && $questionCount === 7) {
    echo "✓ Kysymykset alustettu (7 kpl BvsC parille)\n";
    // Tarkista että kaikki kysymykset ovat BvsC tai CvsB
    $allCorrect = true;
    foreach ($_SESSION['tb1_new_questions'] as $q) {
        if (!in_array($q['blocks_str'], ['BvsC', 'CvsB'])) {
            $allCorrect = false;
            break;
        }
    }
    if ($allCorrect) {
        echo "✓ Kaikki kysymykset ovat oikeaa blokkiparia\n";
    } else {
        echo "✗ VIRHE: Kysymyksissä vääriä blokkipareja\n";
    }
} else {
    echo "✗ VIRHE: Kysymyksiä $questionCount (odotettu 7)\n";
}

if (($_SESSION['tb1_new_index'] ?? -1) === 0) {
    echo "✓ Index alustettu: 0\n";
} else {
    echo "✗ VIRHE: Index on " . ($_SESSION['tb1_new_index'] ?? 'NOT SET') . "\n";
}

$counters = $_SESSION['tb1_new_counters'] ?? [];
if ($counters === ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]) {
    echo "✓ Laskurit alustettu\n";
} else {
    echo "✗ VIRHE: Laskurit: " . json_encode($counters) . "\n";
}

// Test 4: Vastauksen käsittely
echo "\nTest 4: Vastauksen käsittely\n";
$_SESSION['tb1_new_index'] = 0;
$_SESSION['tb1_new_questions'] = $blocks;
$_SESSION['tb1_new_counters'] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$_SESSION['tb1_new_answers'] = [];
$_POST['tb1_new_choice'] = 'B';

// Simuloi vastaus
ob_start();
try {
    handle_tb1_new_answer();
} catch (Exception $e) {
    // Ignore redirect
}
ob_end_clean();

if (($_SESSION['tb1_new_index'] ?? 0) === 1) {
    echo "✓ Index päivitetty: 0 -> 1\n";
} else {
    echo "✗ VIRHE: Index on " . ($_SESSION['tb1_new_index'] ?? 'NOT SET') . "\n";
}

if (($_SESSION['tb1_new_counters']['B'] ?? 0) === 1) {
    echo "✓ B-laskuri kasvatettu: 0 -> 1\n";
} else {
    echo "✗ VIRHE: B-laskuri on " . ($_SESSION['tb1_new_counters']['B'] ?? 'NOT SET') . "\n";
}

if (count($_SESSION['tb1_new_answers'] ?? []) === 1) {
    echo "✓ Vastaus tallennettu\n";
    echo "  Vastaus: " . ($_SESSION['tb1_new_answers'][0] ?? 'N/A') . "\n";
} else {
    echo "✗ VIRHE: Vastauksia " . count($_SESSION['tb1_new_answers'] ?? []) . "\n";
}

// Test 5: Usean vastauksen ketju
echo "\nTest 5: Usean vastauksen ketju (7 vastausta)\n";
$_SESSION['tb1_new_index'] = 0;
$_SESSION['tb1_new_questions'] = $blocks;
$_SESSION['tb1_new_counters'] = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$_SESSION['tb1_new_answers'] = [];
$_SESSION['state'] = 'tb1_new';

$test_answers = ['B', 'C', 'B', 'C', 'B', 'C', 'B'];
foreach ($test_answers as $i => $block) {
    $_POST['tb1_new_choice'] = $block;
    ob_start();
    try {
        handle_tb1_new_answer();
    } catch (Exception $e) {
        // Ignore redirect
    }
    ob_end_clean();
}

$expected_b = 4;
$expected_c = 3;
$actual_b = $_SESSION['tb1_new_counters']['B'] ?? 0;
$actual_c = $_SESSION['tb1_new_counters']['C'] ?? 0;

if ($actual_b === $expected_b && $actual_c === $expected_c) {
    echo "✓ Laskurit oikein: B=$actual_b, C=$actual_c\n";
} else {
    echo "✗ VIRHE: B=$actual_b (odotettu $expected_b), C=$actual_c (odotettu $expected_c)\n";
}

if (count($_SESSION['tb1_new_answers'] ?? []) === 7) {
    echo "✓ Kaikki 7 vastausta tallennettu\n";
} else {
    echo "✗ VIRHE: Vastauksia " . count($_SESSION['tb1_new_answers'] ?? []) . "\n";
}

// Test 6: Voittajan määritys
echo "\nTest 6: Voittajan määritys\n";
$_SESSION['points1'] = ['A1' => 10, 'B1' => 10, 'C1' => 8, 'D1' => 5];
$_SESSION['tb1_new_counters'] = ['A' => 2, 'B' => 5, 'C' => 0, 'D' => 0];
$_SESSION['tiebreak_classes'] = ['A1', 'B1'];
$_SESSION['state'] = 'tb1_new';

ob_start();
handle_tb1_new_completion();
ob_end_clean();

$winner = $_SESSION['log_row']['class1'] ?? 'NOT SET';
if ($winner === 'B1') {
    echo "✓ Voittaja määritetty oikein: B1 (B-laskuri korkein)\n";
} else {
    echo "✗ VIRHE: Voittaja on $winner, odotettu B1\n";
}

// Test 7: Tasapeli laskureissa
echo "\nTest 7: Tasapeli laskureissa\n";
$_SESSION['points1'] = ['A1' => 10, 'B1' => 10, 'C1' => 8];
$_SESSION['tb1_new_counters'] = ['A' => 3, 'B' => 3, 'C' => 1, 'D' => 0];
$_SESSION['tiebreak_classes'] = ['A1', 'B1'];
$_SESSION['state'] = 'tb1_new';

ob_start();
handle_tb1_new_completion();
ob_end_clean();

$winner = $_SESSION['log_row']['class1'] ?? 'NOT SET';
if (in_array($winner, ['A1', 'B1'])) {
    echo "✓ Voittaja valittu tasapelissä: $winner\n";
} else {
    echo "✗ VIRHE: Voittaja on $winner, odotettu A1 tai B1\n";
}

// Test 8: Takaisin-navigointi
echo "\nTest 8: Takaisin-navigointi\n";
$_SESSION['tb1_new_index'] = 3;
$_SESSION['tb1_new_questions'] = $blocks;
$_SESSION['tb1_new_counters'] = ['A' => 2, 'B' => 1, 'C' => 0, 'D' => 0];
$_SESSION['tb1_new_answers'] = ['1B', '2A', '3B'];
$_POST['tb1_new_navigate'] = 'back';

ob_start();
try {
    handle_tb1_new_navigation();
} catch (Exception $e) {
    // Ignore redirect
}
ob_end_clean();

if (($_SESSION['tb1_new_index'] ?? -1) === 2) {
    echo "✓ Index vähennetty: 3 -> 2\n";
} else {
    echo "✗ VIRHE: Index on " . ($_SESSION['tb1_new_index'] ?? 'NOT SET') . "\n";
}

if (count($_SESSION['tb1_new_answers'] ?? []) === 2) {
    echo "✓ Viimeinen vastaus poistettu\n";
} else {
    echo "✗ VIRHE: Vastauksia " . count($_SESSION['tb1_new_answers'] ?? []) . "\n";
}

if (($_SESSION['tb1_new_counters']['B'] ?? 0) === 1) {
    echo "✓ B-laskuri vähennetty: 2 -> 1\n";
} else {
    echo "✗ VIRHE: B-laskuri on " . ($_SESSION['tb1_new_counters']['B'] ?? 'NOT SET') . "\n";
}

// Test 9: Täydellinen tiebreak-prosessi
echo "\nTest 9: Täydellinen tiebreak-prosessi (7 vastausta + voittajan määritys)\n";
$_SESSION = [];
$_SESSION['points1'] = ['A1' => 10, 'B1' => 10, 'C1' => 8, 'D1' => 5];
$_SESSION['state'] = 'tb1_new';

// Alusta tiebreak BvsC parille
handle_quiz1_tie_new(['B1', 'C1']);

// Varmista että alustus onnistui
if (($_SESSION['state'] ?? '') !== 'tb1_new' || !isset($_SESSION['tb1_new_questions'])) {
    echo "✗ VIRHE: Tiebreak-alustus epäonnistui\n";
} else {
    echo "✓ Tiebreak alustettu (BvsC pari)\n";
    
    // Vastaa kaikkiin 7 kysymykseen
    // B voittaa 5-2
    $answers = ['B', 'C', 'B', 'C', 'B', 'B', 'B'];
    
    foreach ($answers as $i => $block) {
        $_POST['tb1_new_choice'] = $block;
        ob_start();
        try {
            handle_tb1_new_answer();
        } catch (Exception $e) {
            // Ignore redirect
        }
        ob_end_clean();
    }
    
    $final_b = $_SESSION['tb1_new_counters']['B'] ?? 0;
    $final_c = $_SESSION['tb1_new_counters']['C'] ?? 0;
    
    echo "  Lopulliset laskurit: B=$final_b, C=$final_c\n";
    
    if ($final_b === 5 && $final_c === 2) {
        echo "✓ Kaikki vastaukset tallennettu oikein\n";
    } else {
        echo "✗ VIRHE: Laskurit väärin (odotettu B=5, C=2)\n";
    }
    
    // Tarkista että kaikki 7 vastausta on tallennettu
    $answer_count = count($_SESSION['tb1_new_answers'] ?? []);
    if ($answer_count === 7) {
        echo "✓ Kaikki 7 vastausta tallennettu logia varten\n";
        echo "  Vastaukset: " . implode(', ', $_SESSION['tb1_new_answers']) . "\n";
    } else {
        echo "✗ VIRHE: Vastauksia $answer_count, odotettu 7\n";
    }
    
    // Määritä voittaja
    $winner = $_SESSION['log_row']['class1'] ?? 'NOT SET';
    
    if ($winner === 'B1') {
        echo "✓ VOITTAJA: B1 (5 pistettä vs C:n 2 pistettä)\n";
        echo "  Tila siirtyi: " . ($_SESSION['state'] ?? 'N/A') . "\n";
    } else {
        echo "✗ VIRHE: Voittaja on $winner, odotettu B1\n";
    }
}

echo "\n=== Kaikki testit suoritettu ===\n";
echo "</pre>";
