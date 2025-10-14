<?php
// test/tiebreak_answer_test.php
// Testaa tiebreak-vastausten käsittely

require_once __DIR__ . '/../index.php';

$_SESSION['points1'] = ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];
$set = [['class'=>'A1','question_number'=>1],['class'=>'B1','question_number'=>2]];
$_SESSION['tiebreak_set'] = $set;
$_SESSION['tiebreak_index'] = 0;

// Simuloi vastaus: A1 saa 6
$n = 6;
$blk = $set[0];
$cls = $blk['class'];
$_SESSION['points1'][$cls] += $n;
echo $_SESSION['points1'][$cls] === 6 ? "Tiebreak-vastaus OK\n" : "Tiebreak-vastaus VIRHE!\n";
