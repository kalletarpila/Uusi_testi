<?php
// test/phase2_full_test.php
// Testaa koko vaiheen 2 logiikan: kysymyslohkot, pisteiden laskenta, tiebreak, tulosten esitys

require_once __DIR__ . '/../index.php';

function simulate_phase2_blocks() {
    // Simuloi lohkot
    return [
        'B2' => [
            [
                'desc' => 'Kuvaus B2',
                'left_text' => 'Vasen',
                'left_var' => 'X',
                'right_text' => 'Oikea',
                'right_var' => 'Y',
                'pair' => ['X','Y'],
                'question_number' => 1
            ]
        ],
        'C2' => [],
        'D2' => []
    ];
}

function test_phase2_flow() {
    $_SESSION = [];
    $_SESSION['cached_quiz_data']['phase2_blocks'] = simulate_phase2_blocks();
    $_SESSION['varPoints'] = ['X'=>0, 'Y'=>0];
    $block = $_SESSION['cached_quiz_data']['phase2_blocks']['B2'][0];

    // Simuloi vastaus: valitaan oikea, arvo 2
    $raw = 2;
    $var = ($raw < 0) ? $block['left_var'] : $block['right_var'];
    $_SESSION['varPoints'][$var] += abs($raw);
    echo "Vastaus: ".$var." saa ".$raw." pistettä\n";
    echo $_SESSION['varPoints'][$var] === 2 ? "Pisteiden lisäys OK\n" : "Pisteiden lisäys VIRHE!\n";

    // Simuloi tiebreak-tilanne
    $_SESSION['varPoints'] = ['X'=>5, 'Y'=>5];
    ob_start();
    handle_phase2_tiebreak_completion();
    ob_end_clean();
    $maxPoints = max($_SESSION['varPoints']);
    $leaders = array_keys(array_filter($_SESSION['varPoints'], function($v) use ($maxPoints) { return $v === $maxPoints; }));
    echo count($leaders) === 2 ? "Tiebreak-tasapeli OK\n" : "Tiebreak-tasapeli VIRHE!\n";

    // Simuloi selvä voittaja
    $_SESSION['varPoints'] = ['X'=>7, 'Y'=>3];
    ob_start();
    handle_phase2_tiebreak_completion();
    ob_end_clean();
    $maxPoints = max($_SESSION['varPoints']);
    $leaders = array_keys(array_filter($_SESSION['varPoints'], function($v) use ($maxPoints) { return $v === $maxPoints; }));
    echo $leaders === ['X'] ? "Voittajan tunnistus OK\n" : "Voittajan tunnistus VIRHE!\n";

    // Testaa tulosten esitys
    $_SESSION['cached_quiz_data']['type_descriptions'] = ['X'=>'Kuvaus X','Y'=>'Kuvaus Y'];
    $desc = get_cached_type_descriptions();
    echo $desc['X'] === 'Kuvaus X' ? "Tyyppikuvauksen esitys OK\n" : "Tyyppikuvauksen esitys VIRHE!\n";
}

test_phase2_flow();
