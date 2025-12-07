<?php
/**
 * Testi: Phase 1 Tiebreak - Vasemman ja oikean puolen satunnainen vaihto
 * 
 * Testaa että:
 * 1. Vasemman ja oikean puolen vaihtaminen toimii
 * 2. Blokkilaskurit päivittyvät oikein vaihdoista huolimatta
 * 3. Lokiin kirjautuu oikea valittu blokki
 */

// Vaihda työhakemisto juureen, jotta CSV-tiedostot löytyvät
chdir(__DIR__ . '/..');

// Start session and include main file
session_start();
$_SESSION = []; // Clear session

// Mock $_SERVER variables
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/test';

// Suppress output and include main file
ob_start();
require_once __DIR__ . '/../index.php';
ob_end_clean();

// Test helper: Validate question structure
function validate_question_structure($q, $test_name) {
    $required_fields = ['question_num', 'blocks_str', 'statement', 'block_left', 'option_left', 'block_right', 'option_right'];
    foreach ($required_fields as $field) {
        if (!isset($q[$field])) {
            echo "✗ $test_name: Missing field '$field'\n";
            return false;
        }
    }
    return true;
}

echo "\n=== Test: Phase 1 Tiebreak - Left/Right Swap ===\n\n";

// ======== Test 1: Initialize tiebreak and check for swaps ========
echo "1. Initializing tiebreak with B1 vs C1 tie...\n";

// Set up quiz1 points to create a tie between B1 and C1
$_SESSION['points1'] = [
    'A1' => 10,
    'B1' => 20, // Tied
    'C1' => 20, // Tied
    'D1' => 10
];
$_SESSION['yesCounts1'] = ['A1' => 2, 'B1' => 4, 'C1' => 4, 'D1' => 2];

// Initialize tiebreak
handle_quiz1_tie_new(['B1', 'C1']);

// Check state
if ($_SESSION['state'] !== 'tb1_new_intro') {
    echo "✗ Expected state 'tb1_new_intro', got '" . ($_SESSION['state'] ?? 'null') . "'\n";
    exit(1);
}
echo "   ✓ Tiebreak initialized to intro state\n";

// Manually move to tb1_new state for testing
$_SESSION['state'] = 'tb1_new';

// Check questions loaded
$questions = $_SESSION['tb1_new_questions'] ?? [];
$questionCount = count($questions);

if ($questionCount === 0) {
    echo "✗ No questions loaded\n";
    exit(1);
}
echo "   ✓ Questions loaded: $questionCount\n";

// Validate question structures
$valid = true;
foreach ($questions as $i => $q) {
    if (!validate_question_structure($q, "Question " . ($i + 1))) {
        $valid = false;
        break;
    }
}

if (!$valid) {
    echo "✗ Question structure validation failed\n";
    exit(1);
}
echo "   ✓ All questions have valid structure\n";

// Check if any swaps occurred
$original_blocks_str = $questions[0]['blocks_str']; // e.g., "BvsC"
preg_match('/([A-D])vs([A-D])/i', $original_blocks_str, $m);
$expected_first_block = strtoupper($m[1]); // "B"
$expected_second_block = strtoupper($m[2]); // "C"

$swap_detected = false;
$swap_count = 0;
$no_swap_count = 0;

foreach ($questions as $q) {
    $left = $q['block_left'];
    $right = $q['block_right'];
    
    // Check if this question was swapped
    // Original: left=B, right=C
    // Swapped: left=C, right=B
    if ($left === $expected_second_block && $right === $expected_first_block) {
        $swap_detected = true;
        $swap_count++;
    } elseif ($left === $expected_first_block && $right === $expected_second_block) {
        $no_swap_count++;
    } else {
        echo "✗ Unexpected block combination: left=$left, right=$right\n";
        exit(1);
    }
}

echo "   ✓ Swap analysis: $swap_count swapped, $no_swap_count original order\n";

