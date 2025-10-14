<?php
// test/invite_test.php
// Testaa kutsujärjestelmän toiminnot

require_once __DIR__ . '/../index.php';

$items = [
    ['id'=>'1','token'=>'abc','code'=>'XYZ','status'=>'sent'],
    ['id'=>'2','token'=>'def','code'=>'QWE','status'=>'accepted']
];

invites_save($items);
$loaded = invites_load();
echo count($loaded) === 2 ? "Kutsujen tallennus/luku OK\n" : "Kutsujen tallennus/luku VIRHE!\n";

$found = invite_find_by_token($loaded, 'abc');
echo $found ? "Token-haku OK\n" : "Token-haku VIRHE!\n";

$found2 = invite_find_by_code($loaded, 'qwe');
echo $found2 ? "Code-haku OK\n" : "Code-haku VIRHE!\n";

invite_update_status('1', 'completed');
$loaded2 = invites_load();
echo $loaded2[0]['status'] === 'completed' ? "Statuksen päivitys OK\n" : "Statuksen päivitys VIRHE!\n";
