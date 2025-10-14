<?php
// test/run_all_tests.php
// Ajaa kaikki testit ja näyttää tulokset yhdellä sivulla

echo "<h1>Enneagrammitestin automaattiset testit</h1>";
$tests = [
    'tiebreak_threshold_test.php',
    'session_test.php',
    'csv_test.php',
    'invite_test.php',
    'points_test.php',
    'type_description_test.php',
    'tiebreak_answer_test.php',
    'error_handling_test.php',
    'phase2_tiebreak_test.php',
    'phase2_full_test.php'
];

foreach ($tests as $test) {
    echo "<h2>$test</h2>";
    echo "<pre>";
    // Suorita testitiedosto ja näytä tulos
    $output = @shell_exec("php test/$test");
    if ($output === null) {
        // Fallback: include tiedosto suoraan
        ob_start();
        include __DIR__ . "/$test";
        $output = ob_get_clean();
    }
    echo htmlspecialchars($output);
    echo "</pre>";
}
