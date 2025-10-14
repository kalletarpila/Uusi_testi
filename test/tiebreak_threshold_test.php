<?php
// test/tiebreak_threshold_test.php
// Testaa tiebreak-kynnyksen logiikkaa

require_once __DIR__ . '/../index.php';

function test_tiebreak_threshold($points, $expected) {
    $_SESSION['points1'] = $points;
    $result = check_tiebreak_needed();
    echo "Test: points=" . json_encode($points) . " | expected=" . ($expected ? 'true' : 'false') . " | got=" . ($result ? 'true' : 'false') . "\n";
    return $result === $expected;
}

$tests = [
    // Ero 4 → tiebreak
    [['A1'=>10, 'B1'=>6, 'C1'=>2, 'D1'=>1], true],
    // Ero 5 → ei tiebreak
    [['A1'=>10, 'B1'=>5, 'C1'=>2, 'D1'=>1], false],
    // Ero 0 → tiebreak
    [['A1'=>8, 'B1'=>8, 'C1'=>2, 'D1'=>1], true],
    // Ero 2 → tiebreak
    [['A1'=>7, 'B1'=>5, 'C1'=>2, 'D1'=>1], true],
    // Ero 10 → ei tiebreak
    [['A1'=>15, 'B1'=>5, 'C1'=>2, 'D1'=>1], false],
];

$all_passed = true;
foreach ($tests as [$points, $expected]) {
    if (!test_tiebreak_threshold($points, $expected)) {
        $all_passed = false;
    }
}

if ($all_passed) {
    echo "Kaikki tiebreak-kynnys testit läpäisty!\n";
} else {
    echo "Jokin tiebreak-kynnys testi epäonnistui!\n";
}
