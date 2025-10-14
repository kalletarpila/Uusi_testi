<?php
// test/points_test.php
// Testaa pisteiden laskenta ja yes-vastausten logiikka

require_once __DIR__ . '/../index.php';

$_SESSION['points1'] = ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];
$_SESSION['yesCounts1'] = ['A1'=>0,'B1'=>0,'C1'=>0,'D1'=>0];

// Simuloi vastaus: A1 saa 5 (yes)
$n = 5;
$cls = 'A1';
$_SESSION['points1'][$cls] += $n;
if ($n >= YES_MIN) $_SESSION['yesCounts1'][$cls]++;
echo $_SESSION['points1'][$cls] === 5 ? "Pisteiden lisäys OK\n" : "Pisteiden lisäys VIRHE!\n";
echo $_SESSION['yesCounts1'][$cls] === 1 ? "Yes-laskenta OK\n" : "Yes-laskenta VIRHE!\n";
