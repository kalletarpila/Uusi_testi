<?php
// test/error_handling_test.php
// Testaa virheenkäsittely ja lokitus

require_once __DIR__ . '/../index.php';

function test_error_log() {
    handle_error('TEST', 'Testivirhe', null, false);
    $logfile = index_app_log_path();
    $exists = file_exists($logfile);
    echo $exists ? "Virheloki OK\n" : "Virheloki VIRHE!\n";
}
test_error_log();