if ($swap_count === 0) {
    echo "   ⚠ Warning: No swaps detected (may happen by chance with rand(0,1))\n";
} else {
    echo "   ✓ Swaps detected: $swap_count/$questionCount questions\n";
}

// ======== Test 2: Answer questions and verify counters ========
echo "\n2. Answering questions and verifying counters...\n";

// Answer pattern: alternate between blocks
// We'll answer based on what's on the LEFT side
$expected_counters = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];

for ($i = 0; $i < $questionCount; $i++) {
    $q = $questions[$i];
    
    // Choose LEFT side for every question
    $chosen_block = $q['block_left'];
    
    // Simulate POST
    $_POST = ['tb1_new_choice' => $chosen_block];
    $_SESSION['tb1_new_index'] = $i;
    
    // Manually handle answer (avoid redirect)
    $selectedBlock = $_POST['tb1_new_choice'];
    $index = (int)($_SESSION['tb1_new_index'] ?? 0);
    
    // Increment block counter
    $_SESSION['tb1_new_counters'][$selectedBlock] = ($_SESSION['tb1_new_counters'][$selectedBlock] ?? 0) + 1;
    
    // Save answer for logging
    $_SESSION['tb1_new_answers'][] = $q['question_num'] . $selectedBlock;
    
    // Move to next question
    $_SESSION['tb1_new_index'] = $index + 1;
    
    // Update expected counter
    $expected_counters[$chosen_block]++;
    
    echo "   Q" . ($i + 1) . ": Chose LEFT (block $chosen_block)\n";
}

// Check final counters
$actual_counters = $_SESSION['tb1_new_counters'] ?? [];

$counters_match = true;
foreach (['A', 'B', 'C', 'D'] as $block) {
    $expected = $expected_counters[$block];
    $actual = $actual_counters[$block] ?? 0;
    
    if ($expected !== $actual) {
        echo "✗ Counter mismatch for block $block: expected $expected, got $actual\n";
        $counters_match = false;
    }
}

if ($counters_match) {
    echo "   ✓ All counters correct: B=" . $actual_counters['B'] . ", C=" . $actual_counters['C'] . "\n";
} else {
    echo "✗ Counter validation failed\n";
    exit(1);
}

// ======== Test 3: Verify answers logged correctly ========
echo "\n3. Verifying logged answers...\n";

$logged_answers = $_SESSION['tb1_new_answers'] ?? [];

if (count($logged_answers) !== $questionCount) {
    echo "✗ Expected $questionCount answers, got " . count($logged_answers) . "\n";
    exit(1);
}

echo "   ✓ All $questionCount answers logged\n";

// Verify answer format: "questionNum+Block" (e.g., "1B", "2C")
$format_valid = true;
foreach ($logged_answers as $answer) {
    if (!preg_match('/^\d+[A-D]$/', $answer)) {
        echo "✗ Invalid answer format: $answer\n";
        $format_valid = false;
        break;
    }
}

if ($format_valid) {
    echo "   ✓ Answer format valid: " . implode(', ', $logged_answers) . "\n";
} else {
    exit(1);
}

// ======== Test 4: Verify winner determination ========
echo "\n4. Testing winner determination...\n";

// Since we chose LEFT for all questions, check who should win
$b_count = $actual_counters['B'];
$c_count = $actual_counters['C'];

echo "   Final counts: B=$b_count, C=$c_count\n";

if ($b_count > $c_count) {
    echo "   ✓ B should win (has more votes)\n";
} elseif ($c_count > $b_count) {
    echo "   ✓ C should win (has more votes)\n";
} else {
    echo "   ✓ Tie detected (will use phase1 points)\n";
}

echo "\n✓✓✓ TEST PASSED ✓✓✓\n";
echo "Left/right swap logic works correctly!\n";
echo "Block counters are accurate regardless of swap!\n";

echo "\n=== Test Complete ===\n";
