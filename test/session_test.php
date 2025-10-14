<?php
// test/session_test.php
// Testaa sessionin alustus ja evästeasetukset

session_start();
$params = session_get_cookie_params();
$ok = isset($params['httponly']) && $params['httponly'] && isset($params['samesite']) && $params['samesite'] === 'Lax';
echo "Session cookie params: ".json_encode($params)."\n";
echo $ok ? "Sessionin evästeasetukset OK\n" : "Sessionin evästeasetukset VIRHE!\n";
