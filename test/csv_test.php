<?php
// test/csv_test.php
// Testaa CSV-datan luku ja virhetilanne

require_once __DIR__ . '/../index.php';

function test_csv_load($base) {
    $rows = read_tag_text_rows($base);
    echo "CSV rows: ".count($rows)."\n";
    return is_array($rows);
}

// Oletetaan, että SeparateBlocksQuestions.csv on olemassa
$ok = test_csv_load('SeparateBlocksQuestions');
echo $ok ? "CSV-luku OK\n" : "CSV-luku VIRHE!\n";

// Testaa virhetilanne
$ok2 = test_csv_load('puuttuva_tiedosto');
echo !$ok2 ? "Virhetilanne OK\n" : "Virhetilanne VIRHE!\n";
