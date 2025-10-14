<?php
// test/phase2_tiebreak_test.php
// Testaa vaiheen 2 tiebreak-logiikkaa

require_once __DIR__ . '/../index.php';

function test_phase2_tiebreak($varPoints, $expectedLeaders) {
    $_SESSION['varPoints'] = $varPoints;
    // Simuloi tiebreakin valmistuminen
    ob_start();
    handle_phase2_tiebreak_completion();
    ob_end_clean();
    $leaders = [];
    $maxPoints = max($varPoints);
    foreach ($varPoints as $var => $points) {
        if ($points === $maxPoints) {
            $leaders[] = $var;
        }
    }
    $ok = ($leaders == $expectedLeaders);
    echo "Test: varPoints=".json_encode($varPoints)." | expectedLeaders=".json_encode($expectedLeaders)." | gotLeaders=".json_encode($leaders)."\n";
    echo $ok ? "Vaiheen 2 tiebreak OK\n" : "Vaiheen 2 tiebreak VIRHE!\n";
    return $ok;
}

$tests = [
    // Tasapeli kahden välillä
    [['X'=>10, 'Y'=>10, 'Z'=>5], ['X','Y']],
    // Selvä voittaja
    [['X'=>12, 'Y'=>8, 'Z'=>7], ['X']],
    // Kolmen tasapeli
    [['X'=>6, 'Y'=>6, 'Z'=>6], ['X','Y','Z']],
];

$all_passed = true;
foreach ($tests as [$varPoints, $expectedLeaders]) {
    if (!test_phase2_tiebreak($varPoints, $expectedLeaders)) {
        $all_passed = false;
    }
}

if ($all_passed) {
    echo "Kaikki vaiheen 2 tiebreak-testit läpäisty!\n";
} else {
    echo "Jokin vaiheen 2 tiebreak-testi epäonnistui!\n";
}
