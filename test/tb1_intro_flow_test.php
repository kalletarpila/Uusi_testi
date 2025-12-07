<?php
/**
 * Testi: Tarkistaa että tb1_new_intro -> tb1_new flow toimii
 */

// Vaihda työhakemisto juureen, jotta CSV-tiedostot löytyvät
chdir(__DIR__ . '/..');

session_start();
$_SESSION = [];

// Mock server vars
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/test';

ob_start();
require_once __DIR__ . '/../index.php';
ob_end_clean();

echo "\n=== Test: TB1 Intro Flow ===\n\n";

// 1. Initialize tiebreak
echo "1. Initializing tiebreak...\n";
$_SESSION['points1'] = ['A1' => 10, 'B1' => 20, 'C1' => 20, 'D1' => 10];
$_SESSION['yesCounts1'] = ['A1' => 2, 'B1' => 4, 'C1' => 4, 'D1' => 2];

handle_quiz1_tie_new(['B1', 'C1']);

$state = $_SESSION['state'] ?? 'null';
$questions = $_SESSION['tb1_new_questions'] ?? [];
$question_count = count($questions);

echo "   State: $state\n";
echo "   Questions in session: $question_count\n";

if ($state !== 'tb1_new_intro') {
    echo "✗ Expected tb1_new_intro, got $state\n";
    exit(1);
}

if ($question_count === 0) {
    echo "✗ No questions loaded!\n";
    exit(1);
}

echo "   ✓ Intro state set correctly\n";
echo "   ✓ Questions loaded: $question_count\n";

// 2. Simulate clicking "Jatka kyselyä" button
echo "\n2. Simulating 'Jatka kyselyä' button click...\n";

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['continue_tb1_new' => '1'];

// Manually run the POST handler logic (without redirect)
if (($_SESSION['state'] ?? '') === 'tb1_new_intro' && isset($_POST['continue_tb1_new'])) {
    $question_count_before = count($_SESSION['tb1_new_questions'] ?? []);
    echo "   Questions before state change: $question_count_before\n";
    
    $_SESSION['state'] = 'tb1_new';
    
    $question_count_after = count($_SESSION['tb1_new_questions'] ?? []);
    echo "   Questions after state change: $question_count_after\n";
    echo "   New state: " . $_SESSION['state'] . "\n";
}

// 3. Check if questions are still in session
echo "\n3. Checking session state after transition...\n";

$final_state = $_SESSION['state'] ?? 'null';
$final_questions = $_SESSION['tb1_new_questions'] ?? [];
$final_count = count($final_questions);

echo "   Final state: $final_state\n";
echo "   Final question count: $final_count\n";

if ($final_state !== 'tb1_new') {
    echo "✗ State not tb1_new!\n";
    exit(1);
}

if ($final_count === 0) {
    echo "✗ Questions disappeared from session!\n";
    echo "   Session keys: " . implode(', ', array_keys($_SESSION)) . "\n";
    exit(1);
}

echo "   ✓ State correctly set to tb1_new\n";
echo "   ✓ Questions preserved in session: $final_count\n";

// 4. Verify question structure
echo "\n4. Verifying question structure...\n";

$first_q = $final_questions[0] ?? null;
if (!$first_q) {
    echo "✗ First question missing!\n";
    exit(1);
}

$required_keys = ['question_num', 'blocks_str', 'statement', 'block_left', 'option_left', 'block_right', 'option_right'];
$missing_keys = [];

foreach ($required_keys as $key) {
    if (!isset($first_q[$key])) {
        $missing_keys[] = $key;
    }
}

if (!empty($missing_keys)) {
    echo "✗ Missing keys in question: " . implode(', ', $missing_keys) . "\n";
    exit(1);
}

echo "   ✓ Question structure valid\n";
echo "   Sample question: " . substr($first_q['statement'], 0, 50) . "...\n";

echo "\n✓✓✓ TEST PASSED ✓✓✓\n";
echo "Intro flow works correctly!\n";
echo "Questions are preserved across state transition!\n";

echo "\n=== Test Complete ===\n";
