<?php
// test/type_description_test.php
// Testaa tyyppikuvauksen haku

require_once __DIR__ . '/../index.php';

$types = ['A1'=>'Tyyppi A','B1'=>'Tyyppi B'];
$_SESSION['cached_quiz_data']['type_descriptions'] = $types;
$res = get_cached_type_descriptions();
echo $res['A1'] === 'Tyyppi A' ? "Tyyppikuvauksen haku OK\n" : "Tyyppikuvauksen haku VIRHE!\n";
